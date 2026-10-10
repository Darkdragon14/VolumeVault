<script setup lang="ts">
defineProps<{
    label: string;
    value: string;
}>();
</script>

<template>
    <div class="card h-full p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ label }}</p>
        <p class="mt-3 wrap-break-word text-2xl font-bold text-slate-900 dark:text-white">{{ value }}</p>
    </div>
</template>
