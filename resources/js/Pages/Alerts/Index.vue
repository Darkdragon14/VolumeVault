<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from '@/i18n';
import { readFiltersFromUrl } from '@/Composables/useListFilters';

interface PaginatedData<T> {
    data: T[];
    meta: { current_page: number; per_page: number; total: number; last_page: number };
}

const props = defineProps<{
    alerts: PaginatedData<any>;
    defaultPerPage: number;
}>();

const page = usePage();
const can = page.props.can as { manageSensitiveData?: boolean };
const activeAlertCount = page.props.activeAlertCount as number;
const { t, formatDate } = useI18n();
const search = ref('');
const typeFilter = ref('');
const statusFilter = ref('');
const severityFilter = ref('');
const filtersVisible = ref(false);

readFiltersFromUrl({ search, type: typeFilter, status: statusFilter, severity: severityFilter });

const types = ['backup_too_old', 'job_never_succeeded', 'job_in_error_too_long', 'backup_size_out_of_range', 'destination_storage_limit', 'agent_offline'];
const statuses = ['active', 'resolved'];
const severities = ['warning', 'critical'];

const subjectLabel = (alert: any) => alert.subject?.name || alert.subject?.source || t('Unknown');

const applyFilters = () => {
    router.get('/alerts', {
        search: search.value || undefined,
        type: typeFilter.value || undefined,
        status: statusFilter.value || undefined,
        severity: severityFilter.value || undefined,
        per_page: props.alerts.meta.per_page === 0 ? 'all' : props.alerts.meta.per_page,
    }, { preserveState: true, replace: true });
};

let searchTimeout: ReturnType<typeof setTimeout>;
const onSearchInput = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 300);
};

const resetFilters = () => {
    search.value = '';
    typeFilter.value = '';
    statusFilter.value = '';
    severityFilter.value = '';
    applyFilters();
};

const viewAlert = (id: number) => router.visit(`/alerts/${id}`);
const onAlertKeydown = (event: KeyboardEvent, id: number) => {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        viewAlert(id);
    }
};
</script>

<template>
    <Head :title="t('Alerts')" />
    <AppLayout :title="t('Alerts')" :subtitle="t('Monitor backup health, job failures, stale backups, and unusual archive sizes.')">
        <template #actions>
            <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-center sm:justify-end">
                <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                    <input v-model="search" class="input sm:w-72" data-list-search :aria-label="t('Search')" :placeholder="t('Search alerts, jobs, messages')" @input="onSearchInput">
                    <div class="flex items-center gap-3">
                        <button type="button" class="btn-secondary gap-2" :aria-expanded="filtersVisible" :aria-label="filtersVisible ? t('Hide filters') : t('Show filters')" @click="filtersVisible = !filtersVisible">
                            <span>{{ t('Filters') }}</span>
                            <span class="h-2 w-2 border-b-2 border-r-2 border-current transition" :class="filtersVisible ? 'rotate-[225deg]' : 'rotate-45'" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
                <Link v-if="can.manageSensitiveData" href="/alerts/settings" class="btn-primary shrink-0">{{ t('Alert settings') }}</Link>
            </div>
        </template>

        <div class="mb-6 grid gap-4 md:grid-cols-3">
            <div class="card p-5">
                <p class="text-sm text-slate-400">{{ t('Active alerts') }}</p>
                <p class="mt-2 text-3xl font-bold text-white">{{ activeAlertCount }}</p>
            </div>
            <div class="card p-5 md:col-span-2">
                <h2 class="text-lg font-semibold">{{ t('Alert timeline') }}</h2>
                <p class="mt-2 text-sm text-slate-400">{{ t('Resolved alerts stay visible here, while each trigger and resolution is kept in the alert history.') }}</p>
            </div>
        </div>

        <div v-if="filtersVisible" class="card mb-4 p-4">
            <div class="grid gap-3 md:grid-cols-3">
                <label class="space-y-1">
                    <span class="label">{{ t('Type') }}</span>
                    <select v-model="typeFilter" class="input" @change="applyFilters">
                        <option value="">{{ t('All types') }}</option>
                        <option v-for="type in types" :key="type" :value="type">{{ t(type) }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="label">{{ t('Status') }}</span>
                    <select v-model="statusFilter" class="input" @change="applyFilters">
                        <option value="">{{ t('All statuses') }}</option>
                        <option v-for="status in statuses" :key="status" :value="status">{{ t(status) }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="label">{{ t('Severity') }}</span>
                    <select v-model="severityFilter" class="input" @change="applyFilters">
                        <option value="">{{ t('All severities') }}</option>
                        <option v-for="severity in severities" :key="severity" :value="severity">{{ t(severity) }}</option>
                    </select>
                </label>
            </div>
            <button type="button" class="btn-secondary mt-3" @click="resetFilters">{{ t('Reset filters') }}</button>
        </div>

        <div class="card overflow-hidden">
            <div v-if="alerts.data.length">
                <div class="divide-y divide-white/10 md:hidden">
                    <article v-for="alert in alerts.data" :key="alert.id" class="cursor-pointer space-y-4 p-4 transition hover:bg-slate-100 dark:hover:bg-white/[0.03]" role="link" tabindex="0" @click="viewAlert(alert.id)" @keydown="onAlertKeydown($event, alert.id)">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="break-words font-semibold text-white">{{ t(alert.type) }}</h2>
                                <p class="mt-1 break-words text-sm text-slate-400">{{ subjectLabel(alert) }}</p>
                            </div>
                            <div class="flex flex-wrap justify-end gap-2">
                                <StatusBadge :status="alert.severity" />
                                <StatusBadge :status="alert.status" />
                            </div>
                        </div>
                        <p class="break-words text-sm text-slate-300">{{ alert.message }}</p>
                        <dl class="grid grid-cols-2 gap-3 text-sm">
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Last triggered') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(alert.last_triggered_at) }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Resolved') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(alert.resolved_at, 'None') }}</dd></div>
                        </dl>
                    </article>
                </div>
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-white/10 text-sm">
                        <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-4 py-3">{{ t('Type') }}</th>
                                <th class="px-4 py-3">{{ t('Subject') }}</th>
                                <th class="px-4 py-3">{{ t('Severity') }}</th>
                                <th class="px-4 py-3">{{ t('Status') }}</th>
                                <th class="px-4 py-3">{{ t('Last triggered') }}</th>
                                <th class="px-4 py-3">{{ t('Resolved') }}</th>
                                <th class="px-4 py-3">{{ t('Message') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            <tr v-for="alert in alerts.data" :key="alert.id" class="cursor-pointer hover:bg-slate-100 dark:hover:bg-white/[0.03]" role="link" tabindex="0" @click="viewAlert(alert.id)" @keydown="onAlertKeydown($event, alert.id)">
                                <td class="px-4 py-3 font-medium text-white">{{ t(alert.type) }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ subjectLabel(alert) }}</td>
                                <td class="px-4 py-3"><StatusBadge :status="alert.severity" /></td>
                                <td class="px-4 py-3"><StatusBadge :status="alert.status" /></td>
                                <td class="px-4 py-3 text-slate-300">{{ formatDate(alert.last_triggered_at) }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ formatDate(alert.resolved_at, 'None') }}</td>
                                <td class="max-w-md truncate px-4 py-3 text-slate-300">{{ alert.message }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :data="alerts" base-url="/alerts" :extra-params="{ search: search || undefined, type: typeFilter || undefined, status: statusFilter || undefined, severity: severityFilter || undefined }" />
            </div>
            <div v-else class="p-10 text-center">
                <p class="text-lg font-semibold">{{ t('No alerts yet.') }}</p>
                <p class="mt-2 text-sm text-slate-400">{{ t('Enable alert rules to start monitoring backup health.') }}</p>
                <Link v-if="can.manageSensitiveData" href="/alerts/settings" class="btn-primary mt-5">{{ t('Alert settings') }}</Link>
            </div>
        </div>
    </AppLayout>
</template>
