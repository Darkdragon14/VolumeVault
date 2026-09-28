import { flushPromises, mount } from '@vue/test-utils';
import { computed, defineComponent, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DestinationOperations from './DestinationOperations.vue';
import { useDestinationOperations, type DestinationOperation } from '@/Composables/useDestinationOperations';

vi.mock('@/i18n', () => ({ useI18n: () => ({
    t: (key: string, values?: object) => `${key}${values ? JSON.stringify(values) : ''}`,
    formatDate: (date: string) => date,
}) }));

const http = vi.fn<typeof fetch>();
const wrappers: ReturnType<typeof mount>[] = [];
const response = (data: any, status = 200) => ({ ok: status < 400, json: async () => data }) as Response;
const result = (overrides: Partial<DestinationOperation> = {}): DestinationOperation => ({
    id: 'page-one', destination_id: 7, docker_host_id: 1, action: 'list', status: 'completed', locator_current: true,
    fresh_until: new Date(Date.now() + 1800000).toISOString(),
    result: { status: 'success', data: { objects: [{ key: 'id:Exact / A', display_name: 'archive.tar.gz', size: 8, last_modified: null }], next_cursor: 'opaque-cursor' } },
    ...overrides,
});
const hosts = [
    { id: 2, name: 'Agent', supports_destination_operations: true, supports_host_bound_destinations: true },
    { id: 1, name: 'Central', supports_destination_operations: true, supports_host_bound_destinations: true },
    { id: 3, name: 'Old agent', supports_destination_operations: false },
];
function panel(destination = { id: 7, provider: 'aws_s3', name: 'Archive', docker_host_id: 2 }) {
    const wrapper = mount(DestinationOperations, { props: { destination, hosts } });
    wrappers.push(wrapper);
    return wrapper;
}
function harness() {
    const destination = ref({ id: 7, provider: 'aws_s3', path_prefix: 'old' });
    const hostId = ref(1);
    const backupRunId = ref<number | null>(null);
    let state!: ReturnType<typeof useDestinationOperations>;
    const wrapper = mount(defineComponent({ setup() {
        state = useDestinationOperations(computed(() => ({ destination: destination.value, hostId: hostId.value, backupRunId: backupRunId.value })));
        return () => null;
    } }));
    wrappers.push(wrapper);
    return { wrapper, state, destination, hostId, backupRunId };
}
beforeEach(() => {
    vi.useFakeTimers();
    http.mockReset();
    http.mockRejectedValue(new Error('Unexpected HTTP request'));
    vi.stubGlobal('fetch', http);
});
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('destination operations with a fake HTTP transport', () => {
    it('defaults shared storage to central even with agents first, and local storage to its owner', () => {
        expect((panel().get('select').element as HTMLSelectElement).value).toBe('1');
        const local = panel({ id: 7, provider: 'docker_volume', name: 'Archive', docker_host_id: 2 });
        expect((local.get('select').element as HTMLSelectElement).value).toBe('2');
        expect(local.get('select').attributes('disabled')).toBeDefined();
    });

    it('shows pending, running, metrics and freshness; sends the selected host', async () => {
        http.mockResolvedValueOnce(response({ data: result({ action: 'stats', docker_host_id: 2, status: 'pending', result: null, fresh_until: null }) }, 202))
            .mockResolvedValueOnce(response({ data: result({ action: 'stats', docker_host_id: 2, status: 'running', result: null, fresh_until: null }) }))
            .mockResolvedValueOnce(response({ data: result({ action: 'stats', docker_host_id: 2, result: { status: 'success', data: { used_bytes: 2048, object_count: 19 } } }) }));
        const wrapper = panel();
        await wrapper.get('select').setValue('2');
        await wrapper.findAll('button')[1].trigger('click');
        await flushPromises();
        expect(JSON.parse(http.mock.calls[0][1]!.body as string)).toMatchObject({ action: 'stats', docker_host_id: 2 });
        expect(wrapper.text()).toContain('destinationOperations.pending');
        await vi.advanceTimersByTimeAsync(1500);
        expect(wrapper.text()).toContain('destinationOperations.running');
        await vi.advanceTimersByTimeAsync(1500);
        expect(wrapper.text()).toContain('"count":19');
        expect(wrapper.text()).toContain('destinationOperations.fresh');
        expect(http.mock.calls[1][0]).toBe('/destinations/7/operations/page-one');
        await vi.advanceTimersByTimeAsync(1800001);
        expect(wrapper.text()).toContain('destinationOperations.stale');
    });

    it.each(['host', 'locator', 'unmount'])('ignores late responses and cancels polling after %s changes', async (change) => {
        let finish!: (response: Response) => void;
        http.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; }));
        const { state, wrapper, hostId, destination } = harness();
        const request = state.run('list');
        const signal = http.mock.calls[0][1]!.signal!;
        if (change === 'host') hostId.value = 2;
        if (change === 'locator') destination.value.path_prefix = 'changed';
        if (change === 'unmount') wrapper.unmount();
        expect(signal.aborted).toBe(true);
        finish(response({ data: result({ status: 'pending', result: null }) }));
        await request;
        await vi.advanceTimersByTimeAsync(5000);
        expect(state.operation.value).toBeNull();
        expect(state.objects.value).toEqual([]);
        expect(http).toHaveBeenCalledTimes(1);
    });

    it('keeps each exact key tied to its own page receipt, binds the cursor and expires receipts', async () => {
        const { state, hostId } = harness();
        http.mockResolvedValueOnce(response({ data: result() }));
        await state.run('list');
        http.mockResolvedValueOnce(response({ data: result({ id: 'page-two', result: { status: 'success', data: {
            objects: [{ key: 'id:exact / A', display_name: 'archive.tar.gz', size: 9, last_modified: null }], next_cursor: null,
        } } }) }));
        await state.run('list', true);
        expect(JSON.parse(http.mock.calls[1][1]!.body as string)).toEqual({ action: 'list', docker_host_id: 1, limit: 100, cursor: 'opaque-cursor' });
        expect(state.receiptFor('id:Exact / A')).toBe('page-one');
        expect(state.receiptFor('id:exact / A')).toBe('page-two');
        expect(state.receiptFor('archive.tar.gz')).toBeNull();
        await vi.advanceTimersByTimeAsync(1800001);
        expect(state.receiptFor('id:Exact / A')).toBeNull();
        hostId.value = 2;
        expect(state.objects.value).toEqual([]);
        expect(state.nextCursor.value).toBeNull();
    });

    it('ignores a late GET poll response after the host changes and a new host result has arrived', async () => {
        let finish!: (response: Response) => void;
        http.mockResolvedValueOnce(response({ data: result({ status: 'pending', result: null }) }, 202))
            .mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; }))
            .mockResolvedValueOnce(response({ data: result({ id: 'new-host', docker_host_id: 2 }) }));
        const { state, hostId } = harness();
        await state.run('list');
        await vi.advanceTimersByTimeAsync(1500);
        expect(http.mock.calls[1][1]!.method).toBe('GET');
        expect(http.mock.calls[1][0]).toBe('/destinations/7/operations/page-one');
        hostId.value = 2;
        expect(http.mock.calls[1][1]!.signal!.aborted).toBe(true);
        await state.run('list');
        finish(response({ data: result() }));
        await flushPromises();
        expect(state.operation.value?.id).toBe('new-host');
        expect(state.receiptFor('id:Exact / A')).toBe('new-host');
        expect(state.error.value).toBe('');
        await vi.advanceTimersByTimeAsync(5000);
        expect(http).toHaveBeenCalledTimes(3);
    });

    it('clears storage metrics and freshness when switching hosts', async () => {
        http.mockResolvedValueOnce(response({ data: result({ action: 'stats', result: { status: 'success', data: { used_bytes: 2048, object_count: 19 } } }) }));
        const wrapper = panel();
        await wrapper.findAll('button')[1].trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('"count":19');
        await wrapper.get('select').setValue('2');
        expect(wrapper.text()).not.toContain('destinationOperations.usage');
        expect(wrapper.text()).not.toContain('destinationOperations.fresh');
    });

    it('discards generic pagination when switching to an exact historical lookup, including an empty result', async () => {
        const { state, backupRunId } = harness();
        http.mockResolvedValueOnce(response({ data: result() }));
        await state.run('list');
        backupRunId.value = 82;
        expect(state.objects.value).toEqual([]);
        expect(state.nextCursor.value).toBeNull();
        await state.run('list', true);
        expect(http).toHaveBeenCalledTimes(1);
        http.mockResolvedValueOnce(response({ data: result({ backup_run_id: 82, result: { status: 'success', data: { objects: [], next_cursor: null } } }) }));
        await state.run('list');
        expect(JSON.parse(http.mock.calls[1][1]!.body as string)).toEqual({ action: 'list', docker_host_id: 1, limit: 100, backup_run_id: 82 });
        expect(state.objects.value).toEqual([]);
        expect(state.nextCursor.value).toBeNull();
        expect(state.error.value).toBe('');
        await state.run('list', true);
        expect(http).toHaveBeenCalledTimes(2);
    });

    it.each([
        { backup_run_id: null },
        { backup_run_id: 83 },
        { backup_run_id: 82, action: 'stats' as const },
    ])('rejects a preserved historical receipt from a different run or action: %j', async (overrides) => {
        const { state, backupRunId } = harness();
        backupRunId.value = 82;
        http.mockResolvedValueOnce(response({ data: result(overrides) }));
        await state.resume('page-one');
        expect(http.mock.calls[0][1]!.method).toBe('GET');
        expect(state.receiptFor('id:Exact / A')).toBeNull();
        expect(state.objects.value).toEqual([]);
        expect(state.error.value).toBe('destinationOperations.stale');
    });

    it.each([
        [response({ message: 'Invalid cursor', errors: { cursor: ['Expired cursor'] } }, 422), 'Expired cursor'],
        [response({ data: result({ locator_current: false }) }), 'destinationOperations.stale'],
        [response({ data: result({ docker_host_id: 99 }) }), 'destinationOperations.stale'],
        [response({ data: result({ result: { status: 'failed', error_message: 'Access denied', data: null } }) }), 'Access denied'],
    ])('shows validation, context and operation errors without accepting objects', async (reply, message) => {
        http.mockResolvedValueOnce(reply);
        const wrapper = panel();
        await wrapper.findAll('button')[2].trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toBe(message);
        expect(wrapper.find('li').exists()).toBe(false);
        expect(wrapper.findAll('button')[2].attributes('disabled')).toBeUndefined();
    });

    it('stops a scheduled poll on unmount and shows transport errors', async () => {
        const wrapper = panel();
        await wrapper.findAll('button')[0].trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toBe('destinationOperations.failed');
        http.mockResolvedValueOnce(response({ data: result({ action: 'test', status: 'pending', result: null }) }, 202));
        await wrapper.findAll('button')[0].trigger('click');
        await flushPromises();
        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(5000);
        expect(http).toHaveBeenCalledTimes(2);
    });

    it('invalidates earlier receipts if a later page reports a changed locator', async () => {
        const { state } = harness();
        http.mockResolvedValueOnce(response({ data: result() }));
        await state.run('list');
        expect(state.receiptFor('id:Exact / A')).toBe('page-one');
        http.mockResolvedValueOnce(response({ data: result({ id: 'page-two', locator_current: false }) }));
        await state.run('list', true);
        expect(state.receiptFor('id:Exact / A')).toBeNull();
        expect(state.nextCursor.value).toBeNull();
        expect(state.error.value).toBe('destinationOperations.stale');
    });

    it('includes translated operation labels in all nine locales', () => {
        const locales = import.meta.glob('../i18n/locales/*.json', { eager: true, import: 'default' }) as Record<string, Record<string, string>>;
        const keys = Object.keys(locales['../i18n/locales/en.json']).filter((key) => key.startsWith('destinationOperations.'));
        expect(Object.keys(locales)).toHaveLength(9);
        for (const locale of Object.values(locales)) {
            for (const key of keys) expect(locale[key], key).toBeTruthy();
        }
    });
});
