<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

defineProps<{ run: any }>();

const { t, formatDate } = useI18n();
</script>

<template>
    <Head :title="t('Backup run #{id}', { id: run.id })" />
    <AppLayout :title="t('Backup run #{id}', { id: run.id })" :subtitle="t('Inspect container output, status, timing, and errors for this backup run.')">
        <template #actions>
            <Link :href="`/backup-jobs/${run.job.id}`" class="btn-secondary">{{ t('Back to job') }}</Link>
        </template>

        <section class="card p-4 sm:p-5">
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Job') }}</dt><dd class="mt-1 break-words text-white">{{ run.job.name }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-400">{{ t('Status') }}</dt><dd class="mt-1"><StatusBadge :status="run.status" /></dd></div>
                <div><dt class="text-xs uppercase text-slate-400">{{ t('Trigger') }}</dt><dd class="mt-1 text-white">{{ run.trigger }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-400">{{ t('Duration') }}</dt><dd class="mt-1 text-white">{{ run.duration_seconds ?? '-' }}s</dd></div>
                <div><dt class="text-xs uppercase text-slate-400">{{ t('Started') }}</dt><dd class="mt-1 text-white">{{ formatDate(run.started_at) }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-400">{{ t('Finished') }}</dt><dd class="mt-1 text-white">{{ formatDate(run.finished_at) }}</dd></div>
                <div class="min-w-0 sm:col-span-2"><dt class="text-xs uppercase text-slate-400">{{ t('Container') }}</dt><dd class="mt-1 break-all text-white">{{ run.docker_container_id || '-' }}</dd></div>
            </dl>
            <p v-if="run.error_message" class="mt-5 break-words rounded-xl bg-rose-400/10 p-3 text-sm text-rose-100">{{ run.error_message }}</p>
        </section>

        <section class="card mt-6 p-4 sm:p-5">
            <h2 class="mb-4 text-lg font-semibold">{{ t('Logs') }}</h2>
            <pre class="max-h-[560px] max-w-full overflow-auto rounded-xl bg-slate-950 p-4 text-xs leading-relaxed text-slate-200">{{ run.logs || t('No logs captured yet.') }}</pre>
        </section>
    </AppLayout>
</template>
