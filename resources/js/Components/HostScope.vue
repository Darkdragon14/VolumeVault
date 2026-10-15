<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import HostIdentity from '@/Components/HostIdentity.vue';

const props = defineProps<{ hosts?: any[]; filters?: { docker_host_id?: number | null } }>();
const { t } = useI18n();
const selectedHosts = computed(() => (props.hosts ?? []).filter(host => !props.filters?.docker_host_id || host.id === props.filters.docker_host_id));
const refresh = () => router.reload();
</script>

<template>
    <section class="card mb-4 flex flex-col gap-3 p-4">
        <div class="flex flex-wrap items-end gap-3">
            <button class="btn-secondary" type="button" @click="refresh">{{ t('hostScope.refresh') }}</button>
        </div>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('hostScope.snapshot') }}</p>
        <div class="flex flex-wrap gap-4">
            <HostIdentity v-for="host in selectedHosts" :key="host.id" :host="host" inventory />
        </div>
    </section>
</template>
