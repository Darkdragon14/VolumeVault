<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { defaultOperationHost, operationHostAvailable, useDestinationOperations, type DestinationOperationHost } from '@/Composables/useDestinationOperations';
import { isHostLocalDestination } from '@/Composables/useDeployment';
import { formatBytes } from '@/Composables/useFormatBytes';
import { useI18n } from '@/i18n';

const props = defineProps<{ destination: any; hosts: DestinationOperationHost[] }>();
const { t, formatDate } = useI18n();
const selectedHost = ref(defaultOperationHost(props.destination));
watch(() => props.destination, () => { selectedHost.value = defaultOperationHost(props.destination); }, { deep: true });
const context = computed(() => ({ destination: props.destination, hostId: Number(selectedHost.value) }));
const { operation, objects, nextCursor, error, pending, fresh, run } = useDestinationOperations(context);
const available = computed(() => operationHostAvailable(props.hosts.find((host) => Number(host.id) === Number(selectedHost.value)), props.destination));
</script>

<template>
    <section class="card my-4 space-y-4 p-4" :aria-label="t('destinationOperations.title')">
        <h2 class="font-semibold">{{ destination.name }} — {{ t('destinationOperations.title') }}</h2>
        <p class="text-sm text-slate-400">{{ t('destinationOperations.saved') }}</p>
        <label class="block space-y-2">
            <span class="label">{{ t('destinationOperations.host') }}</span>
            <select v-model="selectedHost" class="input" :disabled="isHostLocalDestination(destination)" data-operation-host>
                <option v-for="host in hosts" :key="host.id" :value="host.id" :disabled="!operationHostAvailable(host, destination)">{{ host.name }}{{ operationHostAvailable(host, destination) ? '' : ` — ${t('destinationOperations.unsupported')}` }}</option>
            </select>
        </label>
        <p v-if="!available" role="status" class="text-sm text-amber-600 dark:text-amber-200">{{ t('destinationOperations.unsupported') }}</p>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn-secondary" :disabled="pending || !available" @click="run('test')">{{ t('Test') }}</button>
            <button type="button" class="btn-secondary" :disabled="pending || !available" @click="run('stats')">{{ t('destinationOperations.stats') }}</button>
            <button type="button" class="btn-secondary" :disabled="pending || !available" @click="run('list')">{{ t('destinationOperations.browse') }}</button>
        </div>
        <p v-if="pending" role="status">{{ t(operation?.status === 'running' ? 'destinationOperations.running' : 'destinationOperations.pending') }}</p>
        <p v-if="error" role="alert" class="text-sm text-rose-600 dark:text-rose-300">{{ t(error) }}</p>
        <template v-if="operation?.result?.status === 'success'">
            <p v-if="operation.action === 'test'" role="status">{{ t('destinationOperations.connected') }}</p>
            <p v-if="operation.action === 'stats'">{{ t('destinationOperations.usage', { bytes: formatBytes(operation.result.data.used_bytes), count: operation.result.data.object_count }) }}</p>
            <p class="text-sm text-slate-400">{{ fresh ? t('destinationOperations.fresh', { date: formatDate(operation.fresh_until) }) : t('destinationOperations.stale') }}</p>
        </template>
        <ul v-if="objects.length" class="space-y-2">
            <li v-for="object in objects" :key="object.key" class="break-all text-sm">
                <strong>{{ object.display_name }}</strong><code class="block">{{ object.key }}</code>
                {{ formatBytes(object.size) }} · {{ formatDate(object.last_modified) }}
            </li>
        </ul>
        <p v-else-if="operation?.action === 'list' && operation.result?.status === 'success'">{{ t('No backup objects found. Run a backup first or check the destination path.') }}</p>
        <button v-if="nextCursor" type="button" class="btn-secondary" :disabled="pending || !available" @click="run('list', true)">{{ t('destinationOperations.more') }}</button>
    </section>
</template>
