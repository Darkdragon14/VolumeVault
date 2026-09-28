<?php

namespace App\Services\InstallationSaves;

use App\Models\ActivityLog;
use App\Services\Agents\ArchiveRelayStorage;
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
    private const ENCRYPTED_COLUMNS = [
        'backup_destinations' => ['access_key_id', 'secret_access_key', 'secrets'],
        'notification_channels' => ['url'],
        'users' => ['two_factor_secret', 'two_factor_recovery_codes'],
        'agent_operations' => ['payload', 'context', 'delivery_token', 'result'],
        'archive_relays' => ['destination_snapshot'],
        'run_finalizations' => ['remote_metadata_payload', 'notification_snapshot'],
    ];

    private const VOLATILE_TABLES = [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'two_factor_trusted_devices',
    ];

    public function __construct(private readonly SecureSaveCrypto $crypto) {}

    public function handle(string $savePath, string $previousAppKey): void
    {
        $tmpDirectory = rtrim(sys_get_temp_dir(), '/').'/volumevault-import-'.Str::uuid();
        $zipPath = $tmpDirectory.'/payload.zip';
        $extractDirectory = $tmpDirectory.'/extract';

        File::ensureDirectoryExists($tmpDirectory, 0700);
        File::ensureDirectoryExists($extractDirectory, 0700);

        try {
            File::put($zipPath, $this->crypto->decrypt(File::get($savePath), $previousAppKey));
            $this->extractPayload($zipPath, $extractDirectory);

            $manifest = $this->readManifest($extractDirectory.'/manifest.json');
            $storageSource = $extractDirectory.'/storage';
            $databasePath = $storageSource.'/'.$manifest['database']['relative_path'];

            if (! File::isDirectory($storageSource) || ! File::exists($databasePath)) {
                throw new RuntimeException('The installation save does not contain a valid storage payload.');
            }

            $relayPath = $manifest['archive_relay']['relative_path'] ?? 'app/private/archive-relays';

            if (! is_string($relayPath) || ! $this->isRelativePath($relayPath)
                || ! str_starts_with($relayPath, 'app/private/')
                || $relayPath !== $this->relativePath((string) config('volumevault.archive_relay.directory'), storage_path())) {
                throw new RuntimeException('The installation save relay storage path does not match this installation.');
            }

            $this->prepareImportedDatabase($databasePath, $previousAppKey, $storageSource.'/'.$relayPath);
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

    private function prepareImportedDatabase(string $databasePath, string $previousAppKey, string $relayDirectory): void
    {
        $pdo = new PDO('sqlite:'.$databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $oldEncrypter = $this->crypto->makeLaravelEncrypter($previousAppKey);
        $currentEncrypter = $this->crypto->makeLaravelEncrypter((string) config('app.key'));

        $pdo->beginTransaction();

        try {
            $this->clearVolatileTables($pdo);

            foreach (self::ENCRYPTED_COLUMNS as $table => $columns) {
                foreach ($columns as $column) {
                    $this->reencryptColumn($pdo, $table, $column, $oldEncrypter, $currentEncrypter);
                }
            }

            $this->reencryptRelayChunks($relayDirectory, $oldEncrypter, $currentEncrypter);
            $this->verifyRelayChunks($pdo, $relayDirectory, $currentEncrypter);
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }
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

            try {
                $statement->execute([
                    'id' => $row['id'],
                    'value' => $currentEncrypter->encrypt($plain, false),
                ]);
            } finally {
                $this->wipe($plain);
            }
        }
    }

    private function reencryptRelayChunks(string $directory, Encrypter $oldEncrypter, Encrypter $currentEncrypter): void
    {
        if (! File::isDirectory($directory)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($files as $file) {
            $relative = $this->relativePath($file->getPathname(), $directory);
            [$id, $offset] = array_pad(explode('/', $relative), 2, null);

            if (! Str::isUuid($id) || strtolower($id) !== $id || ! preg_match('/\A(?:0|[1-9][0-9]*)\z/', $offset ?? '')
                || $relative !== $id.'/'.$offset || $file->getSize() > 2 * ArchiveRelayStorage::CHUNK_BYTES) {
                throw new RuntimeException('The installation save contains invalid relay storage.');
            }

            try {
                $plain = $oldEncrypter->decryptString(File::get($file->getPathname()));
                $encrypted = $currentEncrypter->encryptString($plain);

                if (File::put($file->getPathname(), $encrypted) !== strlen($encrypted)) {
                    throw new RuntimeException('Unable to write imported relay storage.');
                }
            } catch (Throwable) {
                throw new RuntimeException('Unable to re-encrypt imported relay storage. Check the previous APP_KEY and save integrity.');
            } finally {
                $this->wipe($plain);
            }
        }
    }

    private function verifyRelayChunks(PDO $pdo, string $directory, Encrypter $encrypter): void
    {
        if (! $this->tableExists($pdo, 'archive_relays')) {
            return;
        }

        $rows = $pdo->query('SELECT id, uploaded_bytes, size_bytes, sha256 FROM archive_relays WHERE cleaned_at IS NULL AND uploaded_bytes > 0');

        foreach ($rows as $row) {
            if (! Str::isUuid($row['id']) || strtolower($row['id']) !== $row['id']
                || $row['uploaded_bytes'] > $row['size_bytes']
                || ($row['uploaded_bytes'] < $row['size_bytes'] && $row['uploaded_bytes'] % ArchiveRelayStorage::CHUNK_BYTES !== 0)) {
                throw new RuntimeException('The installation save contains invalid relay metadata.');
            }

            $hash = hash_init('sha256');
            for ($offset = 0; $offset < $row['uploaded_bytes']; $offset += ArchiveRelayStorage::CHUNK_BYTES) {
                $path = $directory.'/'.$row['id'].'/'.$offset;
                if (! File::isFile($path)) {
                    throw new RuntimeException('The installation save is missing relay chunks. Live storage has not been replaced.');
                }

                try {
                    $plain = $encrypter->decryptString(File::get($path));
                    if (strlen($plain) !== min(ArchiveRelayStorage::CHUNK_BYTES, $row['size_bytes'] - $offset)) {
                        throw new RuntimeException('The installation save contains an incomplete relay chunk.');
                    }
                    hash_update($hash, $plain);
                } finally {
                    $this->wipe($plain);
                }
            }

            if ($row['uploaded_bytes'] === $row['size_bytes'] && ! hash_equals((string) $row['sha256'], hash_final($hash))) {
                throw new RuntimeException('The installation save relay digest does not match.');
            }
        }
    }

    private function wipe(?string &$plain): void
    {
        if (is_string($plain) && function_exists('sodium_memzero')) {
            sodium_memzero($plain);
        }

        $plain = null;
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
                File::ensureDirectoryExists($target, $relative === 'app/private' || str_starts_with($relative, 'app/private/') ? 0700 : 0755);

                continue;
            }

            File::ensureDirectoryExists(dirname($target));
            File::copy($item->getPathname(), $target);
            if (str_starts_with($relative, 'app/private/')) {
                chmod($target, 0600);
            }
        }

        foreach (['app/private', 'app/public', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
            File::ensureDirectoryExists($storageRoot.'/'.$directory, $directory === 'app/private' ? 0700 : 0755);
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
