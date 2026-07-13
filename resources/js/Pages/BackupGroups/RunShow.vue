<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import { formatBytes } from '@/Composables/useFormatBytes';

defineProps<{
    run: any;
}>();

const { t, formatDate } = useI18n();
const can = usePage().props.can as { runDockerActions?: boolean };
</script>

<template>
    <Head :title="t('Backup group run')" />
    <AppLayout :title="t('Backup group run')" :subtitle="run.group?.name">
        <div class="card max-w-4xl space-y-6 p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <StatusBadge :status="run.status" />
                    <span class="text-sm text-slate-300">{{ t('{ok}/{total} volumes succeeded', { ok: run.succeeded_members, total: run.total_members }) }}</span>
                </div>
                <Link v-if="run.group && can.runDockerActions" :href="`/backup-groups/${run.group.id}/edit`" class="btn-secondary">{{ t('Back to group') }}</Link>
            </div>

            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-xs uppercase text-slate-500">{{ t('Trigger') }}</dt><dd class="mt-1 text-slate-200">{{ t(run.trigger) }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">{{ t('Started') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(run.started_at) }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-500">{{ t('Finished') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(run.finished_at) }}</dd></div>
                <div v-if="run.duration_seconds !== null"><dt class="text-xs uppercase text-slate-500">{{ t('Duration') }}</dt><dd class="mt-1 text-slate-200">{{ run.duration_seconds }}s</dd></div>
                <div v-if="run.total_backup_size_bytes"><dt class="text-xs uppercase text-slate-500">{{ t('Backup size') }}</dt><dd class="mt-1 text-slate-200">{{ formatBytes(run.total_backup_size_bytes) }}</dd></div>
            </dl>

            <p v-if="run.error_message" class="rounded-xl border border-rose-300/30 bg-rose-500/10 p-3 text-sm text-rose-100">{{ run.error_message }}</p>

            <section>
                <h2 class="mb-3 text-lg font-semibold">{{ t('Volumes') }}</h2>
                <div v-if="run.members && run.members.length" class="divide-y divide-white/10 rounded-xl border border-white/10">
                    <div v-for="member in run.members" :key="member.id" class="flex items-center justify-between gap-3 p-3 text-sm">
                        <div class="min-w-0">
                            <p class="break-words font-medium text-white">{{ member.job_name }}</p>
                            <p class="mt-1 break-all text-slate-400">{{ member.source_label }}</p>
                            <p v-if="member.error_message" class="mt-1 break-words text-rose-300">{{ member.error_message }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <Link :href="`/backup-runs/${member.id}`" class="text-sky-300 hover:text-sky-200">{{ t('Details') }}</Link>
                            <StatusBadge :status="member.status" />
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-slate-400">{{ t('No volume runs recorded.') }}</p>
            </section>
        </div>
    </AppLayout>
</template>
