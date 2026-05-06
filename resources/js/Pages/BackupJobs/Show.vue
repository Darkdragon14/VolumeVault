<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

defineProps<{ job: any; runs: any[] }>();

const page = usePage();
const can = page.props.can as { runDockerActions?: boolean };
const { t, formatDate } = useI18n();
const runNow = (id: number) => router.post(`/backup-jobs/${id}/run`);
const pause = (id: number) => router.post(`/backup-jobs/${id}/pause`);
const resume = (id: number) => router.post(`/backup-jobs/${id}/resume`);
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
            </div>
        </template>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card p-4 sm:p-5 lg:col-span-2">
                <h2 class="mb-4 text-lg font-semibold">{{ t('Job info') }}</h2>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Status') }}</dt><dd class="mt-1"><StatusBadge :status="job.status" /></dd></div>
                    <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Volume') }}</dt><dd class="mt-1 break-all text-white">{{ job.volume_name }}</dd></div>
                    <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Destination') }}</dt><dd class="mt-1 break-words text-white">{{ job.destination?.name }}</dd></div>
                    <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Schedule') }}</dt><dd class="mt-1 break-words text-white">{{ job.schedule_summary }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Excluded files') }}</dt><dd class="mt-1 break-all font-mono text-sm text-white">{{ job.backup_exclude_regexp || t('None') }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Last run') }}</dt><dd class="mt-1 text-white">{{ formatDate(job.last_run_at) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-400">{{ t('Next run') }}</dt><dd class="mt-1 text-white">{{ formatDate(job.next_run_at) }}</dd></div>
                </dl>
            </section>
            <section class="card p-4 sm:p-5">
                <h2 class="mb-3 text-lg font-semibold">{{ t('Last error') }}</h2>
                <p v-if="job.last_error" class="break-words rounded-xl bg-rose-400/10 p-3 text-sm text-rose-100">{{ job.last_error }}</p>
                <p v-else class="text-sm text-slate-400">{{ t('No current error.') }}</p>
            </section>
        </div>

        <section class="card mt-6 overflow-hidden">
            <div class="border-b border-white/10 p-5">
                <h2 class="text-lg font-semibold">{{ t('Run history') }}</h2>
            </div>
            <div v-if="runs.length">
                <div class="divide-y divide-white/10 md:hidden">
                    <article v-for="run in runs" :key="run.id" class="space-y-3 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <StatusBadge :status="run.status" />
                            <Link :href="`/backup-runs/${run.id}`" class="text-sm text-sky-300 hover:text-sky-200">{{ t('View logs') }}</Link>
                        </div>
                        <dl class="grid grid-cols-2 gap-3 text-sm">
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Trigger') }}</dt><dd class="mt-1 text-slate-200">{{ run.trigger }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Duration') }}</dt><dd class="mt-1 text-slate-200">{{ run.duration_seconds ?? '-' }}s</dd></div>
                            <div class="col-span-2"><dt class="text-xs uppercase text-slate-500">{{ t('Started') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(run.started_at) }}</dd></div>
                        </dl>
                    </article>
                </div>
                <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-white/10 text-sm">
                    <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr><th class="px-4 py-3">{{ t('Status') }}</th><th class="px-4 py-3">{{ t('Trigger') }}</th><th class="px-4 py-3">{{ t('Started') }}</th><th class="px-4 py-3">{{ t('Duration') }}</th><th class="px-4 py-3">{{ t('Logs') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <tr v-for="run in runs" :key="run.id">
                            <td class="px-4 py-3"><StatusBadge :status="run.status" /></td>
                            <td class="px-4 py-3 text-slate-300">{{ run.trigger }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ formatDate(run.started_at) }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ run.duration_seconds ?? '-' }}s</td>
                            <td class="px-4 py-3"><Link :href="`/backup-runs/${run.id}`" class="text-sky-300 hover:text-sky-200">{{ t('View logs') }}</Link></td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
            <p v-else class="p-5 text-sm text-slate-400">{{ t('No runs yet.') }}</p>
        </section>
    </AppLayout>
</template>
