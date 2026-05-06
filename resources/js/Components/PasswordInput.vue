<script setup lang="ts">
import { ref, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });

const model = defineModel<string>();
const attrs = useAttrs();
const visible = ref(false);
</script>

<template>
    <div class="relative">
        <input
            v-bind="attrs"
            v-model="model"
            :type="visible ? 'text' : 'password'"
            :class="['input pr-11', attrs.class]"
        >
        <button
            type="button"
            class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 transition hover:text-sky-300 focus:outline-none focus-visible:text-sky-300"
            :aria-label="visible ? 'Hide password' : 'Show password'"
            :aria-pressed="visible"
            @click.prevent="visible = !visible"
        >
            <svg v-if="visible" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M3 3l18 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                <path d="M9.1 5.4A8.8 8.8 0 0 1 12 5c5.5 0 8.5 5.5 8.5 7a6.2 6.2 0 0 1-1.6 2.7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M6.6 6.8C4.5 8.2 3.5 10.7 3.5 12c0 1.5 3 7 8.5 7a8.8 8.8 0 0 0 4.3-1.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M3.5 12c0-1.5 3-7 8.5-7s8.5 5.5 8.5 7-3 7-8.5 7-8.5-5.5-8.5-7Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="currentColor" stroke-width="1.8" />
            </svg>
        </button>
    </div>
</template>
