<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import { formatBytes } from '@/Composables/useFormatBytes';

interface PaginatedData<T> {
    data: T[];
    meta: { current_page: number; per_page: number; total: number; last_page: number };
}

const props = defineProps<{
    group: any;
    lastSuccessfulGroupRun?: any | null;
    runs: PaginatedData<any>;
}>();

const page = usePage();
const can = page.props.can as { runDockerActions?: boolean };
const { t, formatDate } = useI18n();
const failurePolicyLabel = (policy: string) => policy === 'stop' ? t('Stop at first failure') : t('Continue, report failure');
const runNow = (id: number) => router.post(`/backup-groups/${id}/run`);
const pause = (id: number) => router.post(`/backup-groups/${id}/pause`);
const resume = (id: number) => router.post(`/backup-groups/${id}/resume`);
const destroyGroup = (id: number) => confirm(t('Delete this backup group? Detach its jobs first.')) && router.delete(`/backup-groups/${id}`);
</script>

<template>
    <Head :title="group.name" />
    <AppLayout :title="group.name" :subtitle="t('Review schedule, members, run history, and actions for this group.')">
        <template #actions>
            <div class="flex flex-wrap gap-2">
                <button v-if="can.runDockerActions" class="btn-primary" :disabled="group.status !== 'active'" @click="runNow(group.id)">{{ t('Run now') }}</button>
                <button v-if="can.runDockerActions && (group.status === 'paused' || group.status === 'error')" class="btn-secondary" @click="resume(group.id)">{{ t('Resume') }}</button>
                <button v-else-if="can.runDockerActions" class="btn-secondary" :disabled="group.status === 'running'" @click="pause(group.id)">{{ t('Pause') }}</button>
                <Link v-if="can.runDockerActions" :href="`/backup-groups/${group.id}/edit`" class="btn-secondary">{{ t('Edit') }}</Link>
                <button v-if="can.runDockerActions" type="button" class="btn-danger" @click="destroyGroup(group.id)">{{ t('Delete') }}</button>
            </div>
        </template>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card p-4 sm:p-5 lg:col-span-2">
                <h2 class="mb-4 text-lg font-semibold">{{ t('Group info') }}</h2>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Status') }}</dt><dd class="mt-1"><StatusBadge :status="group.status" /></dd></div>
                    <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Schedule') }}</dt><dd class="mt-1 break-words text-white">{{ group.schedule_summary }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Failure policy') }}</dt><dd class="mt-1 text-white">{{ failurePolicyLabel(group.failure_policy) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Notifications') }}</dt><dd class="mt-1 text-white">{{ group.notifications_enabled ? t('Enabled') : t('Disabled') }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Members') }}</dt><dd class="mt-1 text-white">{{ group.members_count }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Last run') }}</dt><dd class="mt-1 text-white">{{ formatDate(group.last_run_at) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Next run') }}</dt><dd class="mt-1 text-white">{{ formatDate(group.next_run_at) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Last backup size') }}</dt><dd class="mt-1 text-white">{{ formatBytes(lastSuccessfulGroupRun?.total_backup_size_bytes, t('Unknown')) }}</dd></div>
                </dl>
            </section>
            <section class="card p-4 sm:p-5">
                <h2 class="mb-3 text-lg font-semibold">{{ t('Last error') }}</h2>
                <p v-if="group.last_error" class="break-words rounded-xl bg-rose-400/10 p-3 text-sm text-rose-100">{{ group.last_error }}</p>
                <p v-else class="text-sm text-slate-400">{{ t('No current error.') }}</p>
            </section>
        </div>

        <section class="card mt-6 p-4 sm:p-5">
            <h2 class="mb-4 text-lg font-semibold">{{ t('Members') }}</h2>
            <div v-if="group.members.length" class="divide-y divide-white/10 rounded-xl border border-white/10">
                <div v-for="member in group.members" :key="member.id" class="flex items-center justify-between gap-3 p-3 text-sm">
                    <div class="min-w-0">
                        <Link :href="`/backup-jobs/${member.id}`" class="break-words font-medium text-sky-300 hover:text-sky-200">{{ member.name }}</Link>
                        <p class="mt-1 break-all text-slate-400">{{ member.source_label }}</p>
                        <p class="mt-1 text-slate-500">{{ member.destination || t('Missing') }} · {{ t('Last success') }}: {{ formatDate(member.last_success_at) }}</p>
                    </div>
                    <StatusBadge :status="member.status" />
                </div>
            </div>
            <p v-else class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-3 text-sm text-amber-100">{{ t('No jobs in this group yet. Create or edit a backup job and attach it to this group.') }}</p>
        </section>

        <section class="card mt-6 overflow-hidden">
            <div class="border-b border-white/10 p-4 sm:p-5">
                <h2 class="text-lg font-semibold">{{ t('Run history') }}</h2>
            </div>
            <div v-if="runs.data.length">
                <div class="divide-y divide-white/10 md:hidden">
                    <article v-for="run in runs.data" :key="run.id" class="space-y-3 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <StatusBadge :status="run.status" />
                            <Link :href="`/backup-group-runs/${run.id}`" class="text-sm text-sky-300 hover:text-sky-200">{{ t('View details') }}</Link>
                        </div>
                        <dl class="grid grid-cols-2 gap-3 text-sm">
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Trigger') }}</dt><dd class="mt-1 text-slate-200">{{ t(run.trigger) }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Volumes') }}</dt><dd class="mt-1 text-slate-200">{{ t('{ok}/{total} volumes', { ok: run.succeeded_members, total: run.total_members }) }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Duration') }}</dt><dd class="mt-1 text-slate-200">{{ run.duration_seconds ?? '-' }}s</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Size') }}</dt><dd class="mt-1 text-slate-200">{{ formatBytes(run.total_backup_size_bytes, t('Unknown')) }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Initiated by') }}</dt><dd class="mt-1 text-slate-200">{{ run.initiated_by?.name ?? '—' }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Started') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(run.started_at) }}</dd></div>
                        </dl>
                    </article>
                </div>
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-white/10 text-sm">
                        <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr><th class="px-4 py-3">{{ t('Status') }}</th><th class="px-4 py-3">{{ t('Trigger') }}</th><th class="px-4 py-3">{{ t('Volumes') }}</th><th class="px-4 py-3">{{ t('Initiated by') }}</th><th class="px-4 py-3">{{ t('Started') }}</th><th class="px-4 py-3">{{ t('Duration') }}</th><th class="px-4 py-3">{{ t('Size') }}</th><th class="px-4 py-3">{{ t('Details') }}</th></tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            <tr v-for="run in runs.data" :key="run.id">
                                <td class="px-4 py-3"><StatusBadge :status="run.status" /></td>
                                <td class="px-4 py-3 text-slate-300">{{ t(run.trigger) }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ t('{ok}/{total} volumes', { ok: run.succeeded_members, total: run.total_members }) }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ run.initiated_by?.name ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ formatDate(run.started_at) }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ run.duration_seconds ?? '-' }}s</td>
                                <td class="px-4 py-3 text-slate-300">{{ formatBytes(run.total_backup_size_bytes, t('Unknown')) }}</td>
                                <td class="px-4 py-3"><Link :href="`/backup-group-runs/${run.id}`" class="text-sky-300 hover:text-sky-200">{{ t('View details') }}</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :data="runs" :base-url="`/backup-groups/${group.id}`" page-param="runs_page" />
            </div>
            <p v-else class="p-5 text-sm text-slate-400">{{ t('No group runs yet.') }}</p>
        </section>
    </AppLayout>
</template>
