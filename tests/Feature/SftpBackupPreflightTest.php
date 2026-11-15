<?php

namespace Tests\Feature;

use App\Actions\Backup\RunBackup;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\S3\S3ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SftpBackupPreflightTest extends TestCase
{
    use RefreshDatabase;

    private const PUBKEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP7m24OrCqk9z3+lIB2Pa3L7Z5FkAOsXr7iKWvMkElYr';

    private const FINGERPRINT = 'SHA256:ev373BeFyQ16vITd1dmhbEyDfyqd4ZexqHYICIZ+9VU';

    private const OTHER_PUBKEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOF8jM81mWIRv8vwRltHg5eH9keV7GalxtABUIQK9r1P';

    #[DataProvider('failedPreflights')]
    public function test_failed_host_key_preflight_never_inspects_stops_or_backs_up_containers(?string $pin, string $probe, string $error): void
    {
        Bus::fake();
        $docker = $this->recordingDocker();
        $storage = $this->storage();

        if ($probe === 'unreachable') {
            $storage->shouldReceive('probeHostKey')->once()->andThrow(new RuntimeException('Connection timed out'));
        } elseif ($probe === 'mismatch') {
            $storage->shouldReceive('probeHostKey')->once()->andReturn([
                'key' => self::OTHER_PUBKEY,
                'fingerprint' => DestinationStorage::hostKeyFingerprint(self::OTHER_PUBKEY),
            ]);
        } else {
            $storage->shouldNotReceive('probeHostKey');
        }

        $run = $this->backupRun($this->destination($pin));

        app(RunBackup::class)->handle($run);

        $run->refresh();
        $this->assertSame(BackupRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString($error, $run->error_message);
        $this->assertSame([], $docker->commands);
        $this->assertNull($run->stopped_container_ids);
        $this->assertNull($run->docker_container_id);
    }

    public static function failedPreflights(): array
    {
        return [
            'missing pin' => [null, 'none', 'Configure a valid SFTP'],
            'malformed pin' => ['ssh-ed25519 invalid', 'none', 'Configure a valid SFTP'],
            'malformed fingerprint' => ['SHA256:invalid', 'none', 'Configure a valid SFTP'],
            'unreachable server' => [self::FINGERPRINT, 'unreachable', 'Connection timed out'],
            'mismatched fingerprint' => [self::FINGERPRINT, 'mismatch', 'host key verification failed'],
        ];
    }

    public function test_fingerprint_is_probed_once_before_stopping_and_reused_by_backup_container(): void
    {
        Bus::fake();
        $docker = $this->recordingDocker();
        $this->storage()->shouldReceive('probeHostKey')->once()->with('ssh.example.com', 2222)
            ->andReturnUsing(function () use ($docker): array {
                $this->assertSame([], $docker->commands, 'Host verification must precede all Docker operations.');

                return ['key' => self::PUBKEY.' server-comment', 'fingerprint' => self::FINGERPRINT];
            });
        $run = $this->backupRun($this->destination(self::FINGERPRINT));

        app(RunBackup::class)->handle($run);

        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->fresh()->status);
        $this->assertSame(self::PUBKEY, $docker->backupEnvironment['SSH_HOST_PUBLIC_KEY'] ?? null);
        $this->assertArrayNotHasKey('SSH_HOST_KEY', $docker->backupEnvironment);
        $this->assertArrayNotHasKey('SSH_INSECURE', $docker->backupEnvironment);
        $this->assertArrayNotHasKey('SSH_SKIP_HOST_KEY_VERIFICATION', $docker->backupEnvironment);
        $this->assertArrayNotHasKey('SSH_INSECURE_IGNORE_HOST_KEY', $docker->backupEnvironment);
        $stopIndex = array_search(['docker', 'stop', 'app-1'], $docker->commands, true);
        $backupIndexes = array_keys(array_filter($docker->commands, fn (array $command): bool => ($command[1] ?? null) === 'run'));
        $this->assertIsInt($stopIndex);
        $this->assertCount(1, $backupIndexes);
        $this->assertLessThan($backupIndexes[0], $stopIndex);
        $this->assertContains(['docker', 'start', 'app-1'], $docker->commands);
    }

    public function test_snapshot_destination_pin_is_used_instead_of_current_job_destination(): void
    {
        Bus::fake();
        $docker = $this->recordingDocker();
        $this->storage()->shouldNotReceive('probeHostKey');
        $snapshot = $this->destination(self::PUBKEY.' snapshot-comment');
        $run = $this->backupRun($this->destination(self::OTHER_PUBKEY));
        $run->update(['backup_destination_id_snapshot' => $snapshot->id]);

        app(RunBackup::class)->handle($run);

        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->fresh()->status);
        $this->assertSame(self::PUBKEY, $docker->backupEnvironment['SSH_HOST_PUBLIC_KEY'] ?? null);
    }

    private function storage(): MockInterface&DestinationStorage
    {
        $storage = Mockery::mock(DestinationStorage::class, [app(S3ClientFactory::class)])->makePartial();
        $this->app->instance(DestinationStorage::class, $storage);

        return $storage;
    }

    private function destination(?string $pin): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'SFTP',
            'provider' => BackupDestination::PROVIDER_SSH,
            'bucket' => 'unused',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['host' => 'ssh.example.com', 'port' => 2222, 'remote_path' => '/backups', 'host_key' => $pin],
            'secrets' => ['user' => 'backup', 'password' => 'secret'],
        ]);
    }

    private function backupRun(BackupDestination $destination): BackupRun
    {
        $job = BackupJob::create([
            'name' => 'Application',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'stop_containers_before_backup' => true,
        ]);

        return BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
    }

    private function recordingDocker(): DockerProcess
    {
        $docker = new class extends DockerProcess
        {
            public array $commands = [];

            public array $backupEnvironment = [];

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->commands[] = $command;

                if (array_slice($command, 1, 2) === ['volume', 'inspect']) {
                    return new DockerProcessResult($command, 0, '[{"Name":"app_data","Driver":"local"}]', '');
                }

                if (($command[1] ?? null) === 'ps') {
                    return new DockerProcessResult($command, 0, '{"ID":"app-1","Names":"application","State":"running"}', '');
                }

                if (($command[1] ?? null) === 'run') {
                    $this->backupEnvironment = $environment;
                }

                return new DockerProcessResult($command, 0, 'ok', '');
            }
        };
        $this->app->instance(DockerProcess::class, $docker);

        return $docker;
    }
}
