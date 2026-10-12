<script setup lang="ts">
import { useI18n } from '@/i18n';

const props = defineProps<{
    status?: string | null;
}>();

const { t } = useI18n();

const classes: Record<string, string> = {
    active: 'bg-emerald-400/10 text-emerald-700 dark:text-emerald-200 ring-emerald-400/30',
    success: 'bg-emerald-400/10 text-emerald-700 dark:text-emerald-200 ring-emerald-400/30',
    paused: 'bg-amber-400/10 text-amber-800 dark:text-amber-200 ring-amber-400/30',
    queued: 'bg-sky-400/10 text-sky-700 dark:text-sky-200 ring-sky-400/30',
    running: 'bg-violet-400/10 text-violet-700 dark:text-violet-200 ring-violet-400/30',
    error: 'bg-rose-400/10 text-rose-700 dark:text-rose-200 ring-rose-400/30',
    failed: 'bg-rose-400/10 text-rose-700 dark:text-rose-200 ring-rose-400/30',
    cancelled: 'bg-slate-400/10 text-slate-800 dark:text-slate-200 ring-slate-400/30',
    warning: 'bg-amber-400/10 text-amber-800 dark:text-amber-200 ring-amber-400/30',
    critical: 'bg-rose-400/10 text-rose-700 dark:text-rose-200 ring-rose-400/30',
    resolved: 'bg-emerald-400/10 text-emerald-700 dark:text-emerald-200 ring-emerald-400/30',
    triggered: 'bg-amber-400/10 text-amber-800 dark:text-amber-200 ring-amber-400/30',
    notified: 'bg-sky-400/10 text-sky-700 dark:text-sky-200 ring-sky-400/30',
    reminder_sent: 'bg-violet-400/10 text-violet-700 dark:text-violet-200 ring-violet-400/30',
};
</script>

<template>
    <span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold capitalize ring-1" :class="classes[props.status || ''] || 'bg-slate-400/10 text-slate-800 dark:text-slate-200 ring-slate-400/30'">
        {{ t(props.status || 'unknown') }}
    </span>
</template>
