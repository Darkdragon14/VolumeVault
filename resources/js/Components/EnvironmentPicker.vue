<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import HeaderDropdown from '@/Components/HeaderDropdown.vue';
import { useI18n } from '@/i18n';
import { environmentFormsDirty, environmentFormsProcessing } from '@/Composables/useEnvironmentForms';

type Host = { id: number; name: string; status: string; is_local: boolean };
const page = usePage();
const { t } = useI18n();
const environment = computed(() => page.props.environment as { hosts: Host[]; docker_host_id: number | null } | undefined);
const selected = computed(() => environment.value?.hosts.find(host => host.id === environment.value?.docker_host_id));
const nativeForm = ref<HTMLFormElement | null>(null);
const selectedId = ref<number | ''>('');
const csrfToken = ref('');
async function select(id: number | null, close: () => void) {
    if (environmentFormsProcessing.value) return;
    if (environmentFormsDirty.value && !window.confirm(t('environment.confirmDiscard'))) return;
    selectedId.value = id ?? '';
    csrfToken.value = page.props.csrfToken as string;
    await nextTick();
    close();
    nativeForm.value?.requestSubmit();
}
</script>

<template>
    <div class="min-w-0">
    <form ref="nativeForm" action="/user/environment" method="post" hidden>
        <input type="hidden" name="_token" :value="csrfToken">
        <input type="hidden" name="docker_host_id" :value="selectedId">
    </form>
    <HeaderDropdown :label="t('environment.label')" :disabled="environmentFormsProcessing" align="left" class="max-w-[8rem] sm:max-w-[11rem] [&>button]:px-2">
        <template #trigger>
            <span class="h-2 w-2 shrink-0 rounded-full" :class="selected ? (['online', 'local'].includes(selected.status) ? 'bg-emerald-400' : 'bg-amber-400') : 'bg-sky-400'" aria-hidden="true" />
            <span class="truncate">{{ selected?.name ?? t('hostScope.all') }}</span>
        </template>
        <template #default="{ close }">
            <p class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('environment.label') }}</p>
            <button type="button" role="menuitemradio" :aria-checked="!environment?.docker_host_id" class="flex w-full items-center justify-between gap-3 rounded-xl px-3 py-3 text-left text-sm hover:bg-slate-100 focus:bg-sky-400/10 focus:outline-none dark:hover:bg-white/10" @click="select(null, close)">
                <span>{{ t('hostScope.all') }}</span><span v-if="!environment?.docker_host_id" aria-hidden="true">✓</span>
            </button>
            <button v-for="host in environment?.hosts ?? []" :key="host.id" type="button" role="menuitemradio" :aria-checked="host.id === environment?.docker_host_id" class="flex w-full items-center justify-between gap-3 rounded-xl px-3 py-3 text-left text-sm hover:bg-slate-100 focus:bg-sky-400/10 focus:outline-none dark:hover:bg-white/10" @click="select(host.id, close)">
                <span class="min-w-0"><span class="block break-words font-medium">{{ host.name }}</span><span class="mt-1 flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400"><span class="h-1.5 w-1.5 rounded-full" :class="['online', 'local'].includes(host.status) ? 'bg-emerald-400' : 'bg-amber-400'" aria-hidden="true" />{{ t(`dockerHosts.status.${host.status}`) }}<template v-if="host.is_local"> · {{ t('environment.local') }}</template> · #{{ host.id }}</span></span>
                <span v-if="host.id === environment?.docker_host_id" aria-hidden="true">✓</span>
            </button>
        </template>
    </HeaderDropdown>
    </div>
</template>
