<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/i18n';

const props = defineProps<{ alert: any }>();

const { t, formatDate } = useI18n();
const contextEntries = computed(() => Object.entries(props.alert.context || {}));
const formatValue = (value: any) => typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value ?? '');
</script>

<template>
    <Head :title="t('Alert #{id}', { id: alert.id })" />
    <AppLayout :title="t('Alert #{id}', { id: alert.id })" :subtitle="t('Inspect alert state, context, notifications, and history.')">
        <template #actions>
            <Link href="/alerts" class="btn-secondary">{{ t('Back to alerts') }}</Link>
        </template>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card flex flex-col gap-4 p-5 lg:col-span-2">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t(alert.type) }}</p>
                        <h2 class="mt-1 wrap-break-word text-xl font-semibold text-slate-900 dark:text-white">{{ alert.message }}</h2>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <StatusBadge :status="alert.severity" />
                        <StatusBadge :status="alert.status" />
                    </div>
                </div>

                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs uppercase text-slate-500">{{ t('Subject') }}</dt><dd class="mt-1 wrap-break-word text-slate-800 dark:text-slate-200">{{ alert.subject?.name || t('Unknown') }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-500">{{ t('Source') }}</dt><dd class="mt-1 break-all text-slate-800 dark:text-slate-200">{{ alert.subject?.source || t('Unknown') }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-500">{{ t('First triggered') }}</dt><dd class="mt-1 text-slate-800 dark:text-slate-200">{{ formatDate(alert.first_triggered_at) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-500">{{ t('Last triggered') }}</dt><dd class="mt-1 text-slate-800 dark:text-slate-200">{{ formatDate(alert.last_triggered_at) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-500">{{ t('Resolved') }}</dt><dd class="mt-1 text-slate-800 dark:text-slate-200">{{ formatDate(alert.resolved_at, 'None') }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-500">{{ t('Trigger count') }}</dt><dd class="mt-1 text-slate-800 dark:text-slate-200">{{ alert.trigger_count }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-500">{{ t('Last notified') }}</dt><dd class="mt-1 text-slate-800 dark:text-slate-200">{{ formatDate(alert.last_notified_at, 'None') }}</dd></div>
                </dl>
            </section>

            <section class="card p-5">
                <h2 class="text-lg font-semibold">{{ t('Context') }}</h2>
                <div v-if="contextEntries.length" class="mt-4 flex flex-col gap-3 text-sm">
                    <div v-for="[key, value] in contextEntries" :key="key" class="rounded-xl border border-slate-200 dark:border-white/10 bg-white/90 dark:bg-slate-950/60 p-3">
                        <p class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ key }}</p>
                        <p class="mt-1 wrap-break-word text-slate-800 dark:text-slate-200">{{ formatValue(value) }}</p>
                    </div>
                </div>
                <p v-else class="mt-4 text-sm text-slate-500 dark:text-slate-400">{{ t('No context captured.') }}</p>
            </section>
        </div>

        <section class="card mt-6 overflow-hidden">
            <div class="border-b border-slate-200 dark:border-white/10 p-5">
                <h2 class="text-lg font-semibold">{{ t('Alert history') }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('Every trigger, reminder, notification, and resolution is recorded here.') }}</p>
            </div>
            <div v-if="alert.events.length" class="divide-y divide-slate-200 dark:divide-white/10">
                <article v-for="event in alert.events" :key="event.id" class="grid gap-3 p-4 text-sm md:grid-cols-[12rem_1fr]">
                    <div>
                        <StatusBadge :status="event.event_type" />
                        <p class="mt-2 text-xs text-slate-500">{{ formatDate(event.created_at) }}</p>
                    </div>
                    <pre class="overflow-x-auto whitespace-pre-wrap rounded-xl bg-white/90 dark:bg-slate-950/70 p-3 text-xs text-slate-800 dark:text-slate-200">{{ JSON.stringify(event.context || {}, null, 2) }}</pre>
                </article>
            </div>
            <p v-else class="p-5 text-sm text-slate-500 dark:text-slate-400">{{ t('No alert events yet.') }}</p>
        </section>
    </AppLayout>
</template>
