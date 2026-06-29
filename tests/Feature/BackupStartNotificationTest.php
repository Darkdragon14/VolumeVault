<?php

namespace Tests\Feature;

use App\Actions\Backup\RunBackup;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\Notifications\SendShoutrrrNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class BackupStartNotificationTest extends TestCase
{
    use RefreshDatabase;

    private string $storagePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir().'/volumevault-start-notify-'.uniqid();
        File::ensureDirectoryExists($this->storagePath);
        $this->app->useStoragePath($this->storagePath);
        $this->app->instance(DockerProcess::class, $this->successfulDocker());
    }

    protected function tearDown(): void
    {
        if ($this->storagePath !== '') {
            File::deleteDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    public function test_a_normal_backup_run_emits_a_start_notification(): void
    {
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendBackupRunStarted')->once();
        $notifier->shouldReceive('sendBackupRunFinished')->zeroOrMoreTimes();
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        app(RunBackup::class)->handle($this->backupRun(BackupRun::TRIGGER_MANUAL));
    }

    public function test_a_pre_restore_safety_backup_does_not_emit_a_start_notification(): void
    {
        // A pre-restore safety backup stays invisible to the job lifecycle, so it
        // must not ping start URLs or send a "backup started" message.
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldNotReceive('sendBackupRunStarted');
        $notifier->shouldReceive('sendBackupRunFinished')->zeroOrMoreTimes();
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        app(RunBackup::class)->handle($this->backupRun(BackupRun::TRIGGER_PRE_RESTORE));
    }

    private function backupRun(string $trigger): BackupRun
    {
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'is_active' => true,
            'settings' => ['archive_path' => $this->storagePath],
        ]);

        $job = BackupJob::create([
            'name' => 'Job',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        return BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => $trigger,
        ]);
    }

    private function successfulDocker(): DockerProcess
    {
        return new class extends DockerProcess
        {
            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if (($command[1] ?? null) === 'volume' && ($command[2] ?? null) === 'inspect') {
                    return new DockerProcessResult($command, 0, '[{"Name":"app_data"}]', '');
                }

                return new DockerProcessResult($command, 0, '', '');
            }

            public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                return new DockerProcessResult($command, 0, 'backup complete', '');
            }
        };
    }
}
