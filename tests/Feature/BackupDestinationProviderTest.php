<?php

namespace Tests\Feature;

use App\Actions\Docker\RunBackupContainer;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupDestinationProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_offen_environment_is_mapped_for_supported_non_s3_providers(): void
    {
        $process = new class extends DockerProcess
        {
            public array $calls = [];

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->calls[] = ['command' => $command, 'environment' => $environment];

                return new DockerProcessResult($command, 0, 'ok', '');
            }
        };
        $action = new RunBackupContainer($process);

        $cases = [
            [
                'provider' => BackupDestination::PROVIDER_WEBDAV,
                'settings' => ['url' => 'https://webdav.example.com', 'path' => '/volumevault', 'insecure' => true],
                'secrets' => ['username' => 'user', 'password' => 'pass'],
                'expected' => ['WEBDAV_URL' => 'https://webdav.example.com', 'WEBDAV_PATH' => '/volumevault', 'WEBDAV_USERNAME' => 'user', 'WEBDAV_PASSWORD' => 'pass', 'WEBDAV_URL_INSECURE' => 'true'],
            ],
            [
                'provider' => BackupDestination::PROVIDER_SSH,
                'settings' => ['host' => 'server.local', 'port' => 2222, 'remote_path' => '/srv/backups'],
                'secrets' => ['user' => 'backup', 'password' => 'secret'],
                'expected' => ['SSH_HOST_NAME' => 'server.local', 'SSH_PORT' => '2222', 'SSH_REMOTE_PATH' => '/srv/backups', 'SSH_USER' => 'backup', 'SSH_PASSWORD' => 'secret'],
            ],
            [
                'provider' => BackupDestination::PROVIDER_AZURE_BLOB,
                'settings' => ['account_name' => 'account', 'container' => 'backups'],
                'secrets' => ['account_key' => 'key'],
                'expected' => ['AZURE_STORAGE_ACCOUNT_NAME' => 'account', 'AZURE_STORAGE_CONTAINER_NAME' => 'backups', 'AZURE_STORAGE_PRIMARY_ACCOUNT_KEY' => 'key'],
            ],
            [
                'provider' => BackupDestination::PROVIDER_DROPBOX,
                'settings' => ['remote_path' => '/backups'],
                'secrets' => ['app_key' => 'app', 'app_secret' => 'secret', 'refresh_token' => 'refresh'],
                'expected' => ['DROPBOX_REMOTE_PATH' => '/backups', 'DROPBOX_APP_KEY' => 'app', 'DROPBOX_APP_SECRET' => 'secret', 'DROPBOX_REFRESH_TOKEN' => 'refresh'],
            ],
            [
                'provider' => BackupDestination::PROVIDER_GOOGLE_DRIVE,
                'settings' => ['folder_id' => 'folder'],
                'secrets' => ['credentials_json' => '{"client_email":"svc@example.com","private_key":"key"}'],
                'expected' => ['GOOGLE_DRIVE_FOLDER_ID' => 'folder', 'GOOGLE_DRIVE_CREDENTIALS_JSON' => '{"client_email":"svc@example.com","private_key":"key"}'],
            ],
            [
                'provider' => BackupDestination::PROVIDER_LOCAL,
                'settings' => ['archive_path' => '/archive', 'archive_mount_source' => '/host/archive'],
                'secrets' => [],
                'expected' => ['BACKUP_ARCHIVE' => '/archive'],
                'expected_mount' => '/host/archive:/archive',
            ],
        ];

        foreach ($cases as $index => $case) {
            $destination = BackupDestination::create([
                'name' => 'Destination '.$index,
                'provider' => $case['provider'],
                'bucket' => 'target',
                'access_key_id' => '',
                'secret_access_key' => '',
                'settings' => $case['settings'],
                'secrets' => $case['secrets'],
            ]);
            $job = BackupJob::create([
                'name' => 'Job '.$index,
                'volume_name' => 'app_data',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
                'cron_expression' => '0 2 * * *',
                'status' => BackupJob::STATUS_ACTIVE,
            ]);
            $run = BackupRun::create([
                'backup_job_id' => $job->id,
                'status' => BackupRun::STATUS_QUEUED,
                'trigger' => BackupRun::TRIGGER_MANUAL,
            ]);

            $action->handle($run);
            $call = $process->calls[$index];

            $this->assertContains('--entrypoint', $call['command']);
            $this->assertContains('/usr/bin/backup', $call['command']);
            $this->assertNotContains('-foreground', $call['command']);

            foreach ($case['expected'] as $key => $value) {
                $this->assertSame($value, $call['environment'][$key] ?? null);
                $this->assertContains($key, $call['command']);
            }

            if (isset($case['expected_mount'])) {
                $this->assertContains($case['expected_mount'], $call['command']);
            }
        }
    }

    public function test_provider_specific_secrets_are_encrypted_and_hidden(): void
    {
        $destination = BackupDestination::create([
            'name' => 'Dropbox',
            'provider' => BackupDestination::PROVIDER_DROPBOX,
            'bucket' => 'dropbox',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['remote_path' => '/backups'],
            'secrets' => ['app_key' => 'plain-app', 'app_secret' => 'plain-secret', 'refresh_token' => 'plain-refresh'],
        ]);

        $this->assertNotSame('plain-secret', $destination->getRawOriginal('secrets'));
        $this->assertSame('plain-secret', $destination->secret('app_secret'));
        $this->assertTrue($destination->safeForFrontend()['has_secrets']['app_secret']);
        $this->assertStringNotContainsString('plain-secret', json_encode($destination->safeForFrontend()));
    }

    public function test_backup_exclude_regexp_is_passed_to_offen_environment(): void
    {
        $process = new class extends DockerProcess
        {
            public array $calls = [];

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->calls[] = ['command' => $command, 'environment' => $environment];

                return new DockerProcessResult($command, 0, 'ok', '');
            }
        };
        $action = new RunBackupContainer($process);
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => '/archive', 'archive_mount_source' => '/host/archive'],
        ]);
        $job = BackupJob::create([
            'name' => 'Logs excluded',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'backup_exclude_regexp' => '\\.log$',
        ]);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);

        $action->handle($run);
        $call = $process->calls[0];

        $this->assertSame('\\.log$', $call['environment']['BACKUP_EXCLUDE_REGEXP'] ?? null);
        $this->assertContains('BACKUP_EXCLUDE_REGEXP', $call['command']);
    }
}
