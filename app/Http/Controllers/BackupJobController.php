<?php

namespace App\Http\Controllers;

use App\Actions\Backup\CreateBackupRun;
use App\Http\Requests\BackupJobRequest;
use App\Jobs\RunBackupJob;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BackupJobController extends Controller
{
    public function __construct(private readonly BackupScheduleCalculator $scheduleCalculator) {}

    public function index(): Response
    {
        return Inertia::render('BackupJobs/Index', [
            'jobs' => BackupJob::with('destination')
                ->latest()
                ->get()
                ->map(fn (BackupJob $job) => $this->serializeJob($job)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('BackupJobs/Form', $this->formProps());
    }

    public function store(BackupJobRequest $request)
    {
        $job = BackupJob::create($this->payload($request));

        ActivityLog::record('backup_job_created', 'Backup job created.', $job);

        return redirect()->route('backup-jobs.index')->with('success', 'Backup job created.');
    }

    public function show(BackupJob $backupJob): Response
    {
        $backupJob->load('destination');

        return Inertia::render('BackupJobs/Show', [
            'job' => $this->serializeJob($backupJob),
            'runs' => $backupJob->runs()->limit(50)->get(),
        ]);
    }

    public function edit(BackupJob $backupJob): Response
    {
        $backupJob->load('destination');

        return Inertia::render('BackupJobs/Form', [
            ...$this->formProps(),
            'job' => $this->serializeJob($backupJob),
        ]);
    }

    public function update(BackupJobRequest $request, BackupJob $backupJob)
    {
        $backupJob->update($this->payload($request, $backupJob->status));

        return redirect()->route('backup-jobs.index')->with('success', 'Backup job updated.');
    }

    public function destroy(BackupJob $backupJob)
    {
        $backupJob->delete();

        return redirect()->route('backup-jobs.index')->with('success', 'Backup job deleted.');
    }

    public function runNow(BackupJob $backupJob, CreateBackupRun $createBackupRun)
    {
        $run = $createBackupRun->handle($backupJob, BackupRun::TRIGGER_MANUAL);
        RunBackupJob::dispatch($run->id);

        return redirect()->route('backup-runs.show', $run)->with('success', 'Backup run queued.');
    }

    public function pause(Request $request, BackupJob $backupJob)
    {
        if ($backupJob->status === BackupJob::STATUS_RUNNING) {
            throw ValidationException::withMessages(['job' => 'A running job cannot be paused.']);
        }

        $backupJob->forceFill([
            'status' => BackupJob::STATUS_PAUSED,
            'pause_reason' => $request->input('pause_reason', 'Paused manually.'),
        ])->save();

        return back()->with('success', 'Backup job paused.');
    }

    public function resume(BackupJob $backupJob)
    {
        $backupJob->forceFill([
            'status' => BackupJob::STATUS_ACTIVE,
            'pause_reason' => null,
            'last_error' => null,
            'next_run_at' => $this->scheduleCalculator->nextRunAt($backupJob->schedule_type, $backupJob->schedule_config ?? []),
        ])->save();

        return back()->with('success', 'Backup job resumed.');
    }

    private function formProps(): array
    {
        return [
            'job' => null,
            'volumes' => DockerVolume::where('exists', true)->orderBy('name')->get(['name']),
            'destinations' => BackupDestination::where('is_active', true)->orderBy('name')->get()->map->safeForFrontend(),
        ];
    }

    private function payload(BackupJobRequest $request, ?string $status = BackupJob::STATUS_ACTIVE): array
    {
        $scheduleType = $request->input('schedule_type');
        $scheduleConfig = $request->normalizedScheduleConfig();
        $backupExcludeRegexp = trim((string) $request->input('backup_exclude_regexp', ''));

        return [
            'name' => $request->input('name'),
            'volume_name' => $request->input('volume_name'),
            'backup_destination_id' => $request->integer('backup_destination_id'),
            'schedule_type' => $scheduleType,
            'schedule_config' => $scheduleConfig,
            'cron_expression' => $this->scheduleCalculator->cronExpression($scheduleType, $scheduleConfig),
            'status' => $status ?: BackupJob::STATUS_ACTIVE,
            'next_run_at' => $this->scheduleCalculator->nextRunAt($scheduleType, $scheduleConfig),
            'retention_days' => $request->input('retention_days'),
            'retention_count' => $request->input('retention_count'),
            'backup_exclude_regexp' => $backupExcludeRegexp !== '' ? $backupExcludeRegexp : null,
            'stop_containers_before_backup' => $request->boolean('stop_containers_before_backup'),
        ];
    }

    private function serializeJob(BackupJob $job): array
    {
        return [
            ...$job->toArray(),
            'destination' => $job->destination?->safeForFrontend(),
            'schedule_summary' => $this->scheduleCalculator->summary($job->schedule_type, $job->schedule_config ?? []),
        ];
    }
}
