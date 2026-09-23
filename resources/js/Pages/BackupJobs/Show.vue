<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import HostScope from '@/Components/HostScope.vue';
import HostIdentity from '@/Components/HostIdentity.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useDeployment } from '@/Composables/useDeployment';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import { formatBytes } from '@/Composables/useFormatBytes';
import { computed, ref } from 'vue';

interface PaginatedData<T> {
    data: T[];
    meta: { current_page: number; per_page: number; total: number; last_page: number };
}

const props = defineProps<{
    hosts: any[];
    filters: { docker_host_id: number | null };
    job: any;
    lastSuccessfulBackup?: any | null;
    runs: PaginatedData<any>;
    restoreRuns: PaginatedData<any>;
}>();

const activeTab = ref<'runs' | 'restores'>('runs');

const { canManageBackups, localExecutionEnabled } = useDeployment();
const canManageJob = computed(() => canManageBackups.value && (Number(props.job.docker_host_id ?? 1) !== 1 || localExecutionEnabled.value));
const jobHost = computed(() => props.hosts?.find(host => host.id === props.job.docker_host_id));
const canRunJob = computed(() => jobHost.value?.canBackup === true);
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
                <button v-if="canManageJob && !job.backup_job_group_id" class="btn-primary" :disabled="job.status !== 'active' || !canRunJob" @click="runNow(job.id)">{{ t('Run now') }}</button>
                <button v-if="canManageJob && (job.status === 'paused' || job.status === 'error')" class="btn-secondary" :disabled="!canRunJob" @click="resume(job.id)">{{ t('Resume') }}</button>
                <button v-else-if="canManageJob" class="btn-secondary" :disabled="job.status === 'running'" @click="pause(job.id)">{{ t('Pause') }}</button>
                <Link v-if="canManageBackups" :href="`/backup-jobs/${job.id}/restore`" class="btn-secondary">{{ t('Restore') }}</Link>
                <Link v-if="canManageJob && job.configuration_source !== 'docker_label'" :href="`/backup-jobs/${job.id}/edit`" class="btn-secondary">{{ t('Edit') }}</Link>
                <button v-if="canManageJob && job.configuration_source !== 'docker_label'" type="button" class="btn-danger" @click="destroyJob(job.id)">{{ t('Delete') }}</button>
            </div>
        </template>

        <p v-if="canManageJob && !canRunJob" role="status" class="mb-4 rounded-xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-700 dark:text-amber-200">{{ t('hostWorkflow.unavailable') }}</p>
        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card p-4 sm:p-5 lg:col-span-2">
                <h2 class="mb-4 text-lg font-semibold">{{ t('Job info') }}</h2>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('hostWorkflow.sourceHost') }}</dt><dd class="mt-1 break-words text-white"><HostIdentity :host="jobHost" :reason="jobHost?.backup_unavailable_reason" /></dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Status') }}</dt><dd class="mt-1"><StatusBadge :status="job.status" /></dd></div>
                    <div v-if="job.configuration_source === 'docker_label'"><dt class="text-xs uppercase text-slate-400">{{ t('Configuration') }}</dt><dd class="mt-1 text-sky-200">{{ t('Managed by Docker labels') }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Source type') }}</dt><dd class="mt-1 text-white">{{ sourceTypeLabel(job) }}</dd></div>
                    <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Source') }}</dt><dd class="mt-1 break-all text-white">{{ sourceLabel(job) }}</dd></div>
                    <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Destination') }}</dt><dd class="mt-1 break-words text-white">{{ job.destination?.name }}</dd></div>
                    <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Schedule') }}</dt><dd class="mt-1 break-words text-white">{{ job.schedule_summary }}</dd></div>
                    <div v-if="job.backup_filter_mode === 'include'"><dt class="text-xs uppercase text-slate-400">{{ t('Included paths') }}</dt><dd class="mt-1 break-all font-mono text-sm text-white">{{ job.backup_include_paths || t('Everything') }}</dd></div>
                    <div v-else><dt class="text-xs uppercase text-slate-400">{{ t('Excluded files') }}</dt><dd class="mt-1 break-all font-mono text-sm text-white">{{ job.backup_exclude_regexp || t('None') }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Last run') }}</dt><dd class="mt-1 text-white">{{ formatDate(job.last_run_at) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Next run') }}</dt><dd class="mt-1 text-white">{{ job.backup_job_group_id ? t('Managed by group') : formatDate(job.next_run_at) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Last backup size') }}</dt><dd class="mt-1 text-white">{{ formatBytes(lastSuccessfulBackup?.backup_size_bytes, t('Unknown')) }}</dd></div>
                </dl>
            </section>
            <section class="card p-4 sm:p-5">
                <h2 class="mb-3 text-lg font-semibold">{{ t('Last error') }}</h2>
                <p v-if="job.label_reconciliation_error || job.last_error" class="break-words rounded-xl bg-rose-400/10 p-3 text-sm text-rose-100">{{ job.label_reconciliation_error || job.last_error }}</p>
                <p v-else class="text-sm text-slate-400">{{ t('No current error.') }}</p>
            </section>
        </div>

        <HostScope :hosts="hosts" :filters="filters" preserve-state />
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
                            <HostIdentity :host="run.docker_host" />
                            <p class="break-all text-sm text-slate-400">{{ run.source_name }}</p>
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
                                <td class="px-4 py-3"><StatusBadge :status="run.status" /><HostIdentity :host="run.docker_host" /><p class="break-all text-xs text-slate-400">{{ run.source_name }}</p></td>
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
                    <Pagination :data="runs" :base-url="`/backup-jobs/${job.id}`" page-param="runs_page" :extra-params="{ docker_host_id: filters?.docker_host_id ?? undefined }" />
                </div>
                <p v-else class="p-5 text-sm text-slate-400">{{ t('No runs yet.') }}</p>
            </div>

            <div v-show="activeTab === 'restores'" role="tabpanel">
                <div v-if="restoreRuns.data.length">
                    <div class="divide-y divide-white/10 md:hidden">
                        <article v-for="run in restoreRuns.data" :key="run.id" class="space-y-3 p-4">
                            <p class="text-xs text-slate-400">{{ t('hostWorkflow.sourceHost') }}</p><HostIdentity :host="run.source_docker_host" />
                            <p class="text-xs text-slate-400">{{ t('hostWorkflow.targetHost') }}</p><HostIdentity :host="run.target_docker_host" />
                            <div class="flex items-center justify-between gap-3">
                                <StatusBadge :status="run.status" />
                                <Link :href="`/restore-runs/${run.id}`" class="text-sm text-sky-300 hover:text-sky-200">{{ t('View details') }}</Link>
                            </div>
                            <dl class="grid grid-cols-2 gap-3 text-sm">
                                <div><dt class="text-xs uppercase text-slate-500">{{ t('Mode') }}</dt><dd class="mt-1 text-slate-200">{{ t(run.mode) }}</dd></div>
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
                                <td class="px-4 py-3 text-slate-300">{{ t(run.mode) }}</td>
                                <td class="px-4 py-3 break-all text-slate-300">{{ run.source_volume_name }}<HostIdentity :host="run.source_docker_host" /></td>
                                <td class="px-4 py-3 break-all text-slate-300">{{ run.target_volume_name }}<HostIdentity :host="run.target_docker_host" /></td>
                                <td class="px-4 py-3 text-slate-300">{{ run.initiated_by?.name ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ formatDate(run.started_at) }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ run.duration_seconds ?? '-' }}s</td>
                                <td class="px-4 py-3"><Link :href="`/restore-runs/${run.id}`" class="text-sky-300 hover:text-sky-200">{{ t('View details') }}</Link></td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                    <Pagination :data="restoreRuns" :base-url="`/backup-jobs/${job.id}`" page-param="restores_page" :extra-params="{ docker_host_id: filters?.docker_host_id ?? undefined }" />
                </div>
                <p v-else class="p-5 text-sm text-slate-400">{{ t('No restores yet.') }}</p>
            </div>
        </section>
    </AppLayout>
</template>
