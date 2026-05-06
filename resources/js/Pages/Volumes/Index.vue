<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import ActionIcon from '@/Components/ActionIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

defineProps<{ volumes: any[] }>();

const page = usePage();
const can = page.props.can as { runDockerActions?: boolean };
const { t, formatDate } = useI18n();
const sync = () => router.post('/volumes/sync');
</script>

<template>
    <Head :title="t('Volumes')" />
    <AppLayout :title="t('Docker volumes')" :subtitle="t('Inspect discovered Docker volumes and start backup jobs from the volumes that matter.')">
        <template #actions>
            <button v-if="can.runDockerActions" class="btn-primary" @click="sync">{{ t('Sync volumes') }}</button>
        </template>

        <div class="card overflow-hidden">
            <div v-if="volumes.length">
                <div class="divide-y divide-white/10 md:hidden">
                    <article v-for="volume in volumes" :key="volume.id" class="space-y-4 p-4">
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
                            <ActionIcon :label="t('View jobs ({count})', { count: volume.related_jobs_count })" icon="eye" href="/backup-jobs" />
                        </div>
                    </article>
                </div>
                <div class="hidden overflow-x-auto md:block">
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
                        <tr v-for="volume in volumes" :key="volume.id" class="hover:bg-white/[0.03]">
                            <td class="px-4 py-3 font-medium text-white">{{ volume.name }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ volume.driver || t('Unknown') }}</td>
                            <td class="px-4 py-3"><StatusBadge :status="volume.exists ? 'active' : 'error'" /></td>
                            <td class="px-4 py-3 text-slate-300">{{ formatDate(volume.last_seen_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-2">
                                    <ActionIcon v-if="can.runDockerActions" :label="t('Create backup job')" icon="archive" :href="`/backup-jobs/create?volume=${encodeURIComponent(volume.name)}`" />
                                    <ActionIcon :label="t('View jobs ({count})', { count: volume.related_jobs_count })" icon="eye" href="/backup-jobs" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
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
