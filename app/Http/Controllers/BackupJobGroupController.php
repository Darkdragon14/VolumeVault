<?php

namespace App\Http\Controllers;

use App\Actions\Backup\CreateBackupGroupRun;
use App\Actions\Backup\CreateBackupJobGroup;
use App\Actions\Backup\DeleteBackupJobGroup;
use App\Actions\Backup\ResumeBackupJobGroup;
use App\Actions\Backup\UpdateBackupJobGroup;
use App\Actions\Runs\DispatchQueuedRun;
use App\Concerns\PaginateWithPreference;
use App\Http\Requests\BackupJobGroupRequest;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\NotificationChannel;
use App\Services\Agents\AgentExecution;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Database\Eloquent\Builder;
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

        $query = BackupJobGroup::withCount('members')->with(['notificationChannels', 'members.dockerHost'])
            ->withExists(['groupRuns as has_pending_run' => fn (Builder $query): Builder => $query->whereIn('status', ['queued', 'running'])]);

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

    public function store(BackupJobGroupRequest $request, CreateBackupJobGroup $createBackupJobGroup)
    {
        $group = $createBackupJobGroup->handle(
            $this->payload($request),
            'Backup group created.',
        );

        return redirect()->route('backup-groups.edit', $group)->with('success', 'Backup group created. Add jobs to it from the backup job form.');
    }

    public function show(Request $request, BackupJobGroup $backupGroup): Response
    {
        $backupGroup->load(['notificationChannels', 'members.destination', 'members.dockerHost'])->loadCount('members');
        $backupGroup->loadExists(['groupRuns as has_pending_run' => fn (Builder $query): Builder => $query->whereIn('status', ['queued', 'running'])]);
        $perPage = $this->perPageForRequest($request);

        return Inertia::render('BackupGroups/Show', [
            'group' => $this->serializeGroup($backupGroup, withMembers: true),
            'lastSuccessfulGroupBackupSize' => BackupGroupRun::lastSuccessfulTotalBackupSize($backupGroup->id),
            'runs' => $this->paginateForInertia($backupGroup->groupRuns()->withTotalBackupSize()->with('initiatedBy:id,name,email'), $perPage, null, 'runs_page'),
        ]);
    }

    public function edit(BackupJobGroup $backupGroup): Response
    {
        $backupGroup->load(['notificationChannels', 'members.destination', 'members.dockerHost']);
        $backupGroup->loadExists(['groupRuns as has_pending_run' => fn (Builder $query): Builder => $query->whereIn('status', ['queued', 'running'])]);

        return Inertia::render('BackupGroups/Form', [
            ...$this->formProps(),
            'group' => $this->serializeGroup($backupGroup, withMembers: true),
        ]);
    }

    public function update(BackupJobGroupRequest $request, BackupJobGroup $backupGroup, UpdateBackupJobGroup $updateBackupJobGroup)
    {
        $updateBackupJobGroup->handle($backupGroup, $this->updatePayload($request));

        return redirect()->route('backup-groups.index')->with('success', 'Backup group updated.');
    }

    public function destroy(BackupJobGroup $backupGroup, DeleteBackupJobGroup $deleteBackupJobGroup)
    {
        try {
            $deleteBackupJobGroup->handle($backupGroup);
        } catch (ValidationException $exception) {
            return redirect()->route('backup-groups.index')
                ->with('error', $exception->validator->errors()->first());
        }

        return redirect()->route('backup-groups.index')->with('success', 'Backup group deleted.');
    }

    public function runNow(Request $request, BackupJobGroup $backupGroup, CreateBackupGroupRun $createBackupGroupRun, DispatchQueuedRun $dispatchQueuedRun)
    {
        // Flash the reason (no runnable members, group not active, …) rather than
        // letting a validation error propagate: the groups index has no form to
        // bind it to, so the run-now action would otherwise appear to do nothing.
        try {
            $run = $createBackupGroupRun->handle($backupGroup, BackupGroupRun::TRIGGER_MANUAL, $request->user());
        } catch (ValidationException $exception) {
            return redirect()->route('backup-groups.index')
                ->with('error', $exception->validator->errors()->first() ?: 'This backup group cannot run right now.');
        }

        $dispatchQueuedRun->handle($run);

        return redirect()->route('backup-group-runs.show', $run)->with('success', 'Backup group run queued.');
    }

    public function pause(Request $request, BackupJobGroup $backupGroup)
    {
        // Conditional update, not a read-then-write: RunBackupGroup flips the group
        // ACTIVE→RUNNING with a matching `where status = active` guard when it starts
        // a run, so pausing atomically only from a non-running state prevents the
        // worker from later un-pausing a group paused in that race window.
        $paused = BackupJobGroup::query()
            ->whereKey($backupGroup->id)
            ->where('status', '!=', BackupJobGroup::STATUS_RUNNING)
            ->update([
                'status' => BackupJobGroup::STATUS_PAUSED,
                'pause_reason' => $request->input('pause_reason', 'Paused manually.'),
            ]);

        if ($paused === 0) {
            // Flash rather than throw a validation error: the groups index (where a
            // stale Pause button lives) renders only flash banners, so an error-bag
            // message would leave the user with no visible explanation.
            return back()->with('error', 'A running group cannot be paused.');
        }

        return back()->with('success', 'Backup group paused.');
    }

    public function resume(BackupJobGroup $backupGroup, ResumeBackupJobGroup $resumeBackupJobGroup)
    {
        try {
            $resumeBackupJobGroup->handle($backupGroup);
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->errors()['group'][0]);
        }

        return back()->with('success', 'Backup group resumed.');
    }

    public function toggleNotifications(Request $request, BackupJobGroup $backupGroup, UpdateBackupJobGroup $updateBackupJobGroup)
    {
        try {
            $updateBackupJobGroup->setNotificationsEnabled($backupGroup, $request->boolean('notifications_enabled'));
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->errors()['group'][0]);
        }

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
            ...($request->has('notification_channel_ids') ? [
                'notification_channel_ids' => $request->input('notification_channel_ids', []),
            ] : []),
        ];
    }

    private function updatePayload(BackupJobGroupRequest $request): array
    {
        $payload = [
            'name' => $request->input('name'),
            'schedule_type' => $request->input('schedule_type'),
            'schedule_config' => $request->normalizedScheduleConfig(),
            'timezone' => $request->filled('timezone') ? $request->input('timezone') : null,
            'failure_policy' => $request->input('failure_policy'),
        ];

        if ($request->has('notifications_enabled')) {
            $payload['notifications_enabled'] = $request->boolean('notifications_enabled');
        }

        if ($request->has('notification_channel_ids')) {
            $payload['notification_channel_ids'] = $request->input('notification_channel_ids');
        }

        return $payload;
    }

    private function serializeGroup(BackupJobGroup $group, bool $withMembers = false): array
    {
        $data = [
            ...$group->toArray(),
            ...$group->runAvailability(),
            'notification_channel_ids' => $group->relationLoaded('notificationChannels')
                ? $group->notificationChannels->pluck('id')->values()->all()
                : $group->notificationChannels()->pluck('notification_channels.id')->values()->all(),
            'members_count' => $group->members_count ?? $group->members()->count(),
            'schedule_summary' => $this->scheduleCalculator->summary($group->schedule_type, $group->schedule_config ?? []),
        ];

        unset($data['members']);

        if ($withMembers) {
            $data['members'] = $group->members->map(fn (BackupJob $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'docker_host_id' => $member->docker_host_id,
                'docker_host' => $member->dockerHost ? app(AgentExecution::class)->summary($member->dockerHost) : null,
                'source_label' => $member->sourceName(),
                'source_type' => $member->sourceType(),
                'status' => $member->status,
                'destination' => $member->destination?->name,
                'last_success_at' => $member->last_success_at,
                'last_error' => $member->last_error,
            ])->values()->all();
        }

        return $data;
    }
}
