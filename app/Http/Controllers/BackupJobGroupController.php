<?php

namespace App\Http\Controllers;

use App\Actions\Backup\CreateBackupGroupRun;
use App\Concerns\PaginateWithPreference;
use App\Http\Requests\BackupJobGroupRequest;
use App\Jobs\RunBackupGroupJob;
use App\Models\ActivityLog;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\NotificationChannel;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BackupJobGroupController extends Controller
{
    use PaginateWithPreference;

    public function __construct(private readonly BackupScheduleCalculator $scheduleCalculator) {}

    public function index(Request $request): Response
    {
        $perPage = $this->perPageForRequest($request);

        $query = BackupJobGroup::withCount('members')->with('notificationChannels');

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $query->latest();

        return Inertia::render('BackupGroups/Index', [
            'groups' => $this->paginateForInertia($query, $perPage, fn (BackupJobGroup $group): array => $this->serializeGroup($group)),
            'defaultPerPage' => $request->user()->default_per_page ?? 10,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('BackupGroups/Form', $this->formProps());
    }

    public function store(BackupJobGroupRequest $request)
    {
        $group = BackupJobGroup::create($this->payload($request));
        $this->syncNotificationChannels($group, $request);

        ActivityLog::record('backup_group_created', 'Backup group created.', $group);

        return redirect()->route('backup-groups.edit', $group)->with('success', 'Backup group created. Add jobs to it from the backup job form.');
    }

    public function edit(BackupJobGroup $backupGroup): Response
    {
        $backupGroup->load(['notificationChannels', 'members.destination']);

        return Inertia::render('BackupGroups/Form', [
            ...$this->formProps(),
            'group' => $this->serializeGroup($backupGroup, withMembers: true),
        ]);
    }

    public function update(BackupJobGroupRequest $request, BackupJobGroup $backupGroup)
    {
        $backupGroup->update($this->payload($request, $backupGroup->status, $backupGroup));
        $this->syncNotificationChannels($backupGroup, $request);

        // Members mirror the group's schedule columns (they are never dispatched on
        // their own, but the columns must stay valid and consistent).
        $this->syncMemberSchedules($backupGroup);

        return redirect()->route('backup-groups.index')->with('success', 'Backup group updated.');
    }

    public function destroy(BackupJobGroup $backupGroup)
    {
        // Refuse to orphan members: a detached member keeps no schedule and would
        // silently stop backing up. The user must first move its jobs back to
        // standalone (or to another group) from the backup job form. Flash an error
        // (rather than a validation error) so the groups index — which has no form
        // to bind field errors to — shows it via the layout's flash banner.
        if ($backupGroup->members()->exists()) {
            return redirect()->route('backup-groups.index')
                ->with('error', 'Remove or reassign this group\'s jobs before deleting it.');
        }

        // Refuse to delete while a run is in flight: the cascade would drop the
        // backup_group_run a worker may still be executing, losing its finalization,
        // notification and history. Wait for it to finish (or be reconciled).
        if ($backupGroup->groupRuns()->whereIn('status', [BackupGroupRun::STATUS_QUEUED, BackupGroupRun::STATUS_RUNNING])->exists()) {
            return redirect()->route('backup-groups.index')
                ->with('error', 'This group has a backup run in progress. Wait for it to finish before deleting it.');
        }

        $backupGroup->delete();

        return redirect()->route('backup-groups.index')->with('success', 'Backup group deleted.');
    }

    public function runNow(Request $request, BackupJobGroup $backupGroup, CreateBackupGroupRun $createBackupGroupRun)
    {
        $run = $createBackupGroupRun->handle($backupGroup, BackupGroupRun::TRIGGER_MANUAL, $request->user());
        RunBackupGroupJob::dispatch($run->id);

        return redirect()->route('backup-group-runs.show', $run)->with('success', 'Backup group run queued.');
    }

    public function pause(Request $request, BackupJobGroup $backupGroup)
    {
        if ($backupGroup->status === BackupJobGroup::STATUS_RUNNING) {
            throw ValidationException::withMessages(['group' => 'A running group cannot be paused.']);
        }

        $backupGroup->forceFill([
            'status' => BackupJobGroup::STATUS_PAUSED,
            'pause_reason' => $request->input('pause_reason', 'Paused manually.'),
        ])->save();

        return back()->with('success', 'Backup group paused.');
    }

    public function resume(BackupJobGroup $backupGroup)
    {
        $backupGroup->forceFill([
            'status' => BackupJobGroup::STATUS_ACTIVE,
            'pause_reason' => null,
            'last_error' => null,
            'last_error_at' => null,
            'next_run_at' => $this->scheduleCalculator->nextRunAt($backupGroup->schedule_type, $backupGroup->schedule_config ?? [], null, $backupGroup->timezone),
        ])->save();

        return back()->with('success', 'Backup group resumed.');
    }

    public function toggleNotifications(Request $request, BackupJobGroup $backupGroup)
    {
        $backupGroup->forceFill([
            'notifications_enabled' => $request->boolean('notifications_enabled'),
        ])->save();

        return back()->with('success', 'Backup group notifications updated.');
    }

    private function formProps(): array
    {
        return [
            'group' => null,
            'notificationChannels' => NotificationChannel::orderBy('name')->get()->map->safeForFrontend(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'appTimezone' => config('app.timezone'),
        ];
    }

    private function payload(BackupJobGroupRequest $request, ?string $status = BackupJobGroup::STATUS_ACTIVE, ?BackupJobGroup $group = null): array
    {
        $scheduleType = $request->input('schedule_type');
        $scheduleConfig = $request->normalizedScheduleConfig();
        $timezone = $request->filled('timezone') ? $request->input('timezone') : null;

        return [
            'name' => $request->input('name'),
            'schedule_type' => $scheduleType,
            'schedule_config' => $scheduleConfig,
            'cron_expression' => $this->scheduleCalculator->cronExpression($scheduleType, $scheduleConfig),
            'timezone' => $timezone,
            'status' => $status ?: BackupJobGroup::STATUS_ACTIVE,
            'failure_policy' => $request->input('failure_policy', BackupJobGroup::FAILURE_POLICY_CONTINUE),
            // On update, keep the stored value when the request omits the toggle,
            // so an API caller cannot silently re-enable disabled notifications.
            'notifications_enabled' => $request->has('notifications_enabled') ? $request->boolean('notifications_enabled') : (bool) ($group?->notifications_enabled ?? true),
            'next_run_at' => $this->scheduleCalculator->nextRunAt($scheduleType, $scheduleConfig, null, $timezone),
        ];
    }

    private function syncNotificationChannels(BackupJobGroup $group, BackupJobGroupRequest $request): void
    {
        if ($request->has('notification_channel_ids')) {
            $group->notificationChannels()->sync(
                collect($request->input('notification_channel_ids', []))
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all(),
            );
        }
    }

    /**
     * Keep member jobs' (unused but non-null) schedule columns aligned with the
     * group. Members are never dispatched on their own; next_run_at stays null.
     */
    private function syncMemberSchedules(BackupJobGroup $group): void
    {
        $group->members()->update([
            'schedule_type' => $group->schedule_type,
            'schedule_config' => json_encode($group->schedule_config),
            'cron_expression' => $group->cron_expression,
            'timezone' => $group->timezone,
            'next_run_at' => null,
        ]);
    }

    private function serializeGroup(BackupJobGroup $group, bool $withMembers = false): array
    {
        $data = [
            ...$group->toArray(),
            'notification_channel_ids' => $group->relationLoaded('notificationChannels')
                ? $group->notificationChannels->pluck('id')->values()->all()
                : $group->notificationChannels()->pluck('notification_channels.id')->values()->all(),
            'members_count' => $group->members_count ?? $group->members()->count(),
            'schedule_summary' => $this->scheduleCalculator->summary($group->schedule_type, $group->schedule_config ?? []),
        ];

        if ($withMembers) {
            $data['members'] = $group->members->map(fn (BackupJob $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'source_label' => $member->sourceName(),
                'source_type' => $member->sourceType(),
                'status' => $member->status,
                'destination' => $member->destination?->name,
                'last_success_at' => $member->last_success_at,
                'last_error' => $member->last_error,
            ])->values()->all();

            $data['recent_runs'] = $group->groupRuns()->limit(10)->get()->map(fn (BackupGroupRun $run): array => [
                'id' => $run->id,
                'status' => $run->status,
                'trigger' => $run->trigger,
                'total_members' => $run->total_members,
                'succeeded_members' => $run->succeeded_members,
                'failed_members' => $run->failed_members,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
                'duration_seconds' => $run->duration_seconds,
            ])->values()->all();
        }

        return $data;
    }
}
