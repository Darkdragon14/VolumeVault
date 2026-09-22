import { computed, onBeforeUnmount, ref, watch, type Ref } from 'vue';
import { destinationMatchesHost, isHostLocalDestination, type ExecutionHost } from './useDeployment';

export type DestinationOperationHost = ExecutionHost & {
    supports_destination_operations: boolean;
    supports_host_bound_destinations?: boolean;
};
export type DestinationObject = { key: string; display_name: string; size: number; last_modified: string | null };
export type DestinationOperation = {
    id: string; destination_id: number; docker_host_id: number; action: 'test' | 'list' | 'stats';
    backup_run_id?: number | null;
    status: 'pending' | 'running' | 'completed'; locator_current: boolean; fresh_until: string | null;
    result: null | { status: 'success' | 'failed'; error_message?: string; data: any };
};
export type ListedObject = DestinationObject & { receipt: DestinationOperation };

export const defaultOperationHost = (destination: any) => isHostLocalDestination(destination) ? Number(destination.docker_host_id ?? 1) : 1;
export function operationHostAvailable(host: DestinationOperationHost | undefined, destination: any): boolean {
    return !!host && destinationMatchesHost(destination, host.id)
        && !!(isHostLocalDestination(destination) ? host.supports_host_bound_destinations : host.supports_destination_operations)
        && !host.maintenance_requested && !host.maintenance_requested_at;
}

export function useDestinationOperations(context: Ref<{ destination: any; hostId: number; backupRunId?: number | null }>) {
    const operation = ref<DestinationOperation | null>(null);
    const objects = ref<ListedObject[]>([]);
    const nextCursor = ref<string | null>(null);
    const error = ref('');
    const pending = ref(false);
    const now = ref(Date.now());
    let generation = 0;
    let controller: AbortController | undefined;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let expiryTimer: ReturnType<typeof setTimeout> | undefined;
    let disposed = false;
    const contextKey = computed(() => JSON.stringify(context.value));
    const reset = () => {
        generation++;
        controller?.abort();
        clearTimeout(timer);
        clearTimeout(expiryTimer);
        operation.value = null;
        objects.value = [];
        nextCursor.value = null;
        error.value = '';
        pending.value = false;
    };
    watch(contextKey, reset, { flush: 'sync' });
    onBeforeUnmount(() => { disposed = true; reset(); });

    const receiptFor = (key: string): string | null => {
        const receipt = objects.value.find((object) => object.key === key)?.receipt;
        return receipt && receipt.locator_current && receipt.destination_id === Number(context.value.destination.id)
            && receipt.docker_host_id === Number(context.value.hostId)
            && (receipt.backup_run_id ?? null) === (context.value.backupRunId ?? null)
            && receipt.fresh_until && new Date(receipt.fresh_until).getTime() > Math.max(now.value, Date.now()) ? receipt.id : null;
    };
    const fresh = computed(() => !!operation.value?.locator_current && !!operation.value.fresh_until
        && new Date(operation.value.fresh_until).getTime() > now.value);

    async function run(action: DestinationOperation['action'], append = false, operationId?: string) {
        if (disposed || pending.value || (append && (!nextCursor.value || context.value.backupRunId != null))) return;
        const cursor = append ? nextCursor.value : null;
        if (!append && action === 'list') { objects.value = []; nextCursor.value = null; }
        const token = ++generation;
        controller?.abort();
        clearTimeout(timer);
        controller = new AbortController();
        const signal = controller.signal;
        const { destination, hostId, backupRunId } = context.value;
        const base = `/destinations/${destination.id}/operations`;
        pending.value = true;
        error.value = '';
        operation.value = null;
        const current = () => !disposed && token === generation;
        const fail = (message: string) => {
            if (!current()) return;
            error.value = message;
            pending.value = false;
            if (message === 'destinationOperations.stale') {
                objects.value.forEach((object) => { object.receipt.locator_current = false; });
                nextCursor.value = null;
            }
        };
        async function request(url: string, body?: object): Promise<void> {
            try {
                const csrf = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice(11);
                const response = await fetch(url, {
                    method: body ? 'POST' : 'GET', signal, credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                        ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf) } : {}) },
                    ...(body ? { body: JSON.stringify(body) } : {}),
                });
                const payload = await response.json();
                if (!current()) return;
                if (!response.ok) {
                    if (payload.errors?.cursor) {
                        objects.value.forEach((object) => { object.receipt.locator_current = false; });
                        nextCursor.value = null;
                    }
                    fail(Object.values(payload.errors ?? {}).flat().join(' ') || payload.message || 'destinationOperations.failed');
                    return;
                }
                const result = payload.data as DestinationOperation;
                if (!result || result.destination_id !== Number(destination.id) || result.docker_host_id !== Number(hostId)
                    || result.action !== action || (operationId && result.id !== operationId)
                    || (result.backup_run_id ?? null) !== (action === 'list' ? backupRunId ?? null : null)
                    || (operation.value && result.id !== operation.value.id) || !result.locator_current) {
                    fail('destinationOperations.stale'); return;
                }
                operation.value = result;
                if (result.status === 'pending' || result.status === 'running') {
                    timer = setTimeout(() => { void request(`${base}/${encodeURIComponent(result.id)}`); }, 1500);
                    return;
                }
                pending.value = false;
                if (result.status !== 'completed' || result.result?.status !== 'success') {
                    fail(result.result?.error_message || 'destinationOperations.failed'); return;
                }
                now.value = Date.now();
                if (!result.fresh_until || new Date(result.fresh_until).getTime() <= now.value) { fail('destinationOperations.stale'); return; }
                if (action === 'list') {
                    if (backupRunId != null && (result.result.data.objects.length > 1 || result.result.data.next_cursor !== null)) {
                        fail('destinationOperations.stale'); return;
                    }
                    const page = (result.result.data.objects as DestinationObject[]).map((object) => ({ ...object, receipt: result }));
                    // A repeated key retains its original page receipt; keys are opaque, never normalized.
                    objects.value = append ? [...objects.value, ...page.filter((object) => !objects.value.some((existing) => existing.key === object.key))] : page;
                    nextCursor.value = result.result.data.next_cursor;
                }
                clearTimeout(expiryTimer);
                const expirations = [result, ...objects.value.map((object) => object.receipt)].map((receipt) => new Date(receipt.fresh_until!).getTime());
                const tick = () => {
                    now.value = Date.now();
                    const next = expirations.filter((time) => time > now.value).sort((a, b) => a - b)[0];
                    if (next) expiryTimer = setTimeout(tick, next - now.value + 1);
                };
                tick();
            } catch (exception) {
                if (!signal.aborted) fail('destinationOperations.failed');
            }
        }
        await request(operationId ? `${base}/${encodeURIComponent(operationId)}` : base,
            operationId ? undefined : { action, docker_host_id: Number(hostId), limit: 100,
                ...(action === 'list' && backupRunId != null ? { backup_run_id: backupRunId } : {}), ...(cursor ? { cursor } : {}) });
    }

    return { operation, objects, nextCursor, error, pending, fresh, now, receiptFor, run, reset,
        resume: (id: string) => run('list', false, id) };
}
