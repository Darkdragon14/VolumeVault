<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import HostIdentity from '@/Components/HostIdentity.vue';

const props = defineProps<{ hosts?: any[]; filters?: { docker_host_id?: number | null }; query?: Record<string, any>; preserveState?: boolean }>();
const emit = defineEmits<{ beforeNavigate: []; navigating: [pending: boolean] }>();
const pending = ref(false);
const hostSelect = ref<HTMLSelectElement>();
let navigationId = 0;
const page = usePage();
const { t } = useI18n();
const selectedHosts = computed(() => (props.hosts ?? []).filter(host => !props.filters?.docker_host_id || host.id === props.filters.docker_host_id));
function selectHost(event: Event) {
    if (pending.value) return;
    const url = new URL(page.url, window.location.origin);
    for (const [key, value] of Object.entries(props.query ?? {})) {
        if (value === undefined || value === '') url.searchParams.delete(key);
        else url.searchParams.set(key, String(value));
    }
    for (const key of [...url.searchParams.keys()]) {
        if (key === 'page' || (key.endsWith('_page') && key !== 'per_page')) url.searchParams.delete(key);
    }
    const value = (event.target as HTMLSelectElement).value;
    if (value) url.searchParams.set('docker_host_id', value);
    else url.searchParams.delete('docker_host_id');
    emit('beforeNavigate');
    const id = ++navigationId;
    pending.value = true;
    emit('navigating', true);
    router.get(url.pathname + url.search, {}, {
        preserveScroll: true,
        preserveState: props.preserveState ?? false,
        onFinish: () => {
            if (id !== navigationId) return;
            pending.value = false;
            if (hostSelect.value) hostSelect.value.value = String(props.filters?.docker_host_id ?? '');
            emit('navigating', false);
        },
    });
}
const refresh = () => { if (!pending.value) router.reload(); };
</script>

<template>
    <section class="card mb-4 space-y-3 p-4">
        <div class="flex flex-wrap items-end gap-3">
            <label class="block min-w-48 space-y-1">
                <span class="label">{{ t('hostScope.scope') }}</span>
                <select ref="hostSelect" class="input" :disabled="pending" :value="filters?.docker_host_id ?? ''" @change="selectHost">
                    <option value="">{{ t('hostScope.all') }}</option>
                    <option v-for="host in hosts" :key="host.id" :value="host.id">{{ host.name }} · #{{ host.id }}</option>
                </select>
            </label>
            <button class="btn-secondary" type="button" :disabled="pending" @click="refresh">{{ t('hostScope.refresh') }}</button>
        </div>
        <p class="text-sm text-slate-400">{{ t('hostScope.snapshot') }}</p>
        <div class="flex flex-wrap gap-4">
            <HostIdentity v-for="host in selectedHosts" :key="host.id" :host="host" inventory />
        </div>
    </section>
</template>
