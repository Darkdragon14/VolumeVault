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
     * character trie of the paths under the mount (the mount base is emitted as a
     * flat literal prefix) — no regex negation is required, which makes the result
     * fully deterministic and testable.
     *
     * @param  string  $mountBase  absolute mount base, e.g. "/backup/<mount>"
     * @param  array<int, string>  $relativePaths  paths relative to the volume root
     * @return string|null the exclude regexp, or null when nothing should be filtered
     */
    public function build(string $mountBase, array $relativePaths): ?string
    {
        $base = '/'.trim($mountBase, '/');

        $relativeTargets = [];

        foreach ($relativePaths as $path) {
            $relative = preg_replace('#/+#', '/', trim((string) $path)) ?? '';
            $relative = trim($relative, '/');

            if ($relative === '') {
                continue;
            }

            // Keyed by the path relative to the mount (with its leading slash). A
            // nested target already covered by an ancestor stays harmless because
            // the trie prunes at the ancestor boundary.
            $relativeTargets['/'.$relative] = true;
        }

        if ($relativeTargets === []) {
            return null;
        }

        $trie = ['children' => [], 'terminal' => false];

        foreach (array_keys($relativeTargets) as $target) {
            $this->insert($trie, $this->characters($target));
        }

        // A backup job mounts a single source under /backup, so no sibling of the
        // mount base ever exists on disk. Emit the base as a flat literal (no
        // per-character divergence) and only nest the pattern for the paths under
        // it, so the regexp's nesting depth is bounded by the relative path length
        // rather than the whole absolute path (which also carries the volume name).
        return '^'.$this->escapeLiteral($base).$this->pattern($trie);
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
     * Emit the exclude pattern for a trie node as a nested alternation. Nesting —
     * rather than one flat branch per node, each carrying its full prefix — keeps
     * the generated regexp linear in the number of characters instead of O(n^2),
     * so a long include list cannot inflate BACKUP_EXCLUDE_REGEXP to a size that
     * would break the backup container's environment.
     *
     * @param  array{children: array<string, mixed>, terminal: bool}  $node
     */
    private function pattern(array $node): string
    {
        $childChars = array_keys($node['children']);

        if ($node['terminal']) {
            // Everything at or under this target is kept: the entry itself (end of
            // string), anything below "/", and any sibling target that shares this
            // exact prefix (a child char, e.g. "Backups" vs "Backups2"). Match — and
            // therefore exclude — any other continuation.
            $excluded = $childChars;
            if (! in_array('/', $excluded, true)) {
                $excluded[] = '/';
            }

            $alternatives = ['[^'.$this->escapeClass($excluded).']'];
        } else {
            // A path diverges here if the next char is not one of the known
            // children, or if it ends here (an ancestor above every target).
            $alternatives = ['[^'.$this->escapeClass($childChars).']', '$'];
        }

        foreach ($node['children'] as $char => $child) {
            // A terminal node's "/" child is a fully kept subtree — do not descend.
            if ($node['terminal'] && $char === '/') {
                continue;
            }

            $alternatives[] = $this->escapeLiteral($char).$this->pattern($child);
        }

        return '(?:'.implode('|', $alternatives).')';
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
