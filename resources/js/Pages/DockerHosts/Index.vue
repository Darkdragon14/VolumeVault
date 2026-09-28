<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { deploymentFlag, useDeployment } from '@/Composables/useDeployment';
import { useI18n } from '@/i18n';
import { Head, router, useHttp, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface DockerHost {
    id: number;
    uuid: string;
    name: string;
    driver: 'local' | 'agent';
    status: 'local' | 'pending' | 'online' | 'offline' | 'revoked';
    last_seen_at: string | null;
    last_inventory_at: string | null;
    agent_version: string | null;
    docker_version: string | null;
    docker_status: 'ready' | 'unavailable' | null;
    volume_count: number;
    container_count: number | null;
    role?: 'hybrid' | 'orchestrator' | 'agent';
    local_execution_enabled?: boolean;
    protocol_version?: number | null;
    capabilities?: string[];
    compatibility?: 'compatible' | 'incompatible' | 'unknown';
    update_status?: 'current' | 'available' | 'ahead' | 'unknown';
    target_version?: string | null;
    maintenance_requested?: boolean;
    maintenance_ready?: boolean;
    active_operations?: number;
}

interface Enrollment {
    host: { id: number; uuid: string; name: string };
    installation: { command: string; expires_at: string };
}

interface Maintenance {
    maintenance_requested: boolean;
    maintenance_ready: boolean;
    active_operations: number;
}

interface UpdateGuide {
    image: string;
    version: string;
    container_name: string;
    volume_name: string;
    command: string;
    maintenance_ready: boolean;
    uses_existing_identity: boolean;
}

const props = defineProps<{
    hosts: DockerHost[];
    agentsEnabled: boolean;
    agentUrl: string;
    deploymentMode?: 'hybrid' | 'orchestrator';
    targetAgentImage?: string;
    orchestratorVersion?: string;
}>();
const { t, formatDate } = useI18n();
const { deploymentMode: sharedMode, localExecutionEnabled } = useDeployment();
const create = useHttp<{ name: string }, Enrollment>({ name: '' });
const enrollment = useHttp<Record<string, never>, Pick<Enrollment, 'installation'>>({});
const revocation = useHttp<Record<string, never>, { revoked: boolean }>({});
const maintenance = useHttp<{ enabled: boolean }, Maintenance>({ enabled: false });
const guideRequest = useHttp<Record<string, never>, UpdateGuide>({});
const maintenanceStates = ref<Record<number, Maintenance>>({});
const guideHost = ref<DockerHost | null>(null);
const guide = ref<UpdateGuide | null>(null);
const guideError = ref('');
const guideCopyStatus = ref('');
const guideField = ref<HTMLTextAreaElement | null>(null);
const installation = ref<Enrollment | null>(null);
const commandField = ref<HTMLTextAreaElement | null>(null);
const error = ref('');
const copyStatus = ref('');
const now = ref(Date.now());
const busy = computed(() => create.processing || enrollment.processing || revocation.processing || maintenance.processing || guideRequest.processing);
const expired = computed(() => installation.value !== null && Date.parse(installation.value.installation.expires_at) <= now.value);
let clock: ReturnType<typeof setInterval> | undefined;
let guideGeneration = 0;

usePoll(30_000, { only: ['hosts'] });

const hostRole = (host: DockerHost) => host.role ?? (host.driver === 'agent' ? 'agent' : props.deploymentMode ?? sharedMode.value);
const hasDocker = (host: DockerHost) => host.driver === 'agent' || hostRole(host) !== 'orchestrator';
const displayVersion = (version?: string | null) => version && /^(main|dev|development)$/i.test(version)
    ? t('dockerHosts.developmentBuild', { version })
    : version || '—';
const hostState = (host: DockerHost) => maintenanceStates.value[host.id] ?? host;
const isMaintaining = (host: DockerHost) => deploymentFlag(hostState(host).maintenance_requested, false);
const isReady = (host: DockerHost) => deploymentFlag(hostState(host).maintenance_ready, false);
const canMaintain = (host: DockerHost) => host.driver === 'agent'
    ? host.status !== 'pending' && host.status !== 'revoked'
    : hostRole(host) === 'hybrid' && localExecutionEnabled.value && deploymentFlag(host.local_execution_enabled);

const clearGuide = () => {
    guideGeneration++;
    guide.value = null;
    guideRequest.response = null;
    guideCopyStatus.value = '';
};
const closeGuide = () => {
    guideRequest.cancel();
    guideHost.value = null;
    guideError.value = '';
    clearGuide();
};

watch(() => props.hosts, () => {
    maintenanceStates.value = {};
    if (guideHost.value) {
        const host = props.hosts.find((item) => item.id === guideHost.value?.id);
        if (!host || host.status === 'revoked' || !isMaintaining(host) || !isReady(host)) {
            clearGuide();
        }
    }
});

onMounted(() => {
    clock = setInterval(() => now.value = Date.now(), 1000);
});

const dismiss = () => {
    installation.value = null;
    create.response = null;
    enrollment.response = null;
    copyStatus.value = '';
};

onBeforeUnmount(() => {
    clearInterval(clock);
    create.cancel();
    enrollment.cancel();
    revocation.cancel();
    maintenance.cancel();
    closeGuide();
    dismiss();
});

const refreshHosts = () => router.reload({ only: ['hosts'] });
const toggleMaintenance = async (host: DockerHost) => {
    if (busy.value || !canMaintain(host)) {
        return;
    }
    error.value = '';
    maintenance.enabled = !isMaintaining(host);
    if (guideHost.value?.id === host.id) {
        closeGuide();
    }
    try {
        maintenanceStates.value[host.id] = await maintenance.post(`/docker-hosts/${host.id}/maintenance`);
        refreshHosts();
    } catch {
        error.value = t('dockerHosts.maintenanceFailed');
    } finally {
        maintenance.response = null;
    }
};

const loadGuide = async (host: DockerHost) => {
    if (busy.value || host.driver !== 'agent' || host.status === 'revoked') {
        return;
    }
    guideHost.value = host;
    guideError.value = '';
    clearGuide();
    const generation = guideGeneration;
    try {
        const response = await guideRequest.get(`/docker-hosts/${host.id}/update-guide`);
        if (!deploymentFlag(response.maintenance_ready, false) || !deploymentFlag(response.uses_existing_identity, false) || !response.command) {
            throw new Error('Guide unavailable');
        }
        if (generation === guideGeneration && guideHost.value?.id === host.id) {
            guide.value = response;
        }
    } catch {
        if (generation === guideGeneration && guideHost.value?.id === host.id) {
            guideError.value = t('dockerHosts.guideFailed');
        }
    } finally {
        guideRequest.response = null;
    }
};

const copyGuide = async () => {
    if (!guide.value) {
        return;
    }
    try {
        await navigator.clipboard.writeText(guide.value.command);
        guideCopyStatus.value = t('dockerHosts.copied');
    } catch {
        guideField.value?.focus();
        guideField.value?.select();
        guideCopyStatus.value = t('dockerHosts.copyManually');
    }
};
const showInstallation = (response: Enrollment) => {
    dismiss();
    now.value = Date.now();
    installation.value = response;
    refreshHosts();
};

const submit = async () => {
    if (!props.agentsEnabled || busy.value) {
        return;
    }

    error.value = '';
    try {
        showInstallation(await create.post('/docker-hosts'));
        create.reset();
    } catch {
        error.value = t('dockerHosts.requestFailed');
    }
};

const renew = async (host: DockerHost) => {
    if (!props.agentsEnabled || host.driver === 'local' || busy.value || !confirm(t('dockerHosts.confirmRenew', { name: host.name }))) {
        return;
    }

    error.value = '';
    if (installation.value?.host.id === host.id) {
        dismiss();
    }
    if (guideHost.value?.id === host.id) {
        closeGuide();
    }
    try {
        const response = await enrollment.post(`/docker-hosts/${host.id}/enrollment`);
        showInstallation({ host: { id: host.id, uuid: host.uuid, name: host.name }, installation: response.installation });
    } catch {
        error.value = t('dockerHosts.requestFailed');
    }
};

const revoke = async (host: DockerHost) => {
    if (host.driver === 'local' || host.status === 'revoked' || busy.value || !confirm(t('dockerHosts.confirmRevoke', { name: host.name }))) {
        return;
    }

    error.value = '';
    try {
        await revocation.delete(`/docker-hosts/${host.id}/agent`);
        if (guideHost.value?.id === host.id) {
            closeGuide();
        }
        if (installation.value?.host.id === host.id) {
            dismiss();
        }
        refreshHosts();
    } catch {
        error.value = t('dockerHosts.requestFailed');
    }
};

const copyCommand = async () => {
    if (!installation.value || expired.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(installation.value.installation.command);
        copyStatus.value = t('dockerHosts.copied');
    } catch {
        commandField.value?.focus();
        commandField.value?.select();
        copyStatus.value = t('dockerHosts.copyManually');
    }
};

const statusClass = (status: DockerHost['status']) => ({
    local: 'border-sky-300/30 bg-sky-400/10 text-sky-100',
    pending: 'border-amber-300/40 bg-amber-300/10 text-amber-100',
    online: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-100',
    offline: 'border-rose-400/30 bg-rose-400/10 text-rose-100',
    revoked: 'border-white/10 bg-white/5 text-slate-400',
}[status]);
</script>

<template>
    <AppLayout :title="t('dockerHosts.nav')" :subtitle="t('dockerHosts.subtitle')">
        <Head :title="t('dockerHosts.nav')" />
        <div class="flex flex-col gap-6">
            <p class="rounded-2xl border border-sky-300/30 bg-sky-400/10 p-4 text-sm text-sky-100">{{ t(hosts.some((host) => host.driver === 'agent' && host.capabilities?.some((capability) => ['backup-v1', 'restore-v1'].includes(capability))) ? 'hostWorkflow.agentExecution' : 'hostWorkflow.agentUpgrade') }}</p>
            <p class="text-sm text-slate-400">{{ t(`dockerHosts.role.${deploymentMode ?? sharedMode}`) }} · {{ t('dockerHosts.orchestratorVersion') }}: {{ displayVersion(orchestratorVersion) }}<br>{{ t('dockerHosts.agentImage') }}: <span class="break-all font-mono">{{ targetAgentImage || '—' }}</span></p>

            <section v-if="!agentsEnabled" class="card flex flex-col gap-3 p-4 sm:p-6">
                <h2 class="text-lg font-semibold text-white">{{ t('dockerHosts.disabled') }}</h2>
                <p class="text-sm text-slate-400">{{ t('dockerHosts.enableHelp') }}</p>
                <code class="break-all rounded-xl bg-slate-950/80 p-3 text-sm text-slate-200">VOLUMEVAULT_AGENTS_ENABLED=true<br>VOLUMEVAULT_AGENT_URL=https://host:8443</code>
            </section>

            <form v-else class="card flex flex-col gap-4 p-4 sm:p-6" @submit.prevent="submit">
                <h2 class="text-lg font-semibold text-white">{{ t('dockerHosts.add') }}</h2>
                <p class="break-all text-sm text-slate-400">{{ t('dockerHosts.endpoint') }}: {{ agentUrl }}</p>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <label class="flex min-w-0 flex-1 flex-col gap-2" for="host-name">
                        <span class="label">{{ t('Name') }}</span>
                        <input id="host-name" v-model="create.name" class="input" required maxlength="100" :disabled="busy" :aria-invalid="Boolean(create.errors.name)" aria-describedby="host-name-error">
                        <span v-if="create.errors.name" id="host-name-error" class="text-sm text-rose-300">{{ create.errors.name }}</span>
                    </label>
                    <button class="btn-primary" :disabled="busy || !create.name.trim()">{{ t('dockerHosts.add') }}</button>
                </div>
            </form>

            <p v-if="error" role="alert" class="rounded-2xl border border-rose-400/30 bg-rose-400/10 p-4 text-sm text-rose-100">{{ error }}</p>

            <section v-if="installation" class="flex flex-col gap-3 rounded-2xl border border-amber-300/40 bg-amber-300/10 p-4 sm:p-6">
                <h2 class="break-words text-lg font-semibold text-white">{{ t('dockerHosts.install', { name: installation.host.name }) }}</h2>
                <p class="text-sm text-amber-100">{{ t('dockerHosts.oneTime') }}</p>
                <label for="installation-command" class="label">{{ t('dockerHosts.command') }}</label>
                <textarea id="installation-command" ref="commandField" :value="installation.installation.command" readonly spellcheck="false" autocomplete="off" rows="5" class="input font-mono text-xs" />
                <p class="text-sm text-amber-100">{{ t('Expires') }}: {{ formatDate(installation.installation.expires_at) }}</p>
                <p v-if="expired" role="status" class="text-sm text-amber-100">{{ t('dockerHosts.expired') }}</p>
                <div class="flex flex-wrap gap-3">
                    <button type="button" class="btn-secondary" :disabled="expired" @click="copyCommand">{{ t('dockerHosts.copy') }}</button>
                    <button type="button" class="btn-secondary" @click="dismiss">{{ t('Close') }}</button>
                </div>
                <p v-if="copyStatus" role="status" class="text-sm text-amber-100">{{ copyStatus }}</p>
            </section>

            <section v-if="guideHost" class="card flex flex-col gap-4 p-4 sm:p-6" data-update-guide>
                <h2 class="text-lg font-semibold text-white">{{ t('dockerHosts.updateGuide') }} — {{ guideHost.name }}</h2>
                <p class="text-sm text-slate-300">{{ t('dockerHosts.guideSteps') }}</p>
                <p class="text-sm text-amber-200">{{ t('dockerHosts.preserveDeployment') }}</p>
                <p v-if="guideError" role="alert" class="text-sm text-rose-300">{{ guideError }}</p>
                <template v-if="guide">
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-400">{{ t('dockerHosts.agentImage') }}</dt><dd class="break-all text-slate-200">{{ guide.image }}</dd></div>
                        <div><dt class="text-slate-400">{{ t('dockerHosts.targetVersion') }}</dt><dd class="text-slate-200">{{ guide.version }}</dd></div>
                        <div><dt class="text-slate-400">{{ t('dockerHosts.containerName') }}</dt><dd class="break-all text-slate-200">{{ guide.container_name }}</dd></div>
                        <div><dt class="text-slate-400">{{ t('dockerHosts.identityVolume') }}</dt><dd class="break-all text-slate-200">{{ guide.volume_name }}</dd></div>
                    </dl>
                    <label for="update-command" class="label">{{ t('dockerHosts.updateCommand') }}</label>
                    <textarea id="update-command" ref="guideField" :value="guide.command" readonly spellcheck="false" autocomplete="off" rows="6" class="input font-mono text-xs" />
                </template>
                <div class="flex flex-wrap gap-3">
                    <button v-if="guide" type="button" class="btn-secondary" @click="copyGuide">{{ t('dockerHosts.copy') }}</button>
                    <button type="button" class="btn-secondary" :disabled="busy" @click="loadGuide(guideHost)">{{ t('dockerHosts.reloadGuide') }}</button>
                    <button type="button" class="btn-secondary" @click="closeGuide">{{ t('Close') }}</button>
                </div>
                <p v-if="guideCopyStatus" role="status" class="text-sm text-slate-300">{{ guideCopyStatus }}</p>
            </section>

            <div class="grid gap-6 md:grid-cols-2">
                <article v-for="host in hosts" :key="host.uuid" :data-host-id="host.id" class="card flex min-w-0 flex-col gap-4 p-4 sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="min-w-0 break-words text-lg font-semibold text-white">{{ host.name }}</h2>
                        <span class="shrink-0 rounded-full border px-2.5 py-1 text-xs font-semibold" :class="statusClass(host.status)">{{ t(`dockerHosts.status.${host.status}`) }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full border border-white/10 px-2.5 py-1">{{ t(`dockerHosts.role.${hostRole(host)}`) }}</span>
                        <span v-if="host.driver === 'agent'" class="rounded-full border px-2.5 py-1" :class="host.compatibility === 'incompatible' ? 'border-rose-400/30 text-rose-300' : 'border-white/10 text-slate-300'">{{ t(`dockerHosts.compatibility.${host.compatibility ?? 'unknown'}`) }}</span>
                        <span v-if="host.driver === 'agent'" class="rounded-full border border-white/10 px-2.5 py-1 text-slate-300">{{ t(`dockerHosts.update.${host.update_status ?? 'unknown'}`) }}</span>
                    </div>
                    <p v-if="host.status === 'pending'" class="text-sm text-slate-400">{{ t('dockerHosts.pendingHelp') }}</p>
                    <dl class="grid grid-cols-2 gap-4 text-sm">
                        <template v-if="hasDocker(host)">
                            <div><dt class="text-slate-400">{{ t('Volumes') }}</dt><dd class="text-slate-200">{{ host.volume_count }}</dd></div>
                            <div><dt class="text-slate-400">{{ t('Containers') }}</dt><dd class="text-slate-200">{{ host.container_count ?? '—' }}</dd></div>
                        </template>
                        <div v-if="host.driver === 'agent'"><dt class="text-slate-400">{{ t('dockerHosts.lastContact') }}</dt><dd class="break-words text-slate-200">{{ host.last_seen_at ? formatDate(host.last_seen_at) : '—' }}</dd></div>
                        <div v-if="hasDocker(host)" class="col-span-2">
                            <dt class="text-slate-400">{{ t(host.driver === 'local' ? 'dockerHosts.lastVolumeSync' : 'dockerHosts.lastInventory') }}</dt>
                            <dd class="break-words text-slate-200">{{ host.last_inventory_at ? formatDate(host.last_inventory_at) : '—' }}</dd>
                            <dd class="mt-1 text-xs text-slate-400">{{ t(host.driver === 'local' ? 'dockerHosts.lastVolumeSyncHelp' : 'dockerHosts.lastInventoryHelp') }}</dd>
                        </div>
                        <div><dt class="text-slate-400">{{ t(host.driver === 'local' ? 'dockerHosts.orchestratorVersion' : 'dockerHosts.version') }}</dt><dd class="break-words text-slate-200">{{ displayVersion(host.agent_version) }}</dd></div>
                        <template v-if="hasDocker(host)">
                            <div><dt class="text-slate-400">{{ t('dockerHosts.dockerAvailability') }}</dt><dd class="text-slate-200">{{ host.docker_status ? t(`dockerHosts.docker.${host.docker_status}`) : '—' }}</dd></div>
                            <div><dt class="text-slate-400">{{ t('dockerHosts.dockerVersion') }}</dt><dd class="break-words text-slate-200">{{ host.docker_version || '—' }}</dd></div>
                        </template>
                        <template v-if="host.driver === 'agent'">
                            <div><dt class="text-slate-400">{{ t('dockerHosts.targetVersion') }}</dt><dd class="text-slate-200">{{ host.target_version || '—' }}</dd></div>
                            <div><dt class="text-slate-400">{{ t('dockerHosts.protocol') }}</dt><dd class="text-slate-200">{{ host.protocol_version ?? '—' }}</dd></div>
                        </template>
                    </dl>
                    <p v-if="host.capabilities?.length" class="break-words text-xs text-slate-400">{{ t('dockerHosts.capabilities') }}: {{ host.capabilities.join(', ') }}</p>
                    <p v-if="host.driver === 'agent'" class="text-xs text-slate-400">{{ t(host.capabilities?.includes('backup-v1') ? 'hostWorkflow.backupAvailable' : 'hostWorkflow.backupUnavailable') }} · {{ t(host.capabilities?.includes('restore-v1') ? 'hostWorkflow.restoreAvailable' : 'hostWorkflow.restoreUnavailable') }}</p>
                    <p v-if="isMaintaining(host)" role="status" class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-3 text-sm text-amber-100">{{ t(isReady(host) ? 'dockerHosts.maintenanceReady' : host.driver === 'local' ? 'dockerHosts.localMaintenancePending' : 'dockerHosts.maintenancePending') }} · {{ t('dockerHosts.activeOperations', { count: hostState(host).active_operations ?? 0 }) }}</p>
                    <p v-else-if="hostState(host).active_operations != null" class="text-sm text-slate-400">{{ t('dockerHosts.activeOperations', { count: hostState(host).active_operations }) }}</p>
                    <p v-if="host.driver === 'local'" class="text-sm text-slate-400">{{ t('dockerHosts.localUpdate') }}</p>
                    <div v-if="canMaintain(host)" class="flex flex-col gap-3 border-t border-white/10 pt-4">
                        <p class="text-sm text-slate-400">{{ t(host.driver === 'local' ? 'dockerHosts.localMaintenanceHelp' : 'dockerHosts.maintenanceHelp') }}</p>
                        <div class="flex flex-wrap gap-3">
                            <button type="button" class="btn-secondary" :disabled="busy" @click="toggleMaintenance(host)">{{ t(isMaintaining(host) ? 'dockerHosts.resume' : 'dockerHosts.enterMaintenance') }}</button>
                            <button v-if="host.driver === 'agent'" type="button" class="btn-secondary" :disabled="busy" @click="loadGuide(host)">{{ t('dockerHosts.updateGuide') }}</button>
                        </div>
                    </div>
                    <div v-if="host.driver === 'agent'" class="flex flex-wrap gap-3 border-t border-white/10 pt-4">
                        <button type="button" class="btn-secondary" :disabled="!agentsEnabled || busy" @click="renew(host)">{{ t('dockerHosts.renew') }}</button>
                        <button v-if="host.status !== 'revoked'" type="button" class="btn-danger" :disabled="busy" @click="revoke(host)">{{ t('Revoke') }}</button>
                    </div>
                </article>
            </div>
        </div>
    </AppLayout>
</template>
