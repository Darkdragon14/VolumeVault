<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import ActionIcon from '@/Components/ActionIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

interface PaginatedData<T> {
    data: T[];
    meta: { current_page: number; per_page: number; total: number; last_page: number };
}

defineProps<{
    groups: PaginatedData<any>;
    defaultPerPage: number;
}>();

const page = usePage();
const can = page.props.can as { runDockerActions?: boolean };
const { t, formatDate, timezone } = useI18n();

const runNow = (id: number) => router.post(`/backup-groups/${id}/run`);
const pause = (id: number) => router.post(`/backup-groups/${id}/pause`);
const resume = (id: number) => router.post(`/backup-groups/${id}/resume`);
const toggleNotifications = (group: any) => router.patch(`/backup-groups/${group.id}/notifications`, { notifications_enabled: !group.notifications_enabled });
const destroyGroup = (id: number) => confirm(t('Delete this backup group? Detach its jobs first.')) && router.delete(`/backup-groups/${id}`);
const viewGroup = (id: number) => router.visit(`/backup-groups/${id}`);
const onGroupKeydown = (event: KeyboardEvent, id: number) => {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        viewGroup(id);
    }
};
</script>

<template>
    <Head :title="t('Backup groups')" />
    <AppLayout :title="t('Backup groups')" :subtitle="t('Group several volumes into one scheduled backup with a single start/success/fail notification.')">
        <template #title-actions>
            <ActionIcon v-if="can.runDockerActions" :label="t('New backup group')" icon="add" href="/backup-groups/create" />
        </template>

        <template #actions>
            <Link v-if="can.runDockerActions" href="/backup-groups/create" class="btn-primary hidden shrink-0 gap-2 px-3 sm:inline-flex">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 5v14" />
                    <path d="M5 12h14" />
                </svg>
                <span class="whitespace-nowrap">{{ t('New backup group') }}</span>
            </Link>
        </template>

        <p class="mb-3 text-sm text-slate-400">{{ t('Times are shown in {timezone}.', { timezone }) }}</p>

        <div class="card overflow-hidden">
            <div v-if="groups.data.length">
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-white/10 text-sm">
                        <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-4 py-3">{{ t('Name') }}</th>
                                <th class="px-4 py-3">{{ t('Volumes') }}</th>
                                <th class="px-4 py-3">{{ t('Schedule') }}</th>
                                <th class="px-4 py-3">{{ t('Notifications') }}</th>
                                <th class="px-4 py-3">{{ t('Status') }}</th>
                                <th class="px-4 py-3">{{ t('Next run') }}</th>
                                <th class="px-4 py-3">{{ t('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            <tr v-for="group in groups.data" :key="group.id" class="cursor-pointer hover:bg-slate-100 dark:hover:bg-white/[0.03]" role="link" tabindex="0" @click="viewGroup(group.id)" @keydown="onGroupKeydown($event, group.id)">
                                <td class="px-4 py-3 font-medium text-white">{{ group.name }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ group.members_count }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ group.schedule_summary }}</td>
                                <td class="px-4 py-3" @click.stop @keydown.stop>
                                    <button
                                        type="button"
                                        role="switch"
                                        class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full border p-1 transition"
                                        :class="group.notifications_enabled ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'"
                                        :aria-checked="group.notifications_enabled"
                                        :aria-label="t('Toggle notifications')"
                                        :disabled="!can.runDockerActions"
                                        @click="toggleNotifications(group)"
                                    >
                                        <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="group.notifications_enabled ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                                    </button>
                                </td>
                                <td class="px-4 py-3"><StatusBadge :status="group.status" /></td>
                                <td class="px-4 py-3 text-slate-300">{{ formatDate(group.next_run_at) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex md:min-w-52 flex-wrap gap-2" @click.stop @keydown.stop>
                                        <ActionIcon v-if="can.runDockerActions" :label="t('Run now')" icon="play" :disabled="group.status !== 'active'" @click="runNow(group.id)" />
                                        <ActionIcon v-if="can.runDockerActions && (group.status === 'paused' || group.status === 'error')" :label="t('Resume')" icon="play" @click="resume(group.id)" />
                                        <ActionIcon v-else-if="can.runDockerActions" :label="t('Pause')" icon="pause" :disabled="group.status === 'running'" @click="pause(group.id)" />
                                        <ActionIcon v-if="can.runDockerActions" :label="t('Edit')" icon="edit" :href="`/backup-groups/${group.id}/edit`" />
                                        <ActionIcon v-if="can.runDockerActions" :label="t('Delete')" icon="delete" variant="danger" @click="destroyGroup(group.id)" />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="divide-y divide-white/10 md:hidden">
                    <article v-for="group in groups.data" :key="group.id" class="space-y-4 p-4 cursor-pointer transition hover:bg-slate-100 dark:hover:bg-white/[0.03]" role="link" tabindex="0" @click="viewGroup(group.id)" @keydown="onGroupKeydown($event, group.id)">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="break-words font-semibold text-white">{{ group.name }}</h2>
                                <p class="mt-1 text-sm text-slate-400">{{ t('{count} volume(s)', { count: group.members_count }) }} · {{ group.schedule_summary }}</p>
                            </div>
                            <StatusBadge :status="group.status" />
                        </div>
                        <div class="flex flex-wrap gap-2" @click.stop @keydown.stop>
                            <ActionIcon v-if="can.runDockerActions" :label="t('Run now')" icon="play" :disabled="group.status !== 'active'" @click="runNow(group.id)" />
                            <ActionIcon v-if="can.runDockerActions && (group.status === 'paused' || group.status === 'error')" :label="t('Resume')" icon="play" @click="resume(group.id)" />
                            <ActionIcon v-else-if="can.runDockerActions" :label="t('Pause')" icon="pause" :disabled="group.status === 'running'" @click="pause(group.id)" />
                            <ActionIcon v-if="can.runDockerActions" :label="t('Edit')" icon="edit" :href="`/backup-groups/${group.id}/edit`" />
                            <ActionIcon v-if="can.runDockerActions" :label="t('Delete')" icon="delete" variant="danger" @click="destroyGroup(group.id)" />
                        </div>
                    </article>
                </div>

                <Pagination :data="groups" base-url="/backup-groups" />
            </div>
            <div v-else class="p-10 text-center">
                <p class="text-lg font-semibold">{{ t('No backup groups yet.') }}</p>
                <p class="mt-2 text-sm text-slate-400">{{ t('Create a group, then attach jobs to it from the backup job form to back up several volumes as one operation.') }}</p>
                <Link v-if="can.runDockerActions" href="/backup-groups/create" class="btn-primary mt-5">{{ t('Create backup group') }}</Link>
            </div>
        </div>
    </AppLayout>
</template>
