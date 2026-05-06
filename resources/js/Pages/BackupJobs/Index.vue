<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import ActionIcon from '@/Components/ActionIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

defineProps<{ jobs: any[] }>();

const page = usePage();
const can = page.props.can as { runDockerActions?: boolean };
const { t, formatDate } = useI18n();
const destroyJob = (id: number) => confirm(t('Delete this backup job and its run history?')) && router.delete(`/backup-jobs/${id}`);
const runNow = (id: number) => router.post(`/backup-jobs/${id}/run`);
const pause = (id: number) => router.post(`/backup-jobs/${id}/pause`);
const resume = (id: number) => router.post(`/backup-jobs/${id}/resume`);
</script>

<template>
    <Head :title="t('Backup jobs')" />
    <AppLayout :title="t('Backup jobs')" :subtitle="t('Schedule, pause, run, and restore Docker volume backups from one place.')">
        <template #actions>
            <Link v-if="can.runDockerActions" href="/backup-jobs/create" class="btn-primary">{{ t('New backup job') }}</Link>
        </template>

        <div class="card overflow-hidden">
            <div v-if="jobs.length">
                <div class="divide-y divide-white/10 md:hidden">
                    <article v-for="job in jobs" :key="job.id" class="space-y-4 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="break-words font-semibold text-white">{{ job.name }}</h2>
                                <p class="mt-1 break-all text-sm text-slate-400">{{ job.volume_name }}</p>
                            </div>
                            <StatusBadge :status="job.status" />
                        </div>
                        <dl class="grid gap-3 text-sm">
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Destination') }}</dt><dd class="mt-1 break-words text-slate-200">{{ job.destination?.name || t('Missing') }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Schedule') }}</dt><dd class="mt-1 break-words text-slate-200">{{ job.schedule_summary }}</dd></div>
                            <div class="grid grid-cols-2 gap-3">
                                <div><dt class="text-xs uppercase text-slate-500">{{ t('Last run') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(job.last_run_at) }}</dd></div>
                                <div><dt class="text-xs uppercase text-slate-500">{{ t('Next run') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(job.next_run_at) }}</dd></div>
                            </div>
                        </dl>
                        <div class="flex flex-wrap gap-2">
                            <ActionIcon v-if="can.runDockerActions" :label="t('Run now')" icon="play" :disabled="job.status !== 'active'" @click="runNow(job.id)" />
                            <ActionIcon v-if="can.runDockerActions && (job.status === 'paused' || job.status === 'error')" :label="t('Resume')" icon="play" @click="resume(job.id)" />
                            <ActionIcon v-else-if="can.runDockerActions" :label="t('Pause')" icon="pause" :disabled="job.status === 'running'" @click="pause(job.id)" />
                            <ActionIcon v-if="can.runDockerActions" :label="t('Restore')" icon="restore" :href="`/backup-jobs/${job.id}/restore`" />
                            <ActionIcon :label="t('View')" icon="eye" :href="`/backup-jobs/${job.id}`" />
                            <ActionIcon v-if="can.runDockerActions" :label="t('Edit')" icon="edit" :href="`/backup-jobs/${job.id}/edit`" />
                            <ActionIcon v-if="can.runDockerActions" :label="t('Delete')" icon="delete" variant="danger" @click="destroyJob(job.id)" />
                        </div>
                    </article>
                </div>
                <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-white/10 text-sm">
                    <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-3">{{ t('Name') }}</th>
                            <th class="px-4 py-3">{{ t('Volume') }}</th>
                            <th class="px-4 py-3">{{ t('Destination') }}</th>
                            <th class="px-4 py-3">{{ t('Schedule') }}</th>
                            <th class="px-4 py-3">{{ t('Status') }}</th>
                            <th class="px-4 py-3">{{ t('Last run') }}</th>
                            <th class="px-4 py-3">{{ t('Next run') }}</th>
                            <th class="px-4 py-3">{{ t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <tr v-for="job in jobs" :key="job.id" class="hover:bg-white/[0.03]">
                            <td class="px-4 py-3 font-medium text-white">{{ job.name }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ job.volume_name }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ job.destination?.name || t('Missing') }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ job.schedule_summary }}</td>
                            <td class="px-4 py-3"><StatusBadge :status="job.status" /></td>
                            <td class="px-4 py-3 text-slate-300">{{ formatDate(job.last_run_at) }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ formatDate(job.next_run_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex md:min-w-52 flex-wrap gap-2">
                                    <ActionIcon v-if="can.runDockerActions" :label="t('Run now')" icon="play" :disabled="job.status !== 'active'" @click="runNow(job.id)" />
                                    <ActionIcon v-if="can.runDockerActions && (job.status === 'paused' || job.status === 'error')" :label="t('Resume')" icon="play" @click="resume(job.id)" />
                                    <ActionIcon v-else-if="can.runDockerActions" :label="t('Pause')" icon="pause" :disabled="job.status === 'running'" @click="pause(job.id)" />
                                    <ActionIcon v-if="can.runDockerActions" :label="t('Restore')" icon="restore" :href="`/backup-jobs/${job.id}/restore`" />
                                    <ActionIcon :label="t('View')" icon="eye" :href="`/backup-jobs/${job.id}`" />
                                    <ActionIcon v-if="can.runDockerActions" :label="t('Edit')" icon="edit" :href="`/backup-jobs/${job.id}/edit`" />
                                    <ActionIcon v-if="can.runDockerActions" :label="t('Delete')" icon="delete" variant="danger" @click="destroyJob(job.id)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
            <div v-else class="p-10 text-center">
                <p class="text-lg font-semibold">{{ t('No backup jobs yet.') }}</p>
                <p class="mt-2 text-sm text-slate-400">{{ t('Sync volumes, add a destination, then create your first scheduled backup.') }}</p>
                <Link v-if="can.runDockerActions" href="/backup-jobs/create" class="btn-primary mt-5">{{ t('Create backup job') }}</Link>
            </div>
        </div>
    </AppLayout>
</template>
