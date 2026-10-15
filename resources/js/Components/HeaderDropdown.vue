<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';

const props = withDefaults(defineProps<{ label: string; disabled?: boolean; active?: boolean; align?: 'left' | 'right' }>(), { align: 'right' });
const open = ref(false);
const root = ref<HTMLElement>();
const trigger = ref<HTMLButtonElement>();
const panel = ref<HTMLElement>();
const panelId = useId();
const items = () => Array.from(panel.value?.querySelectorAll<HTMLElement>('a[href], button:not(:disabled), select:not(:disabled)') ?? []);
async function show(last = false) {
    if (props.disabled) return;
    open.value = true;
    await nextTick();
    const options = items();
    options.forEach(item => item.setAttribute('tabindex', '-1'));
    (last ? options.at(-1) : options.find(item => item.getAttribute('aria-checked') === 'true') ?? options[0])?.focus();
}
function close(restoreFocus = false) {
    open.value = false;
    if (restoreFocus) trigger.value?.focus();
}
function keydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close(true);
    } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key) && !(event.target instanceof HTMLSelectElement)) {
        event.preventDefault();
        const options = items();
        const index = options.indexOf(document.activeElement as HTMLElement);
        const target = event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
        options[target]?.focus();
    }
}
function outside(event: MouseEvent) {
    if (!root.value?.contains(event.target as Node)) close();
}
function focusout(event: FocusEvent) {
    if (!root.value?.contains(event.relatedTarget as Node)) close();
}
onMounted(() => document.addEventListener('click', outside));
onBeforeUnmount(() => document.removeEventListener('click', outside));
</script>

<template>
    <div ref="root" class="relative min-w-0" @focusout="focusout">
        <button ref="trigger" type="button" :disabled="disabled" :aria-label="label" :aria-controls="panelId" aria-haspopup="menu" :aria-expanded="open" class="inline-flex max-w-full items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-400/50 disabled:opacity-50 dark:hover:bg-white/10" :class="open || active ? 'bg-slate-100/90 text-slate-900 dark:bg-white/10 dark:text-white' : 'text-slate-600 dark:text-slate-300'" @click="open ? close() : show()" @keydown.down.prevent="show()" @keydown.up.prevent="show(true)" @keydown.esc.stop.prevent="close(true)">
            <slot name="trigger">{{ label }}</slot>
            <svg class="h-3 w-3 shrink-0 transition" :class="{ 'rotate-180': open }" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m4 6 4 4 4-4" /></svg>
        </button>
        <div v-if="open" :id="panelId" ref="panel" role="menu" :aria-label="label" class="fixed left-3 right-3 z-50 mt-2 max-h-[70vh] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl shadow-slate-300/20 dark:border-white/10 dark:bg-slate-950 dark:shadow-black/40 sm:absolute sm:w-72" :class="align === 'left' ? 'sm:left-0 sm:right-auto' : 'sm:left-auto sm:right-0'" @keydown="keydown" @click="($event.target as HTMLElement).closest('a, [data-dropdown-close]') && close(true)">
            <slot :close="() => close(true)" />
        </div>
    </div>
</template>
