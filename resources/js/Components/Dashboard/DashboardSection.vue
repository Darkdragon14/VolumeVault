<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import { formatBytes } from '@/Composables/useFormatBytes';

defineProps<{
    sectionKey: string;
    recentBackupRuns: any[];
    recentGroupRuns: any[];
    recentRestoreRuns: any[];
    jobsWithErrors: any[];
    groupsWithErrors: any[];
}>();

const { t, formatDate } = useI18n();
const can = usePage().props.can as { runDockerActions?: boolean };
</script>

<template>
    <section v-if="sectionKey === 'recent_backups'" class="card h-full p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">{{ t('Recent backup runs') }}</h2>
            <Link href="/backup-jobs" class="text-sm text-sky-300 hover:text-sky-200">{{ t('View jobs') }}</Link>
        </div>
        <div v-if="recentBackupRuns.length" class="space-y-3">
            <Link v-for="run in recentBackupRuns" :key="run.id" :href="`/backup-runs/${run.id}`" class="flex flex-col gap-2 rounded-xl bg-white/5 px-4 py-3 hover:bg-slate-100 dark:hover:bg-white/10 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="break-words font-medium">{{ run.job?.name || t('Backup run #{id}', { id: run.id }) }}</p>
                    <p class="text-xs text-slate-400">{{ formatDate(run.started_at || run.created_at) }} <span v-if="run.backup_size_bytes">/ {{ formatBytes(run.backup_size_bytes) }}</span></p>
                </div>
                <StatusBadge :status="run.status" />
            </Link>
        </div>
        <p v-else class="rounded-xl border border-dashed border-white/10 p-4 text-sm text-slate-400">{{ t('No backup runs yet.') }}</p>
    </section>

    <section v-else-if="sectionKey === 'recent_group_runs'" class="card h-full p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">{{ t('Recent group runs') }}</h2>
            <Link href="/backup-groups" class="text-sm text-sky-300 hover:text-sky-200">{{ t('View groups') }}</Link>
        </div>
        <div v-if="recentGroupRuns.length" class="space-y-3">
            <Link v-for="run in recentGroupRuns" :key="run.id" :href="`/backup-group-runs/${run.id}`" class="flex flex-col gap-2 rounded-xl bg-white/5 px-4 py-3 hover:bg-slate-100 dark:hover:bg-white/10 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="break-words font-medium">{{ run.group?.name || t('Group run #{id}', { id: run.id }) }}</p>
                    <p class="text-xs text-slate-400">{{ formatDate(run.started_at || run.created_at) }} <span v-if="run.total_members">/ {{ t('{ok}/{total} volumes', { ok: run.succeeded_members, total: run.total_members }) }}</span> <span v-if="run.total_backup_size_bytes">/ {{ formatBytes(run.total_backup_size_bytes) }}</span></p>
                </div>
                <StatusBadge :status="run.status" />
            </Link>
        </div>
        <p v-else class="rounded-xl border border-dashed border-white/10 p-4 text-sm text-slate-400">{{ t('No group runs yet.') }}</p>
    </section>

    <section v-else-if="sectionKey === 'recent_restores'" class="card h-full p-5">
        <h2 class="mb-4 text-lg font-semibold">{{ t('Recent restore runs') }}</h2>
        <div v-if="recentRestoreRuns.length" class="space-y-3">
            <Link v-for="run in recentRestoreRuns" :key="run.id" :href="`/restore-runs/${run.id}`" class="flex flex-col gap-2 rounded-xl bg-white/5 px-4 py-3 hover:bg-slate-100 dark:hover:bg-white/10 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="break-all font-medium">{{ run.source_volume_name }} to {{ run.target_volume_name }}</p>
                    <p class="text-xs text-slate-400">{{ formatDate(run.started_at || run.created_at) }}</p>
                </div>
                <StatusBadge :status="run.status" />
            </Link>
        </div>
        <p v-else class="rounded-xl border border-dashed border-white/10 p-4 text-sm text-slate-400">{{ t('No restore runs yet.') }}</p>
    </section>

    <section v-else-if="sectionKey === 'jobs_with_errors'" class="card h-full p-5">
        <h2 class="mb-4 text-lg font-semibold">{{ t('Jobs with errors') }}</h2>
        <div v-if="jobsWithErrors.length" class="space-y-3">
            <Link v-for="job in jobsWithErrors" :key="job.id" :href="`/backup-jobs/${job.id}`" class="block rounded-xl bg-rose-400/10 px-4 py-3 hover:bg-rose-400/15">
                <p class="break-words font-medium text-rose-100">{{ job.name }}</p>
                <p class="break-words text-sm text-rose-200/80">{{ job.last_error || t('Unknown error') }}</p>
            </Link>
        </div>
        <p v-else class="text-sm text-slate-400">{{ t('No jobs are currently in error.') }}</p>
    </section>

    <section v-else-if="sectionKey === 'groups_with_errors'" class="card h-full p-5">
        <h2 class="mb-4 text-lg font-semibold">{{ t('Groups with errors') }}</h2>
        <div v-if="groupsWithErrors.length" class="space-y-3">
            <template v-for="group in groupsWithErrors" :key="group.id">
                <Link v-if="can.runDockerActions" :href="`/backup-groups/${group.id}/edit`" class="block rounded-xl bg-rose-400/10 px-4 py-3 hover:bg-rose-400/15">
                    <p class="break-words font-medium text-rose-100">{{ group.name }}</p>
                    <p class="break-words text-sm text-rose-200/80">{{ group.last_error || t('Unknown error') }}</p>
                </Link>
                <div v-else class="block rounded-xl bg-rose-400/10 px-4 py-3">
                    <p class="break-words font-medium text-rose-100">{{ group.name }}</p>
                    <p class="break-words text-sm text-rose-200/80">{{ group.last_error || t('Unknown error') }}</p>
                </div>
            </template>
        </div>
        <p v-else class="text-sm text-slate-400">{{ t('No groups are currently in error.') }}</p>
    </section>
</template>
