<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

const props = withDefaults(defineProps<{
    label: string;
    icon: 'archive' | 'delete' | 'edit' | 'eye' | 'pause' | 'play' | 'refresh' | 'restore' | 'test' | 'token';
    href?: string;
    disabled?: boolean;
    variant?: 'default' | 'danger';
}>(), {
    disabled: false,
    href: undefined,
    variant: 'default',
});

defineEmits<{
    click: [];
}>();

const baseClass = 'group relative inline-flex h-9 w-9 items-center justify-center rounded-xl border text-sm transition focus:outline-none focus:ring-2 focus:ring-sky-400/40 disabled:cursor-not-allowed disabled:opacity-50';
const variantClass = props.variant === 'danger'
    ? 'border-rose-300/20 bg-rose-500/10 text-rose-100 hover:bg-rose-500/20'
    : 'border-white/10 bg-white/5 text-slate-100 hover:bg-white/10';
</script>

<template>
    <Link
        v-if="href && !disabled"
        :href="href"
        :class="[baseClass, variantClass]"
        :aria-label="label"
        :title="label"
    >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path v-if="icon === 'archive'" d="M21 8v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8" />
            <path v-if="icon === 'archive'" d="M10 12h4" />
            <path v-if="icon === 'archive'" d="M3 3h18v5H3z" />
            <path v-if="icon === 'delete'" d="M3 6h18" />
            <path v-if="icon === 'delete'" d="M8 6V4h8v2" />
            <path v-if="icon === 'delete'" d="M19 6l-1 14H6L5 6" />
            <path v-if="icon === 'edit'" d="M12 20h9" />
            <path v-if="icon === 'edit'" d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
            <path v-if="icon === 'eye'" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
            <circle v-if="icon === 'eye'" cx="12" cy="12" r="3" />
            <path v-if="icon === 'pause'" d="M8 5v14" />
            <path v-if="icon === 'pause'" d="M16 5v14" />
            <path v-if="icon === 'play'" d="M8 5v14l11-7Z" />
            <path v-if="icon === 'refresh'" d="M21 12a9 9 0 0 1-15 6.7" />
            <path v-if="icon === 'refresh'" d="M3 12a9 9 0 0 1 15-6.7" />
            <path v-if="icon === 'refresh'" d="M3 21v-5h5" />
            <path v-if="icon === 'refresh'" d="M21 3v5h-5" />
            <path v-if="icon === 'restore'" d="M3 12a9 9 0 1 0 3-6.7" />
            <path v-if="icon === 'restore'" d="M3 3v6h6" />
            <path v-if="icon === 'restore'" d="M12 7v5l3 2" />
            <path v-if="icon === 'test'" d="M20 6 9 17l-5-5" />
            <path v-if="icon === 'token'" d="M15 7a4 4 0 1 1-2.8 6.8L9 17H6v3H3v-3.2l5.2-5.2A4 4 0 0 1 15 7Z" />
            <path v-if="icon === 'token'" d="M17 9h.01" />
        </svg>
        <span class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 hidden max-w-[calc(100vw-2rem)] -translate-x-1/2 whitespace-nowrap rounded-lg border border-white/10 bg-slate-950 px-2 py-1 text-xs font-medium text-slate-100 opacity-0 shadow-xl shadow-black/30 transition group-hover:opacity-100 group-focus-visible:opacity-100 sm:block">
            {{ label }}
        </span>
    </Link>

    <button
        v-else
        type="button"
        :class="[baseClass, variantClass]"
        :disabled="disabled"
        :aria-label="label"
        :title="label"
        @click="$emit('click')"
    >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path v-if="icon === 'archive'" d="M21 8v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8" />
            <path v-if="icon === 'archive'" d="M10 12h4" />
            <path v-if="icon === 'archive'" d="M3 3h18v5H3z" />
            <path v-if="icon === 'delete'" d="M3 6h18" />
            <path v-if="icon === 'delete'" d="M8 6V4h8v2" />
            <path v-if="icon === 'delete'" d="M19 6l-1 14H6L5 6" />
            <path v-if="icon === 'edit'" d="M12 20h9" />
            <path v-if="icon === 'edit'" d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
            <path v-if="icon === 'eye'" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
            <circle v-if="icon === 'eye'" cx="12" cy="12" r="3" />
            <path v-if="icon === 'pause'" d="M8 5v14" />
            <path v-if="icon === 'pause'" d="M16 5v14" />
            <path v-if="icon === 'play'" d="M8 5v14l11-7Z" />
            <path v-if="icon === 'refresh'" d="M21 12a9 9 0 0 1-15 6.7" />
            <path v-if="icon === 'refresh'" d="M3 12a9 9 0 0 1 15-6.7" />
            <path v-if="icon === 'refresh'" d="M3 21v-5h5" />
            <path v-if="icon === 'refresh'" d="M21 3v5h-5" />
            <path v-if="icon === 'restore'" d="M3 12a9 9 0 1 0 3-6.7" />
            <path v-if="icon === 'restore'" d="M3 3v6h6" />
            <path v-if="icon === 'restore'" d="M12 7v5l3 2" />
            <path v-if="icon === 'test'" d="M20 6 9 17l-5-5" />
            <path v-if="icon === 'token'" d="M15 7a4 4 0 1 1-2.8 6.8L9 17H6v3H3v-3.2l5.2-5.2A4 4 0 0 1 15 7Z" />
            <path v-if="icon === 'token'" d="M17 9h.01" />
        </svg>
        <span class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 hidden max-w-[calc(100vw-2rem)] -translate-x-1/2 whitespace-nowrap rounded-lg border border-white/10 bg-slate-950 px-2 py-1 text-xs font-medium text-slate-100 opacity-0 shadow-xl shadow-black/30 transition group-hover:opacity-100 group-focus-visible:opacity-100 sm:block">
            {{ label }}
        </span>
    </button>
</template>
