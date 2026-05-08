<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import ActionIcon from '@/Components/ActionIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import { computed, ref } from 'vue';
import { initialSearchFromUrl, matchesSearch, uniqueSortedOptions, useListFilters } from '@/Composables/useListFilters';

const props = defineProps<{ jobs: any[] }>();

const page = usePage();
const can = page.props.can as { runDockerActions?: boolean };
const { t, formatDate, timezone } = useI18n();
const search = ref(initialSearchFromUrl());
const statusFilter = ref('');
const destinationFilter = ref('');
const scheduleFilter = ref('');
const runFilter = ref('');
const filtersVisible = ref(false);
const { hasActiveFilters, resetFilters } = useListFilters([search, statusFilter, destinationFilter, scheduleFilter, runFilter]);
const { activeFilterCount: activeAdvancedFilterCount } = useListFilters([statusFilter, destinationFilter, scheduleFilter, runFilter]);

const statuses = computed(() => uniqueSortedOptions(props.jobs, (job) => job.status));
const destinations = computed(() => uniqueSortedOptions(props.jobs, (job) => job.destination?.name || t('Missing')));
const schedules = computed(() => uniqueSortedOptions(props.jobs, (job) => job.schedule_type));

const filteredJobs = computed(() => {
    return props.jobs.filter((job) => {
        const destination = job.destination?.name || t('Missing');

        return matchesSearch([job.name, job.volume_name, destination], search.value)
            && (!statusFilter.value || job.status === statusFilter.value)
            && (!destinationFilter.value || destination === destinationFilter.value)
            && (!scheduleFilter.value || job.schedule_type === scheduleFilter.value)
            && (!runFilter.value || (runFilter.value === 'never' ? !job.last_run_at : !!job.last_run_at));
    });
});

const destroyJob = (id: number) => confirm(t('Delete this backup job and its run history?')) && router.delete(`/backup-jobs/${id}`);
const runNow = (id: number) => router.post(`/backup-jobs/${id}/run`);
const pause = (id: number) => router.post(`/backup-jobs/${id}/pause`);
const resume = (id: number) => router.post(`/backup-jobs/${id}/resume`);
</script>

<template>
    <Head :title="t('Backup jobs')" />
    <AppLayout :title="t('Backup jobs')" :subtitle="t('Schedule, pause, run, and restore Docker volume backups from one place.')">
        <template #actions>
            <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-center sm:justify-end">
                <div v-if="jobs.length" class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                    <input v-model="search" class="input sm:w-72" :aria-label="t('Search')" :placeholder="t('Search jobs, volumes, destinations')">
                    <div class="flex items-center gap-3">
                        <p class="whitespace-nowrap text-sm text-slate-400">{{ t('{count} results', { count: filteredJobs.length }) }}</p>
                        <button type="button" class="btn-secondary gap-2" :aria-expanded="filtersVisible" :aria-label="filtersVisible ? t('Hide filters') : t('Show filters')" @click="filtersVisible = !filtersVisible">
                            <span>{{ t('Filters') }}</span>
                            <span v-if="activeAdvancedFilterCount" class="rounded-full bg-sky-400/20 px-2 py-0.5 text-xs text-sky-100">{{ activeAdvancedFilterCount }}</span>
                            <span class="h-2 w-2 border-b-2 border-r-2 border-current transition" :class="filtersVisible ? 'rotate-[225deg]' : 'rotate-45'" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
                <Link v-if="can.runDockerActions" href="/backup-jobs/create" class="btn-primary">{{ t('New backup job') }}</Link>
            </div>
        </template>

        <p class="mb-3 text-sm text-slate-400">{{ t('Times are shown in {timezone}.', { timezone }) }}</p>

        <div v-if="jobs.length && filtersVisible" class="card mb-4 p-4">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <label class="space-y-1">
                    <span class="label">{{ t('Status') }}</span>
                    <select v-model="statusFilter" class="input">
                        <option value="">{{ t('All statuses') }}</option>
                        <option v-for="status in statuses" :key="status" :value="status">{{ t(status) }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="label">{{ t('Destination') }}</span>
                    <select v-model="destinationFilter" class="input">
                        <option value="">{{ t('All destinations') }}</option>
                        <option v-for="destination in destinations" :key="destination" :value="destination">{{ destination }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="label">{{ t('Schedule') }}</span>
                    <select v-model="scheduleFilter" class="input">
                        <option value="">{{ t('All schedules') }}</option>
                        <option v-for="schedule in schedules" :key="schedule" :value="schedule">{{ t(schedule) }}</option>
                    </select>
                </label>
            </div>
            <div class="mt-3 flex flex-wrap items-end gap-3">
                <label class="min-w-48 flex-1 space-y-1 md:flex-none">
                    <span class="label">{{ t('Run history') }}</span>
                    <select v-model="runFilter" class="input">
                        <option value="">{{ t('Any run history') }}</option>
                        <option value="never">{{ t('Never run') }}</option>
                        <option value="has_runs">{{ t('Has run') }}</option>
                    </select>
                </label>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <button type="button" class="btn-secondary" :disabled="!hasActiveFilters" @click="resetFilters">{{ t('Reset filters') }}</button>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div v-if="jobs.length">
                <div v-if="filteredJobs.length" class="divide-y divide-white/10 md:hidden">
                    <article v-for="job in filteredJobs" :key="job.id" class="space-y-4 p-4">
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
                <div v-if="filteredJobs.length" class="hidden overflow-x-auto md:block">
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
                        <tr v-for="job in filteredJobs" :key="job.id" class="hover:bg-white/[0.03]">
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
                <div v-if="!filteredJobs.length" class="p-10 text-center">
                    <p class="text-lg font-semibold">{{ t('No matching results.') }}</p>
                    <button type="button" class="btn-secondary mt-5" @click="resetFilters">{{ t('Reset filters') }}</button>
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
