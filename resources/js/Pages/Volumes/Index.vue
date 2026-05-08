<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import ActionIcon from '@/Components/ActionIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import { computed, ref } from 'vue';
import { initialSearchFromUrl, matchesSearch, uniqueSortedOptions, useListFilters } from '@/Composables/useListFilters';

const props = defineProps<{ volumes: any[] }>();

const page = usePage();
const can = page.props.can as { runDockerActions?: boolean };
const { t, formatDate } = useI18n();
const search = ref(initialSearchFromUrl());
const statusFilter = ref('');
const driverFilter = ref('');
const backupFilter = ref('');
const filtersVisible = ref(false);
const { hasActiveFilters, resetFilters } = useListFilters([search, statusFilter, driverFilter, backupFilter]);
const { activeFilterCount: activeAdvancedFilterCount } = useListFilters([statusFilter, driverFilter, backupFilter]);

const drivers = computed(() => uniqueSortedOptions(props.volumes, (volume) => volume.driver || t('Unknown')));

const filteredVolumes = computed(() => {
    return props.volumes.filter((volume) => {
        const driver = volume.driver || t('Unknown');

        return matchesSearch([volume.name, driver, volume.mountpoint], search.value)
            && (!statusFilter.value || (statusFilter.value === 'present' ? volume.exists : !volume.exists))
            && (!driverFilter.value || driver === driverFilter.value)
            && (!backupFilter.value || (backupFilter.value === 'with_jobs' ? volume.related_jobs_count > 0 : volume.related_jobs_count === 0));
    });
});

const jobsHref = (volumeName: string) => `/backup-jobs?search=${encodeURIComponent(volumeName)}`;
const sync = () => router.post('/volumes/sync');
</script>

<template>
    <Head :title="t('Volumes')" />
    <AppLayout :title="t('Docker volumes')" :subtitle="t('Inspect discovered Docker volumes and start backup jobs from the volumes that matter.')">
        <template #actions>
            <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-center sm:justify-end">
                <div v-if="volumes.length" class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                    <input v-model="search" class="input sm:w-72" :aria-label="t('Search')" :placeholder="t('Search volumes, drivers, paths')">
                    <div class="flex items-center gap-3">
                        <p class="whitespace-nowrap text-sm text-slate-400">{{ t('{count} results', { count: filteredVolumes.length }) }}</p>
                        <button type="button" class="btn-secondary gap-2" :aria-expanded="filtersVisible" :aria-label="filtersVisible ? t('Hide filters') : t('Show filters')" @click="filtersVisible = !filtersVisible">
                            <span>{{ t('Filters') }}</span>
                            <span v-if="activeAdvancedFilterCount" class="rounded-full bg-sky-400/20 px-2 py-0.5 text-xs text-sky-100">{{ activeAdvancedFilterCount }}</span>
                            <span class="h-2 w-2 border-b-2 border-r-2 border-current transition" :class="filtersVisible ? 'rotate-[225deg]' : 'rotate-45'" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
                <button v-if="can.runDockerActions" class="btn-primary" @click="sync">{{ t('Sync volumes') }}</button>
            </div>
        </template>

        <div v-if="volumes.length && filtersVisible" class="card mb-4 p-4">
            <div class="grid gap-3 md:grid-cols-3">
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
                    <span class="label">{{ t('Backup coverage') }}</span>
                    <select v-model="backupFilter" class="input">
                        <option value="">{{ t('Any backup coverage') }}</option>
                        <option value="with_jobs">{{ t('With backup jobs') }}</option>
                        <option value="without_jobs">{{ t('Without backup jobs') }}</option>
                    </select>
                </label>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <button type="button" class="btn-secondary" :disabled="!hasActiveFilters" @click="resetFilters">{{ t('Reset filters') }}</button>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div v-if="volumes.length">
                <div v-if="filteredVolumes.length" class="divide-y divide-white/10 md:hidden">
                    <article v-for="volume in filteredVolumes" :key="volume.id" class="space-y-4 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="break-all font-semibold text-white">{{ volume.name }}</h2>
                                <p class="mt-1 text-sm text-slate-400">{{ volume.driver || t('Unknown') }}</p>
                            </div>
                            <StatusBadge :status="volume.exists ? 'active' : 'error'" />
                        </div>
                        <dl class="text-sm">
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Last seen') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(volume.last_seen_at) }}</dd></div>
                        </dl>
                        <div class="flex flex-wrap gap-2">
                            <ActionIcon v-if="can.runDockerActions" :label="t('Create backup job')" icon="archive" :href="`/backup-jobs/create?volume=${encodeURIComponent(volume.name)}`" />
                            <ActionIcon :label="t('View jobs ({count})', { count: volume.related_jobs_count })" icon="eye" :href="jobsHref(volume.name)" />
                        </div>
                    </article>
                </div>
                <div v-if="filteredVolumes.length" class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-white/10 text-sm">
                    <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-3">{{ t('Name') }}</th>
                            <th class="px-4 py-3">{{ t('Driver') }}</th>
                            <th class="px-4 py-3">{{ t('Status') }}</th>
                            <th class="px-4 py-3">{{ t('Last seen') }}</th>
                            <th class="px-4 py-3">{{ t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <tr v-for="volume in filteredVolumes" :key="volume.id" class="hover:bg-white/[0.03]">
                            <td class="px-4 py-3 font-medium text-white">{{ volume.name }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ volume.driver || t('Unknown') }}</td>
                            <td class="px-4 py-3"><StatusBadge :status="volume.exists ? 'active' : 'error'" /></td>
                            <td class="px-4 py-3 text-slate-300">{{ formatDate(volume.last_seen_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-2">
                                    <ActionIcon v-if="can.runDockerActions" :label="t('Create backup job')" icon="archive" :href="`/backup-jobs/create?volume=${encodeURIComponent(volume.name)}`" />
                                    <ActionIcon :label="t('View jobs ({count})', { count: volume.related_jobs_count })" icon="eye" :href="jobsHref(volume.name)" />
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
