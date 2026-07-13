<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import ActionIcon from '@/Components/ActionIcon.vue';
import Pagination from '@/Components/Pagination.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import { ref } from 'vue';
import { readFiltersFromUrl } from '@/Composables/useListFilters';

interface PaginatedData<T> {
    data: T[];
    meta: { current_page: number; per_page: number; total: number; last_page: number };
}

const props = defineProps<{
    destinations: PaginatedData<any>;
    defaultPerPage: number;
}>();
const { t } = useI18n();
const search = ref('');

readFiltersFromUrl({ search });

const applyFilters = () => {
    router.get('/destinations', {
        search: search.value || undefined,
        per_page: props.destinations.meta.per_page === 0 ? 'all' : props.destinations.meta.per_page,
    }, { preserveState: true, replace: true });
};

let searchTimeout: ReturnType<typeof setTimeout>;
const onSearchInput = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 300);
};

const destroyDestination = (id: number) => {
    if (confirm(t('Delete this destination? Jobs using it will also be removed.'))) {
        router.delete(`/destinations/${id}`);
    }
};

const testDestination = (id: number) => router.post(`/destinations/${id}/test`);
const toggleDestinationActive = (destination: any) => router.patch(`/destinations/${destination.id}/active`, { is_active: !destination.is_active }, { preserveScroll: true });
</script>

<template>
    <Head :title="t('Destinations')" />
    <AppLayout :title="t('Backup destinations')" :subtitle="t('Configure encrypted storage targets for backup archives and installation saves.')">
        <template #title-actions>
            <ActionIcon :label="t('New destination')" icon="add" href="/destinations/create" />
        </template>

        <template #actions>
            <Link href="/destinations/create" class="btn-primary hidden sm:inline-flex">{{ t('New destination') }}</Link>
        </template>

        <div class="card overflow-hidden">
            <div v-if="destinations.data.length">
                <div class="divide-y divide-white/10 md:hidden">
                    <article v-for="destination in destinations.data" :key="destination.id" class="space-y-4 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="break-words font-semibold text-white">{{ destination.name }}</h2>
                                <p class="mt-1 break-words text-sm text-slate-400">{{ destination.provider_label || destination.provider }}</p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full border p-1 transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                                :class="destination.is_active ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'"
                                :aria-checked="destination.is_active"
                                :aria-label="destination.is_active ? t('Deactivate destination') : t('Activate destination')"
                                @click="toggleDestinationActive(destination)"
                            >
                                <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="destination.is_active ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                            </button>
                        </div>
                        <dl class="grid gap-3 text-sm">
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Target') }}</dt><dd class="mt-1 break-words text-slate-200">{{ destination.target_label || destination.bucket }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Endpoint') }}</dt><dd class="mt-1 break-all text-slate-200">{{ destination.endpoint || '-' }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Last test') }}</dt><dd class="mt-1"><StatusBadge :status="destination.last_test_status || 'unknown'" /></dd></div>
                        </dl>
                        <div class="flex flex-wrap gap-2">
                            <ActionIcon :label="t('Test')" icon="test" @click="testDestination(destination.id)" />
                            <ActionIcon :label="t('Edit')" icon="edit" :href="`/destinations/${destination.id}/edit`" />
                            <ActionIcon :label="t('Delete')" icon="delete" variant="danger" @click="destroyDestination(destination.id)" />
                        </div>
                    </article>
                </div>
                <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-white/10 text-sm">
                    <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-3">{{ t('Name') }}</th>
                            <th class="px-4 py-3">{{ t('Provider') }}</th>
                            <th class="px-4 py-3">{{ t('Target') }}</th>
                            <th class="px-4 py-3">{{ t('Endpoint') }}</th>
                            <th class="px-4 py-3">{{ t('Active') }}</th>
                            <th class="px-4 py-3">{{ t('Last test') }}</th>
                            <th class="px-4 py-3">{{ t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <tr v-for="destination in destinations.data" :key="destination.id" class="hover:bg-slate-100 dark:hover:bg-white/[0.03]">
                            <td class="px-4 py-3 font-medium text-white">{{ destination.name }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ destination.provider_label || destination.provider }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ destination.target_label || destination.bucket }}</td>
                            <td class="max-w-xs truncate px-4 py-3 text-slate-300">{{ destination.endpoint || '-' }}</td>
                            <td class="px-4 py-3">
                                <button
                                    type="button"
                                    role="switch"
                                    class="relative inline-flex h-7 w-12 items-center rounded-full border p-1 transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                                    :class="destination.is_active ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'"
                                    :aria-checked="destination.is_active"
                                    :aria-label="destination.is_active ? t('Deactivate destination') : t('Activate destination')"
                                    @click="toggleDestinationActive(destination)"
                                >
                                    <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="destination.is_active ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                                </button>
                            </td>
                            <td class="px-4 py-3"><StatusBadge :status="destination.last_test_status || 'unknown'" /></td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-2">
                                    <ActionIcon :label="t('Test')" icon="test" @click="testDestination(destination.id)" />
                                    <ActionIcon :label="t('Edit')" icon="edit" :href="`/destinations/${destination.id}/edit`" />
                                    <ActionIcon :label="t('Delete')" icon="delete" variant="danger" @click="destroyDestination(destination.id)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
                <Pagination :data="destinations" base-url="/destinations" :extra-params="{ search: search || undefined }" />
            </div>
            <div v-else class="p-10 text-center">
                <p class="text-lg font-semibold">{{ t('No backup destination yet.') }}</p>
                <p class="mt-2 text-sm text-slate-400">{{ t('Add S3, WebDAV, SSH/SFTP, Azure, Dropbox, Google Drive, or local storage.') }}</p>
                <Link href="/destinations/create" class="btn-primary mt-5">{{ t('Create destination') }}</Link>
            </div>
        </div>
    </AppLayout>
</template>
