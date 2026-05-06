<?php

namespace App\Services\InstallationSaves;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

class CreateSecureInstallationSave
{
    private const EXCLUDED_PREFIXES = [
        'app/installation-saves/',
        'app/restore-runs/',
        'framework/cache/',
        'framework/sessions/',
        'framework/views/',
        'logs/',
    ];

    public function __construct(private readonly SecureSaveCrypto $crypto) {}

    public function handle(): GeneratedInstallationSave
    {
        $databasePath = $this->sqliteDatabasePath();
        $storageRoot = realpath(storage_path());

        if ($storageRoot === false) {
            throw new RuntimeException('Storage path does not exist.');
        }

        $databaseRelativePath = $this->relativePath($databasePath, $storageRoot);

        $tmpDirectory = storage_path('app/installation-saves/tmp/'.Str::uuid());
        $saveDirectory = storage_path('app/installation-saves');
        File::ensureDirectoryExists($tmpDirectory);
        File::ensureDirectoryExists($saveDirectory);

        $snapshotPath = $tmpDirectory.'/database.sqlite';
        $zipPath = $tmpDirectory.'/payload.zip';
        $filename = 'volumevault-installation-'.now('UTC')->format('Ymd-His').'.vvsave';
        $savePath = $saveDirectory.'/'.$filename;

        try {
            $this->snapshotSqliteDatabase($databasePath, $snapshotPath);
            $this->createPayloadZip($zipPath, $storageRoot, $databasePath, $databaseRelativePath, $snapshotPath);

            File::put($savePath, $this->crypto->encrypt(File::get($zipPath), (string) config('app.key')));

            return new GeneratedInstallationSave($savePath, $filename, File::size($savePath));
        } finally {
            File::deleteDirectory($tmpDirectory);
        }
    }

    private function sqliteDatabasePath(): string
    {
        $connection = config('database.default');
        $config = config('database.connections.'.$connection);

        if (($config['driver'] ?? null) !== 'sqlite') {
            throw new RuntimeException('Secure installation saves currently require a SQLite database.');
        }

        $path = (string) ($config['database'] ?? '');

        if ($path === '' || $path === ':memory:') {
            throw new RuntimeException('Secure installation saves require a file-based SQLite database.');
        }

        $realPath = realpath($path);

        if ($realPath === false || ! File::exists($realPath)) {
            throw new RuntimeException('SQLite database file was not found.');
        }

        $storageRoot = realpath(storage_path());

        if ($storageRoot === false || ! $this->isInsideDirectory($realPath, $storageRoot)) {
            throw new RuntimeException('DB_DATABASE must be located inside the Laravel storage directory.');
        }

        return $realPath;
    }

    private function snapshotSqliteDatabase(string $databasePath, string $snapshotPath): void
    {
        File::delete($snapshotPath);

        try {
            $quotedPath = DB::connection()->getPdo()->quote($snapshotPath);
            DB::statement('VACUUM INTO '.$quotedPath);
        } catch (Throwable) {
            File::copy($databasePath, $snapshotPath);
        }
    }

    private function createPayloadZip(string $zipPath, string $storageRoot, string $databasePath, string $databaseRelativePath, string $snapshotPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the installation save payload.');
        }

        $zip->addFromString('manifest.json', json_encode($this->manifest($databaseRelativePath), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storageRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = $file->getRealPath();

            if ($path === false) {
                continue;
            }

            $relative = $this->relativePath($path, $storageRoot);

            if ($path === $databasePath || $this->isDatabaseSidecar($relative, $databaseRelativePath) || $this->isExcluded($relative)) {
                continue;
            }

            $zip->addFile($path, 'storage/'.$relative);
        }

        $zip->addFile($snapshotPath, 'storage/'.$databaseRelativePath);
        $zip->close();
    }

    private function manifest(string $databaseRelativePath): array
    {
        return [
            'format' => SecureSaveCrypto::FORMAT,
            'version' => SecureSaveCrypto::VERSION,
            'created_at' => now('UTC')->toIso8601String(),
            'app_name' => config('app.name'),
            'laravel_version' => app()->version(),
            'database' => [
                'connection' => config('database.default'),
                'driver' => 'sqlite',
                'relative_path' => $databaseRelativePath,
            ],
            'excluded_paths' => self::EXCLUDED_PREFIXES,
        ];
    }

    private function relativePath(string $path, string $root): string
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        if (! str_starts_with($path, $root.'/')) {
            throw new RuntimeException('Path is outside the expected directory.');
        }

        return ltrim(substr($path, strlen($root)), '/');
    }

    private function isInsideDirectory(string $path, string $directory): bool
    {
        $path = str_replace('\\', '/', $path);
        $directory = rtrim(str_replace('\\', '/', $directory), '/').'/';

        return str_starts_with($path, $directory);
    }

    private function isExcluded(string $relative): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isDatabaseSidecar(string $relative, string $databaseRelativePath): bool
    {
        return in_array($relative, [$databaseRelativePath.'-wal', $databaseRelativePath.'-shm'], true);
    }
}
