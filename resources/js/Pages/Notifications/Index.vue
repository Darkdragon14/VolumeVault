<script setup lang="ts">
import ActionIcon from '@/Components/ActionIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import { ref } from 'vue';
import { readFiltersFromUrl } from '@/Composables/useListFilters';

interface PaginatedData<T> {
    data: T[];
    meta: { current_page: number; per_page: number; total: number; last_page: number };
}

const props = defineProps<{
    channels: PaginatedData<any>;
    defaultPerPage: number;
}>();

const { t, formatDate } = useI18n();
const search = ref('');

readFiltersFromUrl({ search });

const applyFilters = () => {
    router.get('/notifications', {
        search: search.value || undefined,
        per_page: props.channels.meta.per_page === 0 ? 'all' : props.channels.meta.per_page,
    }, { preserveState: true, replace: true });
};

let searchTimeout: ReturnType<typeof setTimeout>;
const onSearchInput = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 300);
};

const destroyChannel = (id: number) => confirm(t('Delete this notification channel?')) && router.delete(`/notifications/${id}`);
const testChannel = (id: number) => router.post(`/notifications/${id}/test`);
const toggleChannelActive = (channel: any) => router.patch(`/notifications/${channel.id}/active`, { is_active: !channel.is_active }, { preserveScroll: true });
</script>

<template>
    <Head :title="t('Notifications')" />
    <AppLayout :title="t('Notifications')" :subtitle="t('Route backup results to Shoutrrr channels selected per backup job.')">
        <template #actions>
            <Link href="/notifications/create" class="btn-primary">{{ t('New channel') }}</Link>
        </template>

        <div class="mb-6 grid gap-4 lg:grid-cols-3">
            <div class="card p-5 lg:col-span-2">
                <h2 class="text-lg font-semibold">{{ t('Shoutrrr channels') }}</h2>
                <p class="mt-2 text-sm text-slate-400">{{ t('Configure Discord, Telegram, Ntfy, Gotify, email, or any advanced Shoutrrr URL once, then select channels per backup job.') }}</p>
            </div>
            <div class="card border-sky-300/20 bg-sky-400/10 p-5 text-sm text-sky-100">
                {{ t('URLs are encrypted at rest and never returned to the browser after save.') }}
            </div>
        </div>

        <div class="card overflow-hidden">
            <div v-if="channels.data.length">
                <div class="divide-y divide-white/10 md:hidden">
                    <article v-for="channel in channels.data" :key="channel.id" class="space-y-4 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="break-words font-semibold text-white">{{ channel.name }}</h2>
                                    <span v-if="channel.is_default" class="rounded-full border border-sky-300/30 bg-sky-400/10 px-2 py-0.5 text-xs text-sky-100">{{ t('Default') }}</span>
                                </div>
                                <p class="mt-1 text-sm capitalize text-slate-400">{{ channel.service }}</p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full border p-1 transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                                :class="channel.is_active ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'"
                                :aria-checked="channel.is_active"
                                :aria-label="channel.is_active ? t('Deactivate channel') : t('Activate channel')"
                                @click="toggleChannelActive(channel)"
                            >
                                <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="channel.is_active ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                            </button>
                        </div>
                        <dl class="grid gap-3 text-sm">
                            <div class="grid grid-cols-2 gap-3">
                                <div><dt class="text-xs uppercase text-slate-500">{{ t('Jobs') }}</dt><dd class="mt-1 text-slate-200">{{ t('Used by {count} jobs', { count: channel.backup_job_ids.length }) }}</dd></div>
                                <div><dt class="text-xs uppercase text-slate-500">{{ t('Level') }}</dt><dd class="mt-1 text-slate-200">{{ channel.notification_level === 'info' ? t('Every backup and restore run') : t('Errors only') }}</dd></div>
                            </div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Last test') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(channel.last_tested_at) }}</dd><dd v-if="channel.last_test_status === 'failed'" class="mt-1 break-words text-xs text-rose-300">{{ channel.last_test_error }}</dd></div>
                        </dl>
                        <div class="flex flex-wrap gap-2">
                            <ActionIcon :label="t('Test')" icon="test" @click="testChannel(channel.id)" />
                            <ActionIcon :label="t('Edit')" icon="edit" :href="`/notifications/${channel.id}/edit`" />
                            <ActionIcon :label="t('Delete')" icon="delete" variant="danger" @click="destroyChannel(channel.id)" />
                        </div>
                    </article>
                </div>
                <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-white/10 text-sm">
                    <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-3">{{ t('Name') }}</th>
                            <th class="px-4 py-3">{{ t('Service') }}</th>
                            <th class="px-4 py-3">{{ t('Jobs') }}</th>
                            <th class="px-4 py-3">{{ t('Level') }}</th>
                            <th class="px-4 py-3">{{ t('Active') }}</th>
                            <th class="px-4 py-3">{{ t('Last test') }}</th>
                            <th class="px-4 py-3">{{ t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <tr v-for="channel in channels.data" :key="channel.id" class="hover:bg-slate-100 dark:hover:bg-white/[0.03]">
                            <td class="px-4 py-3 font-medium text-white">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span>{{ channel.name }}</span>
                                    <span v-if="channel.is_default" class="rounded-full border border-sky-300/30 bg-sky-400/10 px-2 py-0.5 text-xs text-sky-100">{{ t('Default') }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-300">{{ channel.service }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ t('Used by {count} jobs', { count: channel.backup_job_ids.length }) }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ channel.notification_level === 'info' ? t('Every backup and restore run') : t('Errors only') }}</td>
                            <td class="px-4 py-3">
                                <button
                                    type="button"
                                    role="switch"
                                    class="relative inline-flex h-7 w-12 items-center rounded-full border p-1 transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                                    :class="channel.is_active ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'"
                                    :aria-checked="channel.is_active"
                                    :aria-label="channel.is_active ? t('Deactivate channel') : t('Activate channel')"
                                    @click="toggleChannelActive(channel)"
                                >
                                    <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="channel.is_active ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                                </button>
                            </td>
                            <td class="px-4 py-3 text-slate-300">
                                <div>{{ formatDate(channel.last_tested_at) }}</div>
                                <p v-if="channel.last_test_status === 'failed'" class="mt-1 max-w-xs truncate text-xs text-rose-300">{{ channel.last_test_error }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex md:min-w-32 flex-wrap gap-2">
                                    <ActionIcon :label="t('Test')" icon="test" @click="testChannel(channel.id)" />
                                    <ActionIcon :label="t('Edit')" icon="edit" :href="`/notifications/${channel.id}/edit`" />
                                    <ActionIcon :label="t('Delete')" icon="delete" variant="danger" @click="destroyChannel(channel.id)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
                <Pagination :data="channels" base-url="/notifications" :extra-params="{ search: search || undefined }" />
            </div>
            <div v-else class="p-10 text-center">
                <p class="text-lg font-semibold">{{ t('No notification channels yet.') }}</p>
                <p class="mt-2 text-sm text-slate-400">{{ t('Add one channel, test it, then select it from backup jobs.') }}</p>
                <Link href="/notifications/create" class="btn-primary mt-5">{{ t('Create channel') }}</Link>
            </div>
        </div>
    </AppLayout>
</template>
