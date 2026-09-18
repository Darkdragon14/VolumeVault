import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export type ExecutionHost = {
    id: number;
    name: string;
    driver?: string;
    agent_capabilities?: string[];
    capabilities?: string[];
    agent_protocol_version?: number;
    protocol_version?: number;
    agent_revoked_at?: string | null;
    agent_registered_at?: string | null;
    maintenance_requested_at?: string | null;
    maintenance_requested?: boolean;
    maintenance_state?: string;
    agent_maintenance_state?: string;
    status?: string;
    compatibility?: string;
    agent_host_path_allowlist?: string[];
    host_path_allowlist?: string[];
};

export const hostId = (resource: { docker_host_id?: number | null }) => Number(resource.docker_host_id ?? 1);
export const isHostLocalDestination = (destination: { provider?: string } | null | undefined) => ['local', 'docker_volume'].includes(destination?.provider ?? '');
export const destinationMatchesHost = (destination: any, id: number) => !isHostLocalDestination(destination) || hostId(destination) === Number(id);

export function hostSupports(host: ExecutionHost | undefined, capability: 'backup-v1' | 'restore-v1', localEnabled: boolean): boolean {
    if (!host || host.agent_revoked_at || host.maintenance_requested_at || host.maintenance_requested
        || [host.maintenance_state, host.agent_maintenance_state].some((state) => state && state !== 'active')
        || ['pending', 'revoked', 'incompatible'].includes(host.status ?? '')
        || host.compatibility === 'incompatible') return false;
    if (Number(host.id) === 1 && (!host.driver || host.driver === 'local')) return localEnabled;
    return (host.agent_protocol_version ?? host.protocol_version ?? 1) === 1
        && (host.agent_capabilities ?? host.capabilities ?? []).includes(capability);
}

export function deploymentFlag(value: unknown, fallback = true): boolean {
    if (value === undefined || value === null) {
        return fallback;
    }

    return value === true || value === 1 || value === '1' || value === 'true';
}

export function useDeployment() {
    const page = usePage();
    const deployment = computed(() => page.props.deployment as { mode?: 'hybrid' | 'orchestrator'; local_execution_enabled?: boolean } | undefined);
    const localExecutionEnabled = computed(() => deploymentFlag(deployment.value?.local_execution_enabled));
    const permissions = computed(() => page.props.can as { runDockerActions?: boolean; runRemoteBackupActions?: boolean; manageSensitiveData?: boolean } | undefined);
    const canManageBackups = computed(() => Boolean(permissions.value?.manageSensitiveData || permissions.value?.runRemoteBackupActions
        || (localExecutionEnabled.value && permissions.value?.runDockerActions)));
    const executionHosts = (hosts?: ExecutionHost[]): ExecutionHost[] => hosts ?? (localExecutionEnabled.value ? [{ id: 1, name: 'Local', driver: 'local' }] : []);
    const canExecute = (host: ExecutionHost | undefined, capability: 'backup-v1' | 'restore-v1') => canManageBackups.value && hostSupports(host, capability, localExecutionEnabled.value);
    const resourceHost = (resource: any): ExecutionHost | undefined => resource.docker_host
        ?? (page.props.hosts as ExecutionHost[] | undefined)?.find((host) => Number(host.id) === hostId(resource))
        ?? (hostId(resource) === 1 ? { id: 1, name: 'Local', driver: 'local' } : undefined);

    return {
        deploymentMode: computed(() => deployment.value?.mode ?? 'hybrid'),
        localExecutionEnabled,
        canManageBackups,
        executionHosts,
        canExecute,
        resourceHost,
        localDockerPermissions: computed(() => ({
            runDockerActions: localExecutionEnabled.value && Boolean((page.props.can as { runDockerActions?: boolean } | undefined)?.runDockerActions),
        })),
    };
}
