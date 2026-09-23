<?php

namespace App\Actions\Backup;

use App\Actions\Runs\CreateRunFinalizations;
use App\Actions\Runs\DispatchQueuedRun;
use App\Actions\Runs\ProcessRunFinalization;
use App\Models\AgentOperation;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\RunFinalization;
use App\Services\Agents\HostWorkAdmission;
use Illuminate\Support\Facades\DB;

/**
 * Durable central counterpart of RunBackupGroup for remote/mixed groups.
 * Every tick derives counters from the frozen ordered children, selects at most
 * one member, then publishes outside the transaction. The minute dispatcher can
 * repeat any tick after a crash; queue claims and agent assignments own execution.
 */
class AdvanceBackupGroupRun
{
    /** Only the persisted current member may be published or claimed. */
    public static function authorizes(BackupRun $run): bool
    {
        return BackupGroupRun::query()->whereKey($run->backup_group_run_id)
            ->whereNotNull('member_run_ids')->where('status', BackupGroupRun::STATUS_RUNNING)
            ->whereDoesntHave('finalizations', fn ($query) => $query
                ->where('type', RunFinalization::TYPE_STARTED_NOTIFICATION)
                ->whereIn('status', [RunFinalization::STATUS_PENDING, RunFinalization::STATUS_PROCESSING]))
            ->where('current_member_run_id', $run->id)->exists();
    }

    public function handle(BackupGroupRun $run): void
    {
        $decision = app(WithBackupGroupMutationLocks::class)->handle([$run->backup_job_group_id], function ($groups) use ($run): array {
            $group = $groups->get($run->backup_job_group_id);
            $run = BackupGroupRun::query()->lockForUpdate()->find($run->id);
            if (! $group || ! $run || $run->member_run_ids === null || ! in_array($run->status, ['queued', 'running'], true)) {
                return [];
            }
            if ($run->status === 'queued') {
                if ($group->status === BackupJobGroup::STATUS_PAUSED) {
                    $run->memberRuns()->where('status', 'queued')->update(['status' => 'cancelled', 'finished_at' => now()]);
                    $run->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();

                    return [];
                }
                if (app(HostWorkAdmission::class)->claim($run, ['started_at' => now(), 'last_heartbeat_at' => now()]) !== 1) {
                    return [];
                }
                $run->refresh();
                $group->forceFill(['status' => BackupJobGroup::STATUS_RUNNING, 'last_run_at' => $run->started_at])->save();
                app(CreateRunFinalizations::class)->createGroupStartNotifications($run, $group);
            }

            $children = $run->memberRuns()->get()->keyBy('id');
            $succeeded = 0;
            $failed = 0;
            $next = null;
            foreach ($run->member_run_ids as $id) {
                if ($failed > 0 && $run->failure_policy_snapshot === BackupJobGroup::FAILURE_POLICY_STOP) {
                    break;
                }
                $child = $children->get($id);
                if ($child?->status === 'queued' && $child->job?->status === BackupJob::STATUS_PAUSED) {
                    BackupRun::whereKey($child->id)->where('status', 'queued')->update([
                        'status' => 'cancelled', 'finished_at' => now(), 'error_message' => 'Member was paused before its turn.',
                    ]);
                    $child->refresh();
                    if ($child->status === 'cancelled') {
                        AgentOperation::where('backup_run_id', $child->id)->where('status', 'pending')->update(['status' => 'cancelled', 'completed_at' => now()]);
                    }
                }
                if ($child && ($child->docker_container_cleanup_pending || ! empty($child->stopped_container_ids) || $child->status === 'running')) {
                    $next = $child;
                    break;
                }
                if ($child?->status === 'queued') {
                    $next = $child;
                    break;
                }
                if ($child?->status === 'success') {
                    $succeeded++;
                } else {
                    $failed++;
                }
            }
            $run->forceFill([
                'current_member_run_id' => $next?->id,
                'succeeded_members' => $succeeded,
                'failed_members' => $failed,
                'last_heartbeat_at' => now(),
            ])->save();
            $finalizations = [];
            if ($next === null) {
                $run->memberRuns()->where('status', 'queued')->update([
                    'status' => 'cancelled', 'finished_at' => now(), 'error_message' => 'Skipped by the group failure policy.',
                ]);
                $run->forceFill([
                    'status' => $failed > 0 ? 'failed' : 'success', 'finished_at' => now(),
                    'duration_seconds' => $run->started_at->diffInSeconds(now()),
                ])->save();
                $group->forceFill([
                    'status' => $failed > 0 ? BackupJobGroup::STATUS_ERROR : BackupJobGroup::STATUS_ACTIVE,
                    'last_success_at' => $failed > 0 ? $group->last_success_at : now(),
                    'last_error' => $failed > 0 ? $failed.' of '.$run->total_members.' volume(s) failed to back up.' : null,
                    'last_error_at' => $failed > 0 ? now() : null,
                ])->save();
                $finalizations = app(CreateRunFinalizations::class)->createGroupNotifications($run, $group);
            }

            return compact('run', 'next', 'finalizations');
        });

        if ($decision === []) {
            return;
        }
        DB::afterCommit(function () use ($decision): void {
            foreach ($decision['run']->finalizations()->where('type', RunFinalization::TYPE_STARTED_NOTIFICATION)->pluck('id') as $id) {
                app(ProcessRunFinalization::class)->handle($id);
            }
            app(ProcessRunFinalization::class)->dispatch($decision['finalizations']);
            if ($decision['next']?->status === 'queued') {
                app(DispatchQueuedRun::class)->handle($decision['next']);
            }
        });
    }
}
