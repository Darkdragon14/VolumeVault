<?php

namespace App\Services\Backup;

class IncludePathsToExcludeRegexp
{
    /**
     * Build the BACKUP_EXCLUDE_REGEXP that offen must use so that ONLY the given
     * paths (folders or files, relative to the volume root) end up in the archive.
     *
     * offen matches BACKUP_EXCLUDE_REGEXP with Go's regexp.MatchString (unanchored)
     * against the ABSOLUTE path of every walked entry (e.g. /backup/<mount>/foo/bar)
     * and drops the entry when it matches. offen has no "include" option and Go RE2
     * has no negative lookahead, so to keep only the wanted paths we generate the
     * *complement*: a regexp matching every path that is neither one of the targets
     * nor located under one of them.
     *
     * The targets are literal paths (not regexes), so the complement is built from a
     * character trie of the absolute targets — no regex negation is required, which
     * makes the result fully deterministic and testable.
     *
     * @param  string  $mountBase  absolute mount base, e.g. "/backup/<mount>"
     * @param  array<int, string>  $relativePaths  paths relative to the volume root
     * @return string|null the exclude regexp, or null when nothing should be filtered
     */
    public function build(string $mountBase, array $relativePaths): ?string
    {
        $base = '/'.trim($mountBase, '/');

        $targets = [];

        foreach ($relativePaths as $path) {
            $relative = preg_replace('#/+#', '/', trim((string) $path)) ?? '';
            $relative = trim($relative, '/');

            if ($relative === '') {
                continue;
            }

            // De-duplicate; a nested target that is already covered by an ancestor
            // stays harmless because the trie prunes at the ancestor boundary.
            $targets[$base.'/'.$relative] = true;
        }

        if ($targets === []) {
            return null;
        }

        $trie = ['children' => [], 'terminal' => false];

        foreach (array_keys($targets) as $target) {
            $this->insert($trie, $this->characters($target));
        }

        $branches = [];
        $this->collect($trie, '', $branches);

        return '^(?:'.implode('|', $branches).')';
    }

    /**
     * Split into UTF-8 characters, not bytes: the trie nodes and the negated
     * character classes must key on whole runes so Go's regexp engine (which
     * matches runes) interprets the generated pattern the same way as PHP.
     *
     * @return array<int, string>
     */
    private function characters(string $value): array
    {
        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param  array{children: array<string, mixed>, terminal: bool}  $trie
     * @param  array<int, string>  $characters
     */
    private function insert(array &$trie, array $characters): void
    {
        $node = &$trie;

        foreach ($characters as $char) {
            if (! isset($node['children'][$char])) {
                $node['children'][$char] = ['children' => [], 'terminal' => false];
            }

            $node = &$node['children'][$char];
        }

        $node['terminal'] = true;
        unset($node);
    }

    /**
     * Walk the trie and emit, for every node, the branch matching the paths that
     * "fall off" the set of kept targets at that node.
     *
     * @param  array{children: array<string, mixed>, terminal: bool}  $node
     * @param  array<int, string>  $branches
     */
    private function collect(array $node, string $prefix, array &$branches): void
    {
        $childChars = array_keys($node['children']);
        $literal = $this->escapeLiteral($prefix);

        if ($node['terminal']) {
            // Everything at or under this target is kept: the entry itself (end of
            // string), anything below "/", and any sibling target that shares this
            // exact prefix (a child char, e.g. "Backups" vs "Backups2"). Exclude a
            // continuation that is none of those.
            $allowed = $childChars;
            if (! in_array('/', $allowed, true)) {
                $allowed[] = '/';
            }

            $branches[] = $literal.'[^'.$this->escapeClass($allowed).']';

            foreach ($node['children'] as $char => $child) {
                // The whole "/" subtree is kept; only descend sibling-prefix targets.
                if ($char === '/') {
                    continue;
                }

                $this->collect($child, $prefix.$char, $branches);
            }

            return;
        }

        // Non-terminal: a path diverges here if the next char is not one of the known
        // children, or if the path ends here (an ancestor above every target).
        $branches[] = $literal.'(?:[^'.$this->escapeClass($childChars).']|$)';

        foreach ($node['children'] as $char => $child) {
            $this->collect($child, $prefix.$char, $branches);
        }
    }

    private function escapeLiteral(string $value): string
    {
        // preg_quote only backslash-escapes ASCII punctuation, all of which Go RE2
        // also accepts as escaped literals, so the output is valid for both engines.
        return preg_quote($value);
    }

    /**
     * @param  array<int, string>  $chars
     */
    private function escapeClass(array $chars): string
    {
        return implode('', array_map(function (string $char): string {
            return in_array($char, ['\\', ']', '^', '-', '/'], true) ? '\\'.$char : $char;
        }, $chars));
    }
}
