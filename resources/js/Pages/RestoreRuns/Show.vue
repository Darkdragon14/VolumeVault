<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import HostIdentity from '@/Components/HostIdentity.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';
import { formatBytes } from '@/Composables/useFormatBytes';
import { useI18n } from '@/i18n';

const props = defineProps<{ run: any }>();

const { start, stop } = usePoll(2000, { only: ['run'] }, { autoStart: false });
watch(() => ['queued', 'running'].includes(props.run.status)
    || Boolean(props.run.archive_relay && !props.run.archive_relay.cleaned_at)
    || Boolean(props.run.docker_container_cleanup_pending)
    || (props.run.stopped_container_ids?.length ?? 0) > 0, (needsPolling) => {
    if (needsPolling) start();
    else stop();
}, { immediate: true });

const { t, formatDate } = useI18n();
</script>

<template>
    <AppLayout :title="t('Restore run #{id}', { id: run.id })" :subtitle="t('Inspect source, target, selected archive, logs, and errors for this restore run.')">
        <Head :title="t('Restore run #{id}', { id: run.id })" />
        <template #actions>
            <Link :href="`/backup-jobs/${run.job.id}`" class="btn-secondary">{{ t('Back to job') }}</Link>
        </template>

        <section class="card p-4 sm:p-5">
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="label">{{ t('hostWorkflow.sourceHost') }}</dt><dd><HostIdentity :host="run.source_docker_host" /></dd></div>
                <div><dt class="label">{{ t('hostWorkflow.targetHost') }}</dt><dd><HostIdentity :host="run.target_docker_host" /></dd></div>
                <div><dt class="text-xs uppercase text-slate-400">{{ t('Status') }}</dt><dd class="mt-1"><StatusBadge :status="run.status" /></dd></div>
                <div><dt class="text-xs uppercase text-slate-400">{{ t('Mode') }}</dt><dd class="mt-1 text-white">{{ t(run.mode) }}</dd></div>
                <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Source') }}</dt><dd class="mt-1 break-all text-white">{{ run.source_volume_name }}</dd></div>
                <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Target') }}</dt><dd class="mt-1 break-all text-white">{{ run.target_volume_name }}</dd></div>
                <div class="lg:col-span-2"><dt class="text-xs uppercase text-slate-400">{{ t('Backup key') }}</dt><dd class="mt-1 break-all text-white">{{ run.selected_backup_key }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-400">{{ t('Started') }}</dt><dd class="mt-1 text-white">{{ formatDate(run.started_at) }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-400">{{ t('Duration') }}</dt><dd class="mt-1 text-white">{{ run.duration_seconds ?? '-' }}s</dd></div>
                <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Initiated by') }}</dt><dd class="mt-1 break-all text-white">{{ run.initiated_by ? `${run.initiated_by.name} (${run.initiated_by.email})` : '—' }}</dd></div>
                <div v-if="run.pre_restore_backup" class="min-w-0">
                    <dt class="text-xs uppercase text-slate-400">{{ t('Safety backup') }}</dt>
                    <dd class="mt-1 break-all text-white">
                        <Link :href="`/backup-runs/${run.pre_restore_backup.id}`" class="text-sky-300 hover:underline">{{ run.pre_restore_backup.backup_key || t('Backup run #{id}', { id: run.pre_restore_backup.id }) }}</Link>
                    </dd>
                </div>
            </dl>
            <p v-if="run.error_message" class="mt-5 break-words rounded-xl bg-rose-400/10 p-3 text-sm text-rose-100">{{ run.error_message }}</p>
        </section>

        <section v-if="run.archive_relay" class="card mt-6 p-4 sm:p-5" data-archive-relay aria-live="polite">
            <h2 class="mb-4 text-lg font-semibold">{{ t('archiveRelay.title') }}</h2>
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="label">{{ t('Source') }}</dt><dd>{{ run.archive_relay.source_docker_host?.name }} (#{{ run.archive_relay.source_docker_host_id }})</dd></div>
                <div><dt class="label">{{ t('Target') }}</dt><dd>{{ run.archive_relay.target_docker_host?.name }} (#{{ run.archive_relay.target_docker_host_id }})</dd></div>
                <div><dt class="label">{{ t('Status') }}</dt><dd>{{ t(`archiveRelay.${run.archive_relay.status}`) }}</dd></div>
                <div><dt class="label">{{ t('archiveRelay.expires') }}</dt><dd>{{ formatDate(run.archive_relay.expires_at) }}</dd></div>
                <div><dt class="label">{{ t('archiveRelay.upload') }}</dt><dd>{{ formatBytes(run.archive_relay.uploaded_bytes) }} / {{ formatBytes(run.archive_relay.size_bytes) }}</dd></div>
                <div><dt class="label">{{ t('archiveRelay.download') }}</dt><dd>{{ formatBytes(run.archive_relay.downloaded_bytes) }} / {{ formatBytes(run.archive_relay.size_bytes) }}</dd></div>
            </dl>
            <p v-if="run.archive_relay.error_message" role="alert" class="mt-4 text-sm text-rose-600 dark:text-rose-300">{{ run.archive_relay.error_message }}</p>
            <p class="mt-4 text-sm text-slate-400">{{ t(run.archive_relay.cleaned_at ? 'archiveRelay.cleaned' : 'archiveRelay.retained') }}</p>
        </section>

        <section class="card mt-6 p-4 sm:p-5">
            <h2 class="mb-4 text-lg font-semibold">{{ t('Logs') }}</h2>
            <pre class="max-h-[560px] max-w-full overflow-auto rounded-xl bg-slate-950 p-4 text-xs leading-relaxed text-slate-200">{{ run.logs || t('No logs captured yet.') }}</pre>
        </section>
    </AppLayout>
</template>
