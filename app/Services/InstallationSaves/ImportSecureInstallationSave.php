<?php

namespace App\Services\InstallationSaves;

use App\Models\ActivityLog;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

class ImportSecureInstallationSave
{
    private const VOLATILE_TABLES = [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
    ];

    public function __construct(private readonly SecureSaveCrypto $crypto) {}

    public function handle(string $savePath, string $previousAppKey): void
    {
        $tmpDirectory = rtrim(sys_get_temp_dir(), '/').'/volumevault-import-'.Str::uuid();
        $zipPath = $tmpDirectory.'/payload.zip';
        $extractDirectory = $tmpDirectory.'/extract';

        File::ensureDirectoryExists($extractDirectory);

        try {
            File::put($zipPath, $this->crypto->decrypt(File::get($savePath), $previousAppKey));
            $this->extractPayload($zipPath, $extractDirectory);

            $manifest = $this->readManifest($extractDirectory.'/manifest.json');
            $storageSource = $extractDirectory.'/storage';
            $databasePath = $storageSource.'/'.$manifest['database']['relative_path'];

            if (! File::isDirectory($storageSource) || ! File::exists($databasePath)) {
                throw new RuntimeException('The installation save does not contain a valid storage payload.');
            }

            $this->prepareImportedDatabase($databasePath, $previousAppKey);
            $this->replaceStorage($storageSource);

            DB::purge();
            DB::reconnect();

            ActivityLog::record('installation_imported', 'Installation save imported.', null, [
                'format_version' => $manifest['version'],
                'created_at' => $manifest['created_at'] ?? null,
            ]);
        } finally {
            File::deleteDirectory($tmpDirectory);
        }
    }

    private function extractPayload(string $zipPath, string $extractDirectory): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open the installation save payload.');
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if (! $this->isSafeEntryName($name)) {
                    throw new RuntimeException('The installation save contains an unsafe file path.');
                }
            }

            if (! $zip->extractTo($extractDirectory)) {
                throw new RuntimeException('Unable to extract the installation save payload.');
            }
        } finally {
            $zip->close();
        }
    }

    private function readManifest(string $path): array
    {
        if (! File::exists($path)) {
            throw new RuntimeException('The installation save manifest is missing.');
        }

        $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        if (($manifest['format'] ?? null) !== SecureSaveCrypto::FORMAT || ($manifest['version'] ?? null) !== SecureSaveCrypto::VERSION) {
            throw new RuntimeException('Unsupported installation save manifest.');
        }

        $databasePath = $manifest['database']['relative_path'] ?? null;

        if (! is_string($databasePath) || $databasePath === '' || ! $this->isRelativePath($databasePath)) {
            throw new RuntimeException('The installation save manifest has an invalid database path.');
        }

        return $manifest;
    }

    private function prepareImportedDatabase(string $databasePath, string $previousAppKey): void
    {
        $pdo = new PDO('sqlite:'.$databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $oldEncrypter = $this->crypto->makeLaravelEncrypter($previousAppKey);
        $currentEncrypter = $this->crypto->makeLaravelEncrypter((string) config('app.key'));

        $this->clearVolatileTables($pdo);
        $this->reencryptColumn($pdo, 'backup_destinations', 'access_key_id', $oldEncrypter, $currentEncrypter);
        $this->reencryptColumn($pdo, 'backup_destinations', 'secret_access_key', $oldEncrypter, $currentEncrypter);
        $this->reencryptColumn($pdo, 'backup_destinations', 'secrets', $oldEncrypter, $currentEncrypter);
        $this->reencryptColumn($pdo, 'notification_channels', 'url', $oldEncrypter, $currentEncrypter);
    }

    private function clearVolatileTables(PDO $pdo): void
    {
        foreach (self::VOLATILE_TABLES as $table) {
            if ($this->tableExists($pdo, $table)) {
                $pdo->exec('DELETE FROM '.$this->quoteIdentifier($table));
            }
        }
    }

    private function reencryptColumn(PDO $pdo, string $table, string $column, Encrypter $oldEncrypter, Encrypter $currentEncrypter): void
    {
        if (! $this->tableExists($pdo, $table) || ! $this->columnExists($pdo, $table, $column)) {
            return;
        }

        $rows = $pdo->query('SELECT id, '.$this->quoteIdentifier($column).' FROM '.$this->quoteIdentifier($table).' WHERE '.$this->quoteIdentifier($column).' IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
        $statement = $pdo->prepare('UPDATE '.$this->quoteIdentifier($table).' SET '.$this->quoteIdentifier($column).' = :value WHERE id = :id');

        foreach ($rows as $row) {
            $encrypted = (string) $row[$column];

            if ($encrypted === '') {
                continue;
            }

            try {
                $plain = $oldEncrypter->decrypt($encrypted, false);
            } catch (Throwable $exception) {
                throw new RuntimeException('Unable to decrypt imported secrets. Check the previous APP_KEY.', previous: $exception);
            }

            $statement->execute([
                'id' => $row['id'],
                'value' => $currentEncrypter->encrypt($plain, false),
            ]);
        }
    }

    private function replaceStorage(string $storageSource): void
    {
        $storageRoot = storage_path();

        DB::disconnect();
        File::ensureDirectoryExists($storageRoot);
        File::cleanDirectory($storageRoot);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storageSource, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relative = $this->relativePath($item->getPathname(), $storageSource);
            $target = $storageRoot.'/'.$relative;

            if ($item->isDir()) {
                File::ensureDirectoryExists($target);

                continue;
            }

            File::ensureDirectoryExists(dirname($target));
            File::copy($item->getPathname(), $target);
        }

        foreach (['app/private', 'app/public', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
            File::ensureDirectoryExists($storageRoot.'/'.$directory);
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
        $statement->execute(['name' => $table]);

        return $statement->fetchColumn() !== false;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->query('PRAGMA table_info('.$this->quoteIdentifier($table).')');

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    private function isSafeEntryName(string $name): bool
    {
        return $name !== ''
            && ! str_starts_with($name, '/')
            && ! str_contains($name, "\0")
            && ! collect(explode('/', $name))->contains('..')
            && ($name === 'manifest.json' || str_starts_with($name, 'storage/'));
    }

    private function isRelativePath(string $path): bool
    {
        return ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! collect(explode('/', $path))->contains('..');
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

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
