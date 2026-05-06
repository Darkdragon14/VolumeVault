<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import ActionIcon from '@/Components/ActionIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

defineProps<{ channels: any[]; jobs: any[] }>();

const { t, formatDate } = useI18n();
const destroyChannel = (id: number) => confirm(t('Delete this notification channel?')) && router.delete(`/notifications/${id}`);
const testChannel = (id: number) => router.post(`/notifications/${id}/test`);
</script>

<template>
    <Head :title="t('Notifications')" />
    <AppLayout :title="t('Notifications')" :subtitle="t('Route backup results to Shoutrrr channels for all or selected jobs.')">
        <template #actions>
            <Link href="/notifications/create" class="btn-primary">{{ t('New channel') }}</Link>
        </template>

        <div class="mb-6 grid gap-4 lg:grid-cols-3">
            <div class="card p-5 lg:col-span-2">
                <h2 class="text-lg font-semibold">{{ t('Shoutrrr channels') }}</h2>
                <p class="mt-2 text-sm text-slate-400">Configure Discord, Telegram, Ntfy, Gotify, email, or any advanced Shoutrrr URL once, then apply it to every backup or only selected jobs.</p>
            </div>
            <div class="card border-sky-300/20 bg-sky-400/10 p-5 text-sm text-sky-100">
                URLs are encrypted at rest and never returned to the browser after save.
            </div>
        </div>

        <div class="card overflow-hidden">
            <div v-if="channels.length">
                <div class="divide-y divide-white/10 md:hidden">
                    <article v-for="channel in channels" :key="channel.id" class="space-y-4 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="break-words font-semibold text-white">{{ channel.name }}</h2>
                                <p class="mt-1 text-sm capitalize text-slate-400">{{ channel.service }}</p>
                            </div>
                            <StatusBadge :status="channel.is_active ? 'active' : 'paused'" />
                        </div>
                        <dl class="grid gap-3 text-sm">
                            <div class="grid grid-cols-2 gap-3">
                                <div><dt class="text-xs uppercase text-slate-500">Scope</dt><dd class="mt-1 text-slate-200"><span v-if="channel.scope === 'all'">All backups</span><span v-else>{{ channel.backup_job_ids.length }} selected backups</span></dd></div>
                                <div><dt class="text-xs uppercase text-slate-500">Level</dt><dd class="mt-1 text-slate-200">{{ channel.notification_level === 'info' ? 'All runs' : 'Errors only' }}</dd></div>
                            </div>
                            <div><dt class="text-xs uppercase text-slate-500">Last test</dt><dd class="mt-1 text-slate-200">{{ formatDate(channel.last_tested_at) }}</dd><dd v-if="channel.last_test_status === 'failed'" class="mt-1 break-words text-xs text-rose-300">{{ channel.last_test_error }}</dd></div>
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
                            <th class="px-4 py-3">Service</th>
                            <th class="px-4 py-3">Scope</th>
                            <th class="px-4 py-3">Level</th>
                            <th class="px-4 py-3">Active</th>
                            <th class="px-4 py-3">Last test</th>
                            <th class="px-4 py-3">{{ t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <tr v-for="channel in channels" :key="channel.id" class="hover:bg-white/[0.03]">
                            <td class="px-4 py-3 font-medium text-white">{{ channel.name }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ channel.service }}</td>
                            <td class="px-4 py-3 text-slate-300">
                                <span v-if="channel.scope === 'all'">All backups</span>
                                <span v-else>{{ channel.backup_job_ids.length }} selected backups</span>
                            </td>
                            <td class="px-4 py-3 text-slate-300">{{ channel.notification_level === 'info' ? 'All runs' : 'Errors only' }}</td>
                            <td class="px-4 py-3"><StatusBadge :status="channel.is_active ? 'active' : 'paused'" /></td>
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
            </div>
            <div v-else class="p-10 text-center">
                <p class="text-lg font-semibold">{{ t('No notification channels yet.') }}</p>
                <p class="mt-2 text-sm text-slate-400">Add one channel, test it, then attach it to all backups or selected jobs.</p>
                <Link href="/notifications/create" class="btn-primary mt-5">{{ t('Create channel') }}</Link>
            </div>
        </div>
    </AppLayout>
</template>
