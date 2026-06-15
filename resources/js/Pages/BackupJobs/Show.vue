<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import { formatBytes } from '@/Composables/useFormatBytes';
import { ref } from 'vue';

interface PaginatedData<T> {
    data: T[];
    meta: { current_page: number; per_page: number; total: number; last_page: number };
}

const props = defineProps<{
    job: any;
    lastSuccessfulBackup?: any | null;
    runs: PaginatedData<any>;
    restoreRuns: PaginatedData<any>;
}>();

const activeTab = ref<'runs' | 'restores'>('runs');

const page = usePage();
const can = page.props.can as { runDockerActions?: boolean };
const { t, formatDate } = useI18n();
const sourceLabel = (job: any) => job.source_label || job.host_path || job.volume_name || t('Unknown');
const sourceTypeLabel = (job: any) => job.source_type === 'host_path' ? t('Host path') : t('Docker volume');
const runNow = (id: number) => router.post(`/backup-jobs/${id}/run`);
const pause = (id: number) => router.post(`/backup-jobs/${id}/pause`);
const resume = (id: number) => router.post(`/backup-jobs/${id}/resume`);
const destroyJob = (id: number) => confirm(t('Delete this backup job and its run history?')) && router.delete(`/backup-jobs/${id}`);
</script>

<template>
    <Head :title="job.name" />
    <AppLayout :title="job.name" :subtitle="t('Review schedule, destination, run history, and recovery actions for this job.')">
        <template #actions>
            <div class="flex flex-wrap gap-2">
                <button v-if="can.runDockerActions" class="btn-primary" :disabled="job.status !== 'active'" @click="runNow(job.id)">{{ t('Run now') }}</button>
                <button v-if="can.runDockerActions && (job.status === 'paused' || job.status === 'error')" class="btn-secondary" @click="resume(job.id)">{{ t('Resume') }}</button>
                <button v-else-if="can.runDockerActions" class="btn-secondary" :disabled="job.status === 'running'" @click="pause(job.id)">{{ t('Pause') }}</button>
                <Link v-if="can.runDockerActions" :href="`/backup-jobs/${job.id}/restore`" class="btn-secondary">{{ t('Restore') }}</Link>
                <Link v-if="can.runDockerActions" :href="`/backup-jobs/${job.id}/edit`" class="btn-secondary">{{ t('Edit') }}</Link>
                <button v-if="can.runDockerActions" type="button" class="btn-danger" @click="destroyJob(job.id)">{{ t('Delete') }}</button>
            </div>
        </template>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card p-4 sm:p-5 lg:col-span-2">
                <h2 class="mb-4 text-lg font-semibold">{{ t('Job info') }}</h2>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Status') }}</dt><dd class="mt-1"><StatusBadge :status="job.status" /></dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Source type') }}</dt><dd class="mt-1 text-white">{{ sourceTypeLabel(job) }}</dd></div>
                    <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Source') }}</dt><dd class="mt-1 break-all text-white">{{ sourceLabel(job) }}</dd></div>
                    <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Destination') }}</dt><dd class="mt-1 break-words text-white">{{ job.destination?.name }}</dd></div>
                    <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Schedule') }}</dt><dd class="mt-1 break-words text-white">{{ job.schedule_summary }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Excluded files') }}</dt><dd class="mt-1 break-all font-mono text-sm text-white">{{ job.backup_exclude_regexp || t('None') }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Last run') }}</dt><dd class="mt-1 text-white">{{ formatDate(job.last_run_at) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Next run') }}</dt><dd class="mt-1 text-white">{{ formatDate(job.next_run_at) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Last backup size') }}</dt><dd class="mt-1 text-white">{{ formatBytes(lastSuccessfulBackup?.backup_size_bytes, t('Unknown')) }}</dd></div>
                </dl>
            </section>
            <section class="card p-4 sm:p-5">
                <h2 class="mb-3 text-lg font-semibold">{{ t('Last error') }}</h2>
                <p v-if="job.last_error" class="break-words rounded-xl bg-rose-400/10 p-3 text-sm text-rose-100">{{ job.last_error }}</p>
                <p v-else class="text-sm text-slate-400">{{ t('No current error.') }}</p>
            </section>
        </div>

        <section class="card mt-6 overflow-hidden">
            <div class="flex gap-1 border-b border-white/10 p-2" role="tablist">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'runs'"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition"
                    :class="activeTab === 'runs' ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white'"
                    @click="activeTab = 'runs'"
                >
                    {{ t('Run history') }}
                </button>
                <button
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'restores'"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition"
                    :class="activeTab === 'restores' ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white'"
                    @click="activeTab = 'restores'"
                >
                    {{ t('Restore history') }}
                </button>
            </div>

            <div v-show="activeTab === 'runs'" role="tabpanel">
                <div v-if="runs.data.length">
                    <div class="divide-y divide-white/10 md:hidden">
                        <article v-for="run in runs.data" :key="run.id" class="space-y-3 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <StatusBadge :status="run.status" />
                                <Link :href="`/backup-runs/${run.id}`" class="text-sm text-sky-300 hover:text-sky-200">{{ t('View logs') }}</Link>
                            </div>
                            <dl class="grid grid-cols-2 gap-3 text-sm">
                                <div><dt class="text-xs uppercase text-slate-500">{{ t('Trigger') }}</dt><dd class="mt-1 text-slate-200">{{ t(run.trigger) }}</dd></div>
                                <div><dt class="text-xs uppercase text-slate-500">{{ t('Duration') }}</dt><dd class="mt-1 text-slate-200">{{ run.duration_seconds ?? '-' }}s</dd></div>
                                <div><dt class="text-xs uppercase text-slate-500">{{ t('Size') }}</dt><dd class="mt-1 text-slate-200">{{ formatBytes(run.backup_size_bytes, t('Unknown')) }}</dd></div>
                                <div><dt class="text-xs uppercase text-slate-500">{{ t('Initiated by') }}</dt><dd class="mt-1 text-slate-200">{{ run.initiated_by?.name ?? '—' }}</dd></div>
                                <div class="col-span-2"><dt class="text-xs uppercase text-slate-500">{{ t('Started') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(run.started_at) }}</dd></div>
                            </dl>
                        </article>
                    </div>
                    <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-white/10 text-sm">
                        <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr><th class="px-4 py-3">{{ t('Status') }}</th><th class="px-4 py-3">{{ t('Trigger') }}</th><th class="px-4 py-3">{{ t('Initiated by') }}</th><th class="px-4 py-3">{{ t('Started') }}</th><th class="px-4 py-3">{{ t('Duration') }}</th><th class="px-4 py-3">{{ t('Size') }}</th><th class="px-4 py-3">{{ t('Logs') }}</th></tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            <tr v-for="run in runs.data" :key="run.id">
                                <td class="px-4 py-3"><StatusBadge :status="run.status" /></td>
                                <td class="px-4 py-3 text-slate-300">{{ t(run.trigger) }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ run.initiated_by?.name ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ formatDate(run.started_at) }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ run.duration_seconds ?? '-' }}s</td>
                                <td class="px-4 py-3 text-slate-300">{{ formatBytes(run.backup_size_bytes, t('Unknown')) }}</td>
                                <td class="px-4 py-3"><Link :href="`/backup-runs/${run.id}`" class="text-sky-300 hover:text-sky-200">{{ t('View logs') }}</Link></td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                    <Pagination :data="runs" :base-url="`/backup-jobs/${job.id}`" page-param="runs_page" />
                </div>
                <p v-else class="p-5 text-sm text-slate-400">{{ t('No runs yet.') }}</p>
            </div>

            <div v-show="activeTab === 'restores'" role="tabpanel">
                <div v-if="restoreRuns.data.length">
                    <div class="divide-y divide-white/10 md:hidden">
                        <article v-for="run in restoreRuns.data" :key="run.id" class="space-y-3 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <StatusBadge :status="run.status" />
                                <Link :href="`/restore-runs/${run.id}`" class="text-sm text-sky-300 hover:text-sky-200">{{ t('View details') }}</Link>
                            </div>
                            <dl class="grid grid-cols-2 gap-3 text-sm">
                                <div><dt class="text-xs uppercase text-slate-500">{{ t('Mode') }}</dt><dd class="mt-1 text-slate-200">{{ run.mode }}</dd></div>
                                <div><dt class="text-xs uppercase text-slate-500">{{ t('Duration') }}</dt><dd class="mt-1 text-slate-200">{{ run.duration_seconds ?? '-' }}s</dd></div>
                                <div class="min-w-0"><dt class="text-xs uppercase text-slate-500">{{ t('Source') }}</dt><dd class="mt-1 break-all text-slate-200">{{ run.source_volume_name }}</dd></div>
                                <div class="min-w-0"><dt class="text-xs uppercase text-slate-500">{{ t('Target') }}</dt><dd class="mt-1 break-all text-slate-200">{{ run.target_volume_name }}</dd></div>
                                <div class="col-span-2"><dt class="text-xs uppercase text-slate-500">{{ t('Initiated by') }}</dt><dd class="mt-1 text-slate-200">{{ run.initiated_by?.name ?? '—' }}</dd></div>
                                <div class="col-span-2"><dt class="text-xs uppercase text-slate-500">{{ t('Started') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(run.started_at) }}</dd></div>
                            </dl>
                        </article>
                    </div>
                    <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-white/10 text-sm">
                        <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr><th class="px-4 py-3">{{ t('Status') }}</th><th class="px-4 py-3">{{ t('Mode') }}</th><th class="px-4 py-3">{{ t('Source') }}</th><th class="px-4 py-3">{{ t('Target') }}</th><th class="px-4 py-3">{{ t('Initiated by') }}</th><th class="px-4 py-3">{{ t('Started') }}</th><th class="px-4 py-3">{{ t('Duration') }}</th><th class="px-4 py-3">{{ t('Details') }}</th></tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            <tr v-for="run in restoreRuns.data" :key="run.id">
                                <td class="px-4 py-3"><StatusBadge :status="run.status" /></td>
                                <td class="px-4 py-3 text-slate-300">{{ run.mode }}</td>
                                <td class="px-4 py-3 break-all text-slate-300">{{ run.source_volume_name }}</td>
                                <td class="px-4 py-3 break-all text-slate-300">{{ run.target_volume_name }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ run.initiated_by?.name ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ formatDate(run.started_at) }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ run.duration_seconds ?? '-' }}s</td>
                                <td class="px-4 py-3"><Link :href="`/restore-runs/${run.id}`" class="text-sky-300 hover:text-sky-200">{{ t('View details') }}</Link></td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                    <Pagination :data="restoreRuns" :base-url="`/backup-jobs/${job.id}`" page-param="restores_page" />
                </div>
                <p v-else class="p-5 text-sm text-slate-400">{{ t('No restores yet.') }}</p>
            </div>
        </section>
    </AppLayout>
</template>
