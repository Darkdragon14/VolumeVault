<script setup lang="ts">
import { useI18n } from '@/i18n';

defineProps<{
    text: string;
}>();

const { t } = useI18n();
</script>

<template>
    <span class="group relative inline-flex">
        <span
            class="inline-flex h-6 w-6 items-center justify-center rounded-full border border-slate-500/50 bg-white/90 dark:bg-slate-950/70 text-xs font-semibold text-slate-600 dark:text-slate-300 transition hover:border-sky-500/50 hover:text-sky-700 focus:outline-hidden focus:ring-2 focus:ring-sky-500/30 dark:hover:border-sky-300/50 dark:hover:text-sky-100 dark:focus:ring-sky-400/30"
            :aria-label="t('More information')"
        >
            ?
        </span>
        <span class="pointer-events-none absolute right-0 top-full z-30 mt-2 hidden w-64 rounded-xl border border-slate-200 dark:border-white/10 bg-white/90 dark:bg-slate-950 p-3 text-left text-xs leading-relaxed text-slate-800 dark:text-slate-200 shadow-2xl shadow-slate-300/20 dark:shadow-black/40 group-hover:block">
            {{ text }}
        </span>
    </span>
</template>
