<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import ActionIcon from '@/Components/ActionIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import { ref } from 'vue';
import { readFiltersFromUrl } from '@/Composables/useListFilters';

interface PaginatedData<T> {
    data: T[];
    meta: { current_page: number; per_page: number; total: number; last_page: number };
}

const props = defineProps<{
    jobs: PaginatedData<any>;
    defaultPerPage: number;
}>();

const page = usePage();
const can = page.props.can as { runDockerActions?: boolean };
const { t, formatDate, timezone } = useI18n();
const search = ref('');
const statusFilter = ref('');
const destinationFilter = ref('');
const sort = ref('created_at');
const direction = ref('desc');
const filtersVisible = ref(false);

readFiltersFromUrl({ search, status: statusFilter, destination: destinationFilter, sort, direction });

const sortFields = ['created_at', 'name', 'next_run_at', 'last_run_at'];
if (!sortFields.includes(sort.value)) {
    sort.value = 'created_at';
    direction.value = 'desc';
} else if (!['asc', 'desc'].includes(direction.value)) {
    direction.value = 'desc';
}

const statuses = ['active', 'paused', 'error', 'running'];
const sourceLabel = (job: any) => job.source_label || job.host_path || job.volume_name || t('Unknown');

const applyFilters = () => {
    router.get('/backup-jobs', {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        destination: destinationFilter.value || undefined,
        sort: sort.value,
        direction: direction.value,
        per_page: props.jobs.meta.per_page === 0 ? 'all' : props.jobs.meta.per_page,
    }, { preserveState: true, replace: true });
};

let searchTimeout: ReturnType<typeof setTimeout>;
const onSearchInput = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 300);
};

const resetFilters = () => {
    search.value = '';
    statusFilter.value = '';
    destinationFilter.value = '';
    applyFilters();
};

const sortBy = (field: string) => {
    if (sort.value === field) {
        direction.value = direction.value === 'asc' ? 'desc' : 'asc';
    } else {
        sort.value = field;
        direction.value = field === 'last_run_at' ? 'desc' : 'asc';
    }

    applyFilters();
};

const ariaSort = (field: string) => sort.value === field ? (direction.value === 'asc' ? 'ascending' : 'descending') : undefined;

const destroyJob = (id: number) => confirm(t('Delete this backup job and its run history?')) && router.delete(`/backup-jobs/${id}`);
const runNow = (id: number) => router.post(`/backup-jobs/${id}/run`);
const pause = (id: number) => router.post(`/backup-jobs/${id}/pause`);
const resume = (id: number) => router.post(`/backup-jobs/${id}/resume`);
const viewJob = (id: number) => router.visit(`/backup-jobs/${id}`);
const onJobKeydown = (event: KeyboardEvent, id: number) => {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        viewJob(id);
    }
};
</script>

<template>
    <Head :title="t('Backup jobs')" />
    <AppLayout :title="t('Backup jobs')" :subtitle="t('Schedule, pause, run, and restore Docker volume or host path backups from one place.')">
        <template #title-actions>
            <ActionIcon v-if="can.runDockerActions" :label="t('New backup job')" icon="add" href="/backup-jobs/create" />
        </template>

        <template #actions>
            <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-center sm:justify-end">
                <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                    <input v-model="search" class="input sm:w-72" data-list-search :aria-label="t('Search')" :placeholder="t('Search jobs, sources, destinations')" @input="onSearchInput">
                    <div class="flex items-center gap-3">
                        <button type="button" class="btn-secondary gap-2" :aria-expanded="filtersVisible" :aria-label="filtersVisible ? t('Hide filters') : t('Show filters')" @click="filtersVisible = !filtersVisible">
                            <span>{{ t('Filters') }}</span>
                            <span class="h-2 w-2 border-b-2 border-r-2 border-current transition" :class="filtersVisible ? 'rotate-[225deg]' : 'rotate-45'" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
                <Link v-if="can.runDockerActions" href="/backup-jobs/create" class="btn-primary hidden shrink-0 gap-2 px-3 sm:inline-flex">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 5v14" />
                        <path d="M5 12h14" />
                    </svg>
                    <span class="whitespace-nowrap">{{ t('New backup job') }}</span>
                </Link>
            </div>
        </template>

        <p class="mb-3 text-sm text-slate-400">{{ t('Times are shown in {timezone}.', { timezone }) }}</p>

        <div v-if="filtersVisible" class="card mb-4 p-4">
            <div class="grid gap-3 md:grid-cols-2">
                <label class="space-y-1">
                    <span class="label">{{ t('Status') }}</span>
                    <select v-model="statusFilter" class="input" @change="applyFilters">
                        <option value="">{{ t('All statuses') }}</option>
                        <option v-for="status in statuses" :key="status" :value="status">{{ t(status) }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="label">{{ t('Destination') }}</span>
                    <input v-model="destinationFilter" class="input" :placeholder="t('Filter by destination')" @input="onSearchInput">
                </label>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <button type="button" class="btn-secondary" @click="resetFilters">{{ t('Reset filters') }}</button>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div v-if="jobs.data.length">
                <div class="grid grid-cols-2 gap-3 border-b border-white/10 p-4 md:hidden">
                    <label class="space-y-1">
                        <span class="label">{{ t('Sort by') }}</span>
                        <select v-model="sort" class="input" @change="applyFilters">
                            <option value="created_at">{{ t('Recently created') }}</option>
                            <option value="name">{{ t('Name') }}</option>
                            <option value="next_run_at">{{ t('Next run') }}</option>
                            <option value="last_run_at">{{ t('Last run') }}</option>
                        </select>
                    </label>
                    <label class="space-y-1">
                        <span class="label">{{ t('Direction') }}</span>
                        <select v-model="direction" class="input" @change="applyFilters">
                            <option value="asc">{{ t('Ascending') }}</option>
                            <option value="desc">{{ t('Descending') }}</option>
                        </select>
                    </label>
                </div>
                <div class="divide-y divide-white/10 md:hidden">
                    <article v-for="job in jobs.data" :key="job.id" class="space-y-4 p-4 cursor-pointer transition hover:bg-slate-100 dark:hover:bg-white/[0.03]" role="link" tabindex="0" @click="viewJob(job.id)" @keydown="onJobKeydown($event, job.id)">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="break-words font-semibold text-white">{{ job.name }}</h2>
                                <p class="mt-1 break-all text-sm text-slate-400">{{ sourceLabel(job) }}</p>
                            </div>
                            <StatusBadge :status="job.status" />
                        </div>
                        <dl class="grid gap-3 text-sm">
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Destination') }}</dt><dd class="mt-1 break-words text-slate-200">{{ job.destination?.name || t('Missing') }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Schedule') }}</dt><dd class="mt-1 break-words text-slate-200">{{ job.schedule_summary }}</dd></div>
                            <div class="grid grid-cols-2 gap-3">
                                <div><dt class="text-xs uppercase text-slate-500">{{ t('Last run') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(job.last_run_at) }}</dd></div>
                                <div><dt class="text-xs uppercase text-slate-500">{{ t('Next run') }}</dt><dd class="mt-1 text-slate-200">{{ job.backup_job_group_id ? t('Managed by group') : formatDate(job.next_run_at) }}</dd></div>
                            </div>
                        </dl>
                        <div class="flex flex-wrap gap-2" @click.stop @keydown.stop>
                            <ActionIcon v-if="can.runDockerActions && !job.backup_job_group_id" :label="t('Run now')" icon="play" :disabled="job.status !== 'active'" @click="runNow(job.id)" />
                            <ActionIcon v-if="can.runDockerActions && (job.status === 'paused' || job.status === 'error')" :label="t('Resume')" icon="play" @click="resume(job.id)" />
                            <ActionIcon v-else-if="can.runDockerActions" :label="t('Pause')" icon="pause" :disabled="job.status === 'running'" @click="pause(job.id)" />
                            <ActionIcon v-if="can.runDockerActions" :label="t('Restore')" icon="restore" :href="`/backup-jobs/${job.id}/restore`" />
                            <ActionIcon v-if="can.runDockerActions" :label="t('Edit')" icon="edit" :href="`/backup-jobs/${job.id}/edit`" />
                            <ActionIcon v-if="can.runDockerActions" :label="t('Delete')" icon="delete" variant="danger" @click="destroyJob(job.id)" />
                        </div>
                    </article>
                </div>
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-white/10 text-sm">
                        <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-4 py-3" :aria-sort="ariaSort('name')">
                                    <button type="button" class="inline-flex items-center gap-2 hover:text-white" @click="sortBy('name')">
                                        <span>{{ t('Name') }}</span>
                                        <svg v-if="sort === 'name'" class="h-3 w-3 transition" :class="direction === 'desc' ? 'rotate-180' : ''" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M6 10V2M2.5 5.5 6 2l3.5 3.5" /></svg>
                                        <svg v-else class="h-3 w-3 opacity-60" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 4 3-3 3 3M6 1v10m-3-3 3 3 3-3" /></svg>
                                    </button>
                                </th>
                                <th class="px-4 py-3">{{ t('Source') }}</th>
                                <th class="px-4 py-3">{{ t('Destination') }}</th>
                                <th class="px-4 py-3">{{ t('Schedule') }}</th>
                                <th class="px-4 py-3">{{ t('Status') }}</th>
                                <th class="px-4 py-3" :aria-sort="ariaSort('last_run_at')">
                                    <button type="button" class="inline-flex items-center gap-2 hover:text-white" @click="sortBy('last_run_at')">
                                        <span>{{ t('Last run') }}</span>
                                        <svg v-if="sort === 'last_run_at'" class="h-3 w-3 transition" :class="direction === 'desc' ? 'rotate-180' : ''" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M6 10V2M2.5 5.5 6 2l3.5 3.5" /></svg>
                                        <svg v-else class="h-3 w-3 opacity-60" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 4 3-3 3 3M6 1v10m-3-3 3 3 3-3" /></svg>
                                    </button>
                                </th>
                                <th class="px-4 py-3" :aria-sort="ariaSort('next_run_at')">
                                    <button type="button" class="inline-flex items-center gap-2 hover:text-white" @click="sortBy('next_run_at')">
                                        <span>{{ t('Next run') }}</span>
                                        <svg v-if="sort === 'next_run_at'" class="h-3 w-3 transition" :class="direction === 'desc' ? 'rotate-180' : ''" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M6 10V2M2.5 5.5 6 2l3.5 3.5" /></svg>
                                        <svg v-else class="h-3 w-3 opacity-60" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 4 3-3 3 3M6 1v10m-3-3 3 3 3-3" /></svg>
                                    </button>
                                </th>
                                <th class="px-4 py-3">{{ t('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            <tr v-for="job in jobs.data" :key="job.id" class="cursor-pointer hover:bg-slate-100 dark:hover:bg-white/[0.03]" role="link" tabindex="0" @click="viewJob(job.id)" @keydown="onJobKeydown($event, job.id)">
                                <td class="px-4 py-3 font-medium text-white">{{ job.name }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ sourceLabel(job) }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ job.destination?.name || t('Missing') }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ job.schedule_summary }}</td>
                                <td class="px-4 py-3"><StatusBadge :status="job.status" /></td>
                                <td class="px-4 py-3 text-slate-300">{{ formatDate(job.last_run_at) }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ job.backup_job_group_id ? t('Managed by group') : formatDate(job.next_run_at) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex md:min-w-52 flex-wrap gap-2" @click.stop @keydown.stop>
                                        <ActionIcon v-if="can.runDockerActions && !job.backup_job_group_id" :label="t('Run now')" icon="play" :disabled="job.status !== 'active'" @click="runNow(job.id)" />
                                        <ActionIcon v-if="can.runDockerActions && (job.status === 'paused' || job.status === 'error')" :label="t('Resume')" icon="play" @click="resume(job.id)" />
                                        <ActionIcon v-else-if="can.runDockerActions" :label="t('Pause')" icon="pause" :disabled="job.status === 'running'" @click="pause(job.id)" />
                                        <ActionIcon v-if="can.runDockerActions" :label="t('Restore')" icon="restore" :href="`/backup-jobs/${job.id}/restore`" />
                                        <ActionIcon v-if="can.runDockerActions" :label="t('Edit')" icon="edit" :href="`/backup-jobs/${job.id}/edit`" />
                                        <ActionIcon v-if="can.runDockerActions" :label="t('Delete')" icon="delete" variant="danger" @click="destroyJob(job.id)" />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :data="jobs" base-url="/backup-jobs" :extra-params="{ search: search || undefined, status: statusFilter || undefined, destination: destinationFilter || undefined, sort, direction }" />
            </div>
            <div v-else class="p-10 text-center">
                <p class="text-lg font-semibold">{{ t('No backup jobs yet.') }}</p>
                <p class="mt-2 text-sm text-slate-400">{{ t('Add a destination, then choose a Docker volume or host path for your first scheduled backup.') }}</p>
                <Link v-if="can.runDockerActions" href="/backup-jobs/create" class="btn-primary mt-5">{{ t('Create backup job') }}</Link>
            </div>
        </div>
    </AppLayout>
</template>
