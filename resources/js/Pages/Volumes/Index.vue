<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import ActionIcon from '@/Components/ActionIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import { computed, ref } from 'vue';
import { matchesSearch, readFiltersFromUrl, uniqueSortedOptions, useListFilters, useUrlFilters } from '@/Composables/useListFilters';
import { formatBytes } from '@/Composables/useFormatBytes';

const props = defineProps<{ volumes: any[]; hosts: any[]; currentHostId: number | null }>();

const page = usePage();
const can = page.props.can as { runDockerActions?: boolean };
const { t, formatDate } = useI18n();
const search = ref('');
const statusFilter = ref('');
const driverFilter = ref('');
const stackFilter = ref('');
const backupFilter = ref('');
const hostFilter = ref(props.currentHostId ? String(props.currentHostId) : '');
const filtersVisible = ref(false);

readFiltersFromUrl({ search, status: statusFilter, driver: driverFilter, stack: stackFilter, backup_status: backupFilter });
useUrlFilters({ search, status: statusFilter, driver: driverFilter, stack: stackFilter, backup_status: backupFilter }, { debounce: 300 });

const { hasActiveFilters, resetFilters } = useListFilters([search, statusFilter, driverFilter, stackFilter, backupFilter]);
const { activeFilterCount: activeAdvancedFilterCount } = useListFilters([statusFilter, driverFilter, stackFilter, backupFilter]);

const drivers = computed(() => uniqueSortedOptions(props.volumes, (volume) => volume.driver || t('Unknown')));
const stacks = computed(() => uniqueSortedOptions(props.volumes, (volume) => volume.stack_name || t('No stack')));
const showHostFilter = computed(() => props.hosts.length > 1);

const filteredVolumes = computed(() => {
    return props.volumes.filter((volume) => {
        const driver = volume.driver || t('Unknown');
        const stack = volume.stack_name || t('No stack');

        return matchesSearch([volume.name, driver, stack, volume.mountpoint, volume.host?.name], search.value)
            && (!statusFilter.value || (statusFilter.value === 'present' ? volume.exists : !volume.exists))
            && (!driverFilter.value || driver === driverFilter.value)
            && (!stackFilter.value || stack === stackFilter.value)
            && (!backupFilter.value || volume.backup_state === backupFilter.value);
    });
});

const backupStateLabel = (state: string) => ({
    backed_up: t('Backed up'),
    configured: t('Pending backup'),
    unprotected: t('Unprotected'),
}[state] || t('Unknown'));

const backupStateClass = (state: string) => ({
    backed_up: 'border-emerald-300/30 bg-emerald-300/10 text-emerald-100',
    configured: 'border-amber-300/30 bg-amber-300/10 text-amber-100',
    unprotected: 'border-rose-300/30 bg-rose-300/10 text-rose-100',
}[state] || 'border-slate-300/20 bg-slate-300/10 text-slate-200');

const jobsHref = (volume: any) => `/backup-jobs?search=${encodeURIComponent(volume.name)}${showHostFilter.value ? `&host_id=${encodeURIComponent(volume.host_id)}` : ''}`;
const createJobHref = (volume: any) => `/backup-jobs/create?volume=${encodeURIComponent(volume.name)}&host_id=${encodeURIComponent(volume.host_id)}`;
const sync = () => router.post('/volumes/sync', showHostFilter.value ? (hostFilter.value ? { host_id: hostFilter.value } : { all_hosts: true }) : {});
const applyHostFilter = () => router.get('/volumes', hostFilter.value ? { host_id: hostFilter.value } : {}, { preserveScroll: true });
</script>

<template>
    <Head :title="t('Volumes')" />
    <AppLayout :title="t('Docker volumes')" :subtitle="t('Inspect discovered Docker volumes and start backup jobs from the volumes that matter.')">
        <template #actions>
            <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-center sm:justify-end">
                <div v-if="volumes.length || showHostFilter" class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                    <input v-if="volumes.length" v-model="search" class="input sm:w-72" data-list-search :aria-label="t('Search')" :placeholder="t('Search volumes, stacks, drivers, paths')">
                    <div class="flex items-center gap-3">
                        <p v-if="volumes.length" class="whitespace-nowrap text-sm text-slate-400">{{ t('{count} results', { count: filteredVolumes.length }) }}</p>
                        <button type="button" class="btn-secondary gap-2" :aria-expanded="filtersVisible" :aria-label="filtersVisible ? t('Hide filters') : t('Show filters')" @click="filtersVisible = !filtersVisible">
                            <span>{{ t('Filters') }}</span>
                            <span v-if="activeAdvancedFilterCount" class="rounded-full bg-sky-400/20 px-2 py-0.5 text-xs text-sky-100">{{ activeAdvancedFilterCount }}</span>
                            <span class="h-2 w-2 border-b-2 border-r-2 border-current transition" :class="filtersVisible ? 'rotate-[225deg]' : 'rotate-45'" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
                <button v-if="can.runDockerActions" class="btn-primary shrink-0" @click="sync">{{ t('Sync volumes') }}</button>
            </div>
        </template>

        <div v-if="(volumes.length || showHostFilter) && filtersVisible" class="card mb-4 p-4">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <label v-if="showHostFilter" class="space-y-1">
                    <span class="label">{{ t('Host') }}</span>
                    <select v-model="hostFilter" class="input" @change="applyHostFilter">
                        <option value="">{{ t('All hosts') }}</option>
                        <option v-for="host in hosts" :key="host.id" :value="host.id">{{ host.name }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="label">{{ t('Status') }}</span>
                    <select v-model="statusFilter" class="input">
                        <option value="">{{ t('All statuses') }}</option>
                        <option value="present">{{ t('Present') }}</option>
                        <option value="missing">{{ t('Missing') }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="label">{{ t('Driver') }}</span>
                    <select v-model="driverFilter" class="input">
                        <option value="">{{ t('All drivers') }}</option>
                        <option v-for="driver in drivers" :key="driver" :value="driver">{{ driver }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="label">{{ t('Stack') }}</span>
                    <select v-model="stackFilter" class="input">
                        <option value="">{{ t('All stacks') }}</option>
                        <option v-for="stack in stacks" :key="stack" :value="stack">{{ stack }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="label">{{ t('Backup status') }}</span>
                    <select v-model="backupFilter" class="input">
                        <option value="">{{ t('Any backup status') }}</option>
                        <option value="backed_up">{{ t('Backed up') }}</option>
                        <option value="configured">{{ t('Pending backup') }}</option>
                        <option value="unprotected">{{ t('Unprotected') }}</option>
                    </select>
                </label>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <button type="button" class="btn-secondary" :disabled="!hasActiveFilters" @click="resetFilters">{{ t('Reset filters') }}</button>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div v-if="volumes.length">
                <div v-if="filteredVolumes.length" class="space-y-3 p-3 md:hidden">
                    <article v-for="volume in filteredVolumes" :key="volume.id" class="space-y-4 rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="break-all font-semibold text-white">{{ volume.name }}</h2>
                                <p class="mt-1 break-words text-sm text-slate-400">{{ volume.stack_name || t('No stack') }}</p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-2">
                                <StatusBadge :status="volume.exists ? 'active' : 'error'" />
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold" :class="backupStateClass(volume.backup_state)">{{ backupStateLabel(volume.backup_state) }}</span>
                            </div>
                        </div>
                        <dl class="grid gap-3 text-sm">
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Driver') }}</dt><dd class="mt-1 break-words text-slate-200">{{ volume.driver || t('Unknown') }}</dd></div>
                            <div v-if="volume.last_backup_at"><dt class="text-xs uppercase text-slate-500">{{ t('Last backup') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(volume.last_backup_at) }} / {{ formatBytes(volume.last_backup_size_bytes, t('Unknown')) }}</dd></div>
                            <div v-else><dt class="text-xs uppercase text-slate-500">{{ t('Backup jobs') }}</dt><dd class="mt-1 text-slate-200">{{ volume.related_jobs_count }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Last seen') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(volume.last_seen_at) }}</dd></div>
                        </dl>
                        <div class="flex flex-wrap gap-2">
                            <ActionIcon v-if="can.runDockerActions && volume.host?.type === 'local'" :label="t('Create backup job')" icon="archive" :href="createJobHref(volume)" />
                            <ActionIcon :label="t('View jobs ({count})', { count: volume.related_jobs_count })" icon="eye" :href="jobsHref(volume)" />
                        </div>
                    </article>
                </div>
                <div v-if="filteredVolumes.length" class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-white/10 text-sm">
                        <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-4 py-3">{{ t('Name') }}</th>
                                <th class="px-4 py-3">{{ t('Stack') }}</th>
                                <th class="px-4 py-3">{{ t('Driver') }}</th>
                                <th class="px-4 py-3">{{ t('Status') }}</th>
                                <th class="px-4 py-3">{{ t('Backup') }}</th>
                                <th class="px-4 py-3">{{ t('Last seen') }}</th>
                                <th class="px-4 py-3">{{ t('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            <tr v-for="volume in filteredVolumes" :key="volume.id" class="hover:bg-slate-100 dark:hover:bg-white/[0.03]">
                                <td class="px-4 py-3 font-medium text-white">{{ volume.name }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ volume.stack_name || t('No stack') }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ volume.driver || t('Unknown') }}</td>
                                <td class="px-4 py-3"><StatusBadge :status="volume.exists ? 'active' : 'error'" /></td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold" :class="backupStateClass(volume.backup_state)">{{ backupStateLabel(volume.backup_state) }}</span>
                                    <p v-if="volume.last_backup_at" class="mt-1 whitespace-nowrap text-xs text-slate-400">{{ formatDate(volume.last_backup_at) }} / {{ formatBytes(volume.last_backup_size_bytes, t('Unknown')) }}</p>
                                    <p v-else class="mt-1 whitespace-nowrap text-xs text-slate-400">{{ t('Backup jobs: {count}', { count: volume.related_jobs_count }) }}</p>
                                </td>
                                <td class="px-4 py-3 text-slate-300">{{ formatDate(volume.last_seen_at) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <ActionIcon v-if="can.runDockerActions && volume.host?.type === 'local'" :label="t('Create backup job')" icon="archive" :href="createJobHref(volume)" />
                                        <ActionIcon :label="t('View jobs ({count})', { count: volume.related_jobs_count })" icon="eye" :href="jobsHref(volume)" />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!filteredVolumes.length" class="p-10 text-center">
                    <p class="text-lg font-semibold">{{ t('No matching results.') }}</p>
                    <button type="button" class="btn-secondary mt-5" @click="resetFilters">{{ t('Reset filters') }}</button>
                </div>
            </div>
            <div v-else class="p-10 text-center">
                <p class="text-lg font-semibold">{{ t('No Docker volumes found.') }}</p>
                <p class="mt-2 text-sm text-slate-400">{{ t('Make sure VolumeVault can access the Docker socket.') }}</p>
                <button v-if="can.runDockerActions" class="btn-primary mt-5" @click="sync">{{ t('Sync volumes') }}</button>
            </div>
        </div>
    </AppLayout>
</template>
