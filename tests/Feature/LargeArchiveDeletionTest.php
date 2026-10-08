<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LargeArchiveDeletionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/volumevault-large-delete-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->directory.'/nested/first');
        File::ensureDirectoryExists($this->directory.'/nested/second');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    #[DataProvider('archiveParents')]
    public function test_large_real_selection_shares_parent_descriptors_under_a_low_limit(bool $nested): void
    {
        $keys = $this->createArchives($nested);
        File::put($this->directory.'/keep.tar.gz', 'keep');
        $process = $this->deleteProcess($keys);

        $this->assertSame(0, $process->run(), $process->getErrorOutput());
        foreach ($keys as $key) {
            $this->assertFileDoesNotExist($this->directory.'/'.$key);
        }
        $this->assertSame('keep', File::get($this->directory.'/keep.tar.gz'));
        $this->assertDirectoryExists($this->directory.'/nested/first');
        $this->assertDirectoryExists($this->directory.'/nested/second');
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_final_selection_prevents_the_entire_large_batch_from_being_deleted(string $invalid): void
    {
        $keys = $this->createArchives(true);
        File::put($this->directory.'/outside.tar.gz', 'outside');
        symlink($this->directory.'/outside.tar.gz', $this->directory.'/linked.tar.gz');
        symlink($this->directory, $this->directory.'/linked-parent');
        $process = $this->deleteProcess([...$keys, $invalid]);

        $this->assertNotSame(0, $process->run());
        foreach ($keys as $key) {
            $this->assertFileExists($this->directory.'/'.$key);
        }
        $this->assertSame('outside', File::get($this->directory.'/outside.tar.gz'));
    }

    public function test_native_deletion_treats_glob_characters_as_literal_names(): void
    {
        $key = 'backup[*?].tar.gz';
        File::put($this->directory.'/'.$key, 'selected');
        File::put($this->directory.'/backup1.tar.gz', 'keep');
        $process = $this->deleteProcess([$key]);

        $this->assertSame(0, $process->run(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/'.$key);
        $this->assertSame('keep', File::get($this->directory.'/backup1.tar.gz'));
    }

    public static function archiveParents(): array
    {
        return ['root' => [false], 'shared nested parents' => [true]];
    }

    public function test_reused_parent_descriptor_rejects_a_parent_swapped_during_validation(): void
    {
        if ((new Process(['sh', '-c', 'command -v cc']))->run() !== 0) {
            $this->markTestSkipped('A C compiler is required for the deterministic parent-swap test.');
        }
        $parent = $this->directory.'/nested/first';
        $moved = $this->directory.'/pinned';
        $outside = $this->directory.'/outside';
        File::ensureDirectoryExists($outside);
        foreach (['old.tar.gz', 'other.tar.gz'] as $name) {
            File::put($parent.'/'.$name, 'archive');
            File::put($outside.'/'.$name, 'outside');
        }
        $source = $this->directory.'/swap.c';
        File::put($source, <<<'C'
#define _GNU_SOURCE
#include <dlfcn.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>
int fstatat(int directory, const char *name, struct stat *identity, int flags) {
    int (*original)(int, const char *, struct stat *, int) = dlsym(RTLD_NEXT, "fstatat");
    static int swapped = 0;
    if (!swapped && strcmp(name, "old.tar.gz") == 0) {
        swapped = 1;
        if (rename(getenv("VV_SWAP_PARENT"), getenv("VV_MOVED_PARENT")) != 0
            || symlink(getenv("VV_OUTSIDE"), getenv("VV_SWAP_PARENT")) != 0) {
            return -1;
        }
    }
    return original(directory, name, identity, flags);
}
C);
        $library = $this->directory.'/swap.so';
        (new Process(['cc', '-shared', '-fPIC', '-Wall', '-Wextra', '-Werror', $source, '-o', $library, '-ldl']))->mustRun();
        $process = $this->deleteProcess(['nested/first/old.tar.gz', 'nested/first/other.tar.gz']);
        $process->setEnv([
            'LD_PRELOAD' => $library, 'VV_SWAP_PARENT' => $parent,
            'VV_MOVED_PARENT' => $moved, 'VV_OUTSIDE' => $outside,
        ]);

        $this->assertNotSame(0, $process->run());
        $this->assertDirectoryExists($moved);
        foreach (['old.tar.gz', 'other.tar.gz'] as $name) {
            $this->assertSame('archive', File::get($moved.'/'.$name));
            $this->assertSame('outside', File::get($outside.'/'.$name));
        }
    }

    public static function invalidSelections(): array
    {
        return [
            'missing' => ['missing.tar.gz'],
            'traversal' => ['nested/../outside.tar.gz'],
            'file symlink' => ['linked.tar.gz'],
            'parent symlink' => ['linked-parent/outside.tar.gz'],
        ];
    }

    /** @return list<string> */
    private function createArchives(bool $nested): array
    {
        $keys = [];
        for ($index = 0; $index < 1200; $index++) {
            $prefix = $nested ? ($index % 2 === 0 ? 'nested/first/' : 'nested/second/') : '';
            $key = $prefix.'archive-'.$index.'.tar.gz';
            File::put($this->directory.'/'.$key, 'archive');
            $keys[] = $key;
        }

        return $keys;
    }

    /** @param list<string> $keys */
    private function deleteProcess(array $keys): Process
    {
        $identity = stat($this->directory);

        return new Process([
            'sh', '-c', 'ulimit -n 64 || exit 1; exec "$@"', 'sh',
            '/usr/local/bin/volumevault-local-archive-reader', 'delete', $this->directory,
            (string) $identity['dev'], (string) $identity['ino'], ...$keys,
        ]);
    }
}
