<?php

namespace Tests\Unit;

use App\Services\Backup\IncludePathsToExcludeRegexp;
use Tests\TestCase;

class IncludePathsToExcludeRegexpTest extends TestCase
{
    private IncludePathsToExcludeRegexp $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new IncludePathsToExcludeRegexp;
    }

    /**
     * Mimic offen's filter: an absolute path is dropped from the archive when the
     * generated regexp matches it (unanchored, like Go's regexp.MatchString).
     */
    private function isKept(?string $regexp, string $absolutePath): bool
    {
        $this->assertNotNull($regexp);

        return preg_match('#'.$regexp.'#', $absolutePath) !== 1;
    }

    public function test_empty_list_produces_no_filter(): void
    {
        $this->assertNull($this->builder->build('/backup/vol', []));
        $this->assertNull($this->builder->build('/backup/vol', ['', '  ', '/']));
    }

    public function test_single_folder_keeps_only_that_subtree(): void
    {
        $regexp = $this->builder->build('/backup/vol', ['Backups']);

        // Kept: the folder itself and anything under it.
        $this->assertTrue($this->isKept($regexp, '/backup/vol/Backups'));
        $this->assertTrue($this->isKept($regexp, '/backup/vol/Backups/file.tar'));
        $this->assertTrue($this->isKept($regexp, '/backup/vol/Backups/nested/deep.log'));

        // Excluded: everything else.
        $this->assertFalse($this->isKept($regexp, '/backup/vol/config/app.conf'));
        $this->assertFalse($this->isKept($regexp, '/backup/vol/other'));
        $this->assertFalse($this->isKept($regexp, '/backup/vol'));
    }

    public function test_sibling_with_shared_prefix_is_not_kept(): void
    {
        $regexp = $this->builder->build('/backup/vol', ['Backups']);

        // "Backups2" shares the literal prefix but is a different folder.
        $this->assertFalse($this->isKept($regexp, '/backup/vol/Backups2'));
        $this->assertFalse($this->isKept($regexp, '/backup/vol/Backups2/x'));
        $this->assertFalse($this->isKept($regexp, '/backup/vol/Backupsz'));
    }

    public function test_two_sibling_targets_sharing_a_prefix_are_both_kept(): void
    {
        $regexp = $this->builder->build('/backup/vol', ['Backups', 'Backups2']);

        $this->assertTrue($this->isKept($regexp, '/backup/vol/Backups/a'));
        $this->assertTrue($this->isKept($regexp, '/backup/vol/Backups2/b'));
        $this->assertTrue($this->isKept($regexp, '/backup/vol/Backups'));
        $this->assertTrue($this->isKept($regexp, '/backup/vol/Backups2'));

        $this->assertFalse($this->isKept($regexp, '/backup/vol/Backups3'));
        $this->assertFalse($this->isKept($regexp, '/backup/vol/other'));
    }

    public function test_multiple_distinct_folders(): void
    {
        $regexp = $this->builder->build('/backup/vol', ['Backups', 'config/backups']);

        $this->assertTrue($this->isKept($regexp, '/backup/vol/Backups/a'));
        $this->assertTrue($this->isKept($regexp, '/backup/vol/config/backups/b.sql'));

        // Under config but not the backups subfolder → excluded.
        $this->assertFalse($this->isKept($regexp, '/backup/vol/config/app.conf'));
        $this->assertFalse($this->isKept($regexp, '/backup/vol/config'));
        $this->assertFalse($this->isKept($regexp, '/backup/vol/logs/x.log'));
    }

    public function test_single_file_target_keeps_only_that_file(): void
    {
        $regexp = $this->builder->build('/backup/vol', ['config/app.conf']);

        $this->assertTrue($this->isKept($regexp, '/backup/vol/config/app.conf'));

        $this->assertFalse($this->isKept($regexp, '/backup/vol/config/app.conf.bak'));
        $this->assertFalse($this->isKept($regexp, '/backup/vol/config/other.conf'));
    }

    public function test_paths_are_normalised(): void
    {
        $regexp = $this->builder->build('/backup/vol/', ['/Backups/', 'config//backups']);

        $this->assertTrue($this->isKept($regexp, '/backup/vol/Backups/a'));
        $this->assertTrue($this->isKept($regexp, '/backup/vol/config/backups/b'));
        $this->assertFalse($this->isKept($regexp, '/backup/vol/elsewhere'));
    }

    public function test_special_regex_characters_in_paths_are_escaped(): void
    {
        $regexp = $this->builder->build('/backup/vol', ['data (v1)', 'a.b']);

        $this->assertTrue($this->isKept($regexp, '/backup/vol/data (v1)/x'));
        $this->assertTrue($this->isKept($regexp, '/backup/vol/a.b/y'));

        // The "." must be a literal, not "any char": "aXb" must not be kept.
        $this->assertFalse($this->isKept($regexp, '/backup/vol/aXb'));
        $this->assertFalse($this->isKept($regexp, '/backup/vol/data'));
    }
}
