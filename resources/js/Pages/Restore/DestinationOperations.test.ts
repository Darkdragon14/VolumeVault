import { createInertiaApp, router } from '@inertiajs/vue3';
import type { HttpClient, Page } from '@inertiajs/core';
import { flushPromises, VueWrapper } from '@vue/test-utils';
import { createApp, h, type App } from 'vue';
import { afterEach, expect, it, vi } from 'vitest';
import RestoreCreate from './Create.vue';

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<main><slot /></main>' } }));
vi.mock('@/i18n', () => ({ useI18n: () => ({
    t: (key: string) => key, formatDate: (value: string | null) => value ?? '-', timezone: { value: 'UTC' },
}) }));

let app: App | undefined;
afterEach(() => {
    router.cancelAll();
    app?.unmount();
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

const hosts = [1, 2, 3].map((id) => ({ id, name: `Host ${id}`, driver: id === 1 ? 'local' : 'agent',
    agent_capabilities: id === 3 ? ['restore-v1'] : ['restore-v1', 'destination-v1'],
    supports_destination_operations: id !== 3, supports_host_bound_destinations: id !== 3,
}));
const relayHosts = hosts.map((host) => ({ ...host,
    agent_registered_at: host.id === 1 ? null : '2026-09-22T10:00:00Z',
    agent_protocol_version: host.id === 1 ? null : 1,
    agent_capabilities: host.id === 1 ? [] : [...host.agent_capabilities, 'archive-relay-v1'],
}));
const operation = (id: string, host: number, key: string, cursor: string | null = null, backupRunId: number | null = null) => ({
    data: { id, destination_id: 9, docker_host_id: host, backup_run_id: backupRunId, action: 'list', status: 'completed', locator_current: true,
        fresh_until: new Date(Date.now() + 1800000).toISOString(), result: { status: 'success', data: {
            objects: [{ key, display_name: 'same-name.tar.gz', size: 1, last_modified: null }], next_cursor: cursor,
        } } },
});
const json = (body: any) => ({ ok: true, json: async () => body }) as Response;

async function setup(overrides = {}, url = '/backup-jobs/7/restore') {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    window.history.replaceState({}, '', url);
    document.body.innerHTML = '<div id="app"></div>';
    const page: Page = {
        component: 'Restore/Create', url, version: null, clearHistory: false, encryptHistory: false,
        props: { errors: {}, can: { manageSensitiveData: true }, deployment: { local_execution_enabled: true },
            hosts, destinationOperationHosts: hosts, volumes: [],
            job: { id: 7, name: 'Job', docker_host_id: 1, destination: { id: 9, provider: 'aws_s3' } },
            restoreDestination: { id: 9, name: 'Archives', provider: 'aws_s3' },
            destinationOperations: { destination_id: 9, default_docker_host_id: 1, selected_docker_host_id: 1 },
            backups: [], backupRunId: null, preselectedBackupKey: null, isDockerVolumeSource: true,
            sourceVolumeName: 'data', sourceLabel: 'data', sourceDockerHostId: 1, targetDockerHostId: 1,
            generatedTargetVolumeName: 'data-restored', ...overrides,
        },
    };
    const request = vi.fn<HttpClient['request']>().mockResolvedValue({ status: 200, headers: { 'x-inertia': 'true' }, data: JSON.stringify(page) });
    let wrapper!: VueWrapper;
    await createInertiaApp({ page, resolve: () => RestoreCreate, http: { request }, progress: false,
        setup({ el, App, props, plugin }) {
            app = createApp({ render: () => h(App, props) });
            app.use(plugin);
            wrapper = new VueWrapper(app, app.mount(el));
        },
    });
    await flushPromises();
    const button = (label: string) => wrapper.findAll('button').find((button) => button.text() === label)!;
    const form = () => (wrapper.findComponent(RestoreCreate).vm as any).form;
    return { wrapper, button, form, request, page };
}

it('submits the selected exact key with its page receipt, then invalidates and reloads on target changes', async () => {
    const fetch = vi.fn<typeof globalThis.fetch>()
        .mockResolvedValueOnce(json(operation('receipt-first', 1, 'id:ABC / exact', 'next')))
        .mockResolvedValueOnce(json(operation('receipt-second', 1, 'id:abc / exact')))
        .mockResolvedValueOnce(json(operation('receipt-agent', 2, 'id:Remote')));
    vi.stubGlobal('fetch', fetch);
    const { wrapper, button, form, request } = await setup();
    await wrapper.get('input[type="checkbox"]').setValue(true);
    await wrapper.get('input[value="id:ABC / exact"]').setValue();
    expect(form().destination_operation_id).toBe('receipt-first');
    await button('destinationOperations.more').trigger('click');
    await flushPromises();
    expect(form().destination_operation_id).toBe('receipt-first');
    await wrapper.get('input[value="id:abc / exact"]').setValue();
    expect(form().destination_operation_id).toBe('receipt-second');
    await button('Continue').trigger('click');
    await wrapper.get('input[value="safe_inplace"]').setValue();
    form().confirmation_text = 'data';
    form().backup_before_overwrite = true;
    await wrapper.get('[data-target-host]').setValue('2');
    await flushPromises();
    expect(form()).toMatchObject({ destination_operation_id: null, selected_backup_key: '', mode: 'new_volume', confirmation_text: '', backup_before_overwrite: false });
    expect(wrapper.find('input[value="id:abc / exact"]').exists()).toBe(false);
    expect(JSON.parse(fetch.mock.calls[2][1]!.body as string)).toMatchObject({ action: 'list', docker_host_id: 2 });
    await wrapper.get('input[value="id:Remote"]').setValue();
    await button('Continue').trigger('click');
    await button('Continue').trigger('click');
    await button('Queue restore').trigger('click');
    await flushPromises();
    expect(request.mock.calls[0][0]).toMatchObject({ method: 'post', data: {
        target_docker_host_id: 2, selected_backup_key: 'id:Remote', destination_operation_id: 'receipt-agent', backup_run_id: null,
    } });
});

it('ignores a delayed listing after switching host and keeps pending/failure states visible', async () => {
    let finish!: (response: Response) => void;
    const fetch = vi.fn<typeof globalThis.fetch>()
        .mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; }))
        .mockResolvedValueOnce({ ok: false, json: async () => ({ errors: { docker_host_id: ['Host is in maintenance'] } }) } as Response);
    vi.stubGlobal('fetch', fetch);
    const { wrapper, form } = await setup();
    expect(wrapper.text()).toContain('destinationOperations.pending');
    await wrapper.get('[data-listing-host]').setValue('2');
    await flushPromises();
    expect(wrapper.text()).toContain('Host is in maintenance');
    finish(json(operation('stale', 1, 'must-not-appear')));
    await flushPromises();
    expect(wrapper.find('input[value="must-not-appear"]').exists()).toBe(false);
    expect(form().destination_operation_id).toBeNull();
    expect(fetch.mock.calls[0][1]!.signal!.aborted).toBe(true);
});

it('allows old-agent historical restores without a listing receipt and preserves the snapshot', async () => {
    const fetch = vi.fn<typeof globalThis.fetch>();
    vi.stubGlobal('fetch', fetch);
    const { wrapper, form, button, request } = await setup({
        targetDockerHostId: 3, sourceDockerHostId: 3, backupRunId: 82, preselectedBackupKey: 'historical-exact',
        isDockerVolumeSource: false, sourceVolumeName: null, sourceLabel: '/historical/path',
        restoreDestination: { id: 9, name: 'Old local archive', provider: 'local', docker_host_id: 3 },
        backups: [{ key: 'historical-exact', backup_run_id: 82, belongs_to_job: true, verification_deferred: true }],
    });
    expect(wrapper.text()).toContain('destinationOperations.history');
    expect(fetch).not.toHaveBeenCalled();
    expect(form().destination_operation_id).toBeNull();
    await button('Continue').trigger('click');
    expect(wrapper.find('input[value="inplace"]').exists()).toBe(false);
    await button('Continue').trigger('click');
    expect(wrapper.text()).toContain('/historical/path');
    await button('Queue restore').trigger('click');
    await flushPromises();
    expect(request.mock.calls[0][0]).toMatchObject({ method: 'post', data: {
        target_docker_host_id: 3, backup_run_id: 82, selected_backup_key: 'historical-exact', destination_operation_id: null,
    } });
});

it('preserves a changed target through historical-context errors and a successful GET remount', async () => {
    const fetch = vi.fn<typeof globalThis.fetch>()
        .mockResolvedValueOnce(json(operation('central', 1, 'historical-key')))
        .mockResolvedValueOnce(json(operation('agent-page-one', 2, 'another-key', 'next')))
        .mockResolvedValue(json(operation('agent-page-two', 2, 'historical-key')));
    vi.stubGlobal('fetch', fetch);
    const { wrapper, form, button, request, page } = await setup({
        backups: [{ key: 'historical-key', backup_run_id: 82, belongs_to_job: true }],
    });
    await wrapper.get('[data-listing-host]').setValue('2');
    await flushPromises();
    await button('destinationOperations.more').trigger('click');
    await flushPromises();
    await wrapper.get('input[value="historical-key"]').setValue();
    const original = form();
    const visit = async (responsePage: Page) => {
        request.mockResolvedValueOnce({ status: 200, headers: { 'x-inertia': 'true' }, data: JSON.stringify(responsePage) });
        const finished = new Promise<void>((resolve) => {
            const stop = router.on('finish', () => { stop(); resolve(); });
        });
        await button('Continue').trigger('click');
        await finished;
        await flushPromises();
    };
    await visit({ ...page, props: { ...page.props, errors: { backup_run_id: 'Historical context unavailable' } } });
    expect(form()).toBe(original);
    expect(form()).toMatchObject({ target_docker_host_id: 2, backup_run_id: 82, selected_backup_key: 'historical-key' });
    expect(wrapper.text()).toContain('Historical context unavailable');
    expect(request.mock.lastCall?.[0].url).toContain('docker_host_id=2');
    expect(request.mock.lastCall?.[0].url).not.toContain('destination_operation_id');
    fetch.mockResolvedValue(json(operation('historical-exact', 2, 'historical-key', null, 82)));
    await visit({ ...page, url: '/backup-jobs/7/restore?backup_run_id=82&docker_host_id=2', props: {
        ...page.props, backupRunId: 82, preselectedBackupKey: 'historical-key', isDockerVolumeSource: false,
        sourceLabel: '/historical/source', sourceVolumeName: null,
        destinationOperations: { destination_id: 9, default_docker_host_id: 1, selected_docker_host_id: 2 },
    } });
    expect(form()).not.toBe(original);
    expect(form()).toMatchObject({ target_docker_host_id: 2, backup_run_id: 82, selected_backup_key: 'historical-key', destination_operation_id: 'historical-exact' });
    expect(fetch.mock.lastCall![0]).toBe('/destinations/9/operations');
    expect(JSON.parse(fetch.mock.lastCall![1]!.body as string)).toEqual({ action: 'list', docker_host_id: 2, backup_run_id: 82, limit: 100 });
    await button('Continue').trigger('click');
    expect(wrapper.find('input[value="inplace"]').exists()).toBe(false);
});

it('defaults to exact job-associated keys across pages and requires opt-in for other or unknown archives with colliding names', async () => {
    const secondPage = operation('page-two', 1, 'id:a');
    secondPage.data.result.data.objects.push({ key: 'id:B', display_name: 'same-name.tar.gz', size: 1, last_modified: null });
    const fetch = vi.fn<typeof globalThis.fetch>()
        .mockResolvedValueOnce(json(operation('page-one', 1, 'id:A', 'next')))
        .mockResolvedValueOnce(json(secondPage));
    vi.stubGlobal('fetch', fetch);
    const { wrapper, button, form } = await setup({
        backups: [
            { key: 'id:A', display_name: 'same-name.tar.gz', belongs_to_job: true },
            { key: 'id:B', display_name: 'same-name.tar.gz', belongs_to_job: false },
        ],
        hasOtherBackups: false,
    });
    await button('destinationOperations.more').trigger('click');
    await flushPromises();
    expect(wrapper.find('input[value="id:A"]').exists()).toBe(true);
    expect(wrapper.find('input[value="id:a"]').exists()).toBe(false);
    expect(wrapper.find('input[value="id:B"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('Show all backups in this destination');
    await wrapper.get('input[type="checkbox"]').setValue(true);
    expect(wrapper.find('input[value="id:B"]').exists()).toBe(true);
    await wrapper.get('input[value="id:a"]').setValue();
    expect(form()).toMatchObject({ selected_backup_key: 'id:a', destination_operation_id: 'page-two' });
    await wrapper.get('input[type="checkbox"]').setValue(false);
    expect(form().selected_backup_key).toBe('');
    expect(form().destination_operation_id).toBeNull();
    expect(button('Continue').attributes('disabled')).toBeDefined();
});

it('automatically looks up a moved historical Dropbox archive by run ID and submits its exact receipt with the safe source snapshot', async () => {
    const fetch = vi.fn<typeof globalThis.fetch>().mockResolvedValue(json(operation('exact-receipt', 2, 'id:MovedStableID', null, 82)));
    vi.stubGlobal('fetch', fetch);
    const { wrapper, button, form, request } = await setup({
        targetDockerHostId: 2, backupRunId: 82, preselectedBackupKey: 'id:MovedStableID',
        restoreDestination: { id: 9, provider: 'dropbox', settings: { remote_path: '/new-folder' } },
        backups: [], isDockerVolumeSource: false, sourceVolumeName: null, sourceLabel: '/historical/path',
    });
    expect(JSON.parse(fetch.mock.calls[0][1]!.body as string)).toEqual({ action: 'list', docker_host_id: 2, limit: 100, backup_run_id: 82 });
    expect(wrapper.findAll('input[type="radio"]')).toHaveLength(1);
    expect(button('destinationOperations.more')).toBeUndefined();
    await wrapper.get('input[value="id:MovedStableID"]').setValue();
    expect(form().destination_operation_id).toBe('exact-receipt');
    await button('Continue').trigger('click');
    expect(wrapper.find('input[value="inplace"]').exists()).toBe(false);
    await button('Continue').trigger('click');
    expect(wrapper.text()).toContain('/historical/path');
    await button('Queue restore').trigger('click');
    await flushPromises();
    expect(request.mock.calls[0][0]).toMatchObject({ method: 'post', data: {
        backup_run_id: 82, selected_backup_key: 'id:MovedStableID', destination_operation_id: 'exact-receipt', mode: 'new_volume',
    } });
});

it('resumes a preserved exact historical receipt using GET without creating a generic listing', async () => {
    const fetch = vi.fn<typeof globalThis.fetch>().mockResolvedValue(json(operation('saved-exact', 2, 'id:Stable', null, 82)));
    vi.stubGlobal('fetch', fetch);
    const { wrapper, form } = await setup({
        targetDockerHostId: 2, backupRunId: 82, preselectedBackupKey: 'id:Stable',
        backups: [{ key: 'id:Stable', backup_run_id: 82, belongs_to_job: true }],
    }, '/backup-jobs/7/restore?backup_run_id=82&destination_operation_id=saved-exact');
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch.mock.calls[0][0]).toBe('/destinations/9/operations/saved-exact');
    expect(fetch.mock.calls[0][1]!.method).toBe('GET');
    expect(form()).toMatchObject({ selected_backup_key: 'id:Stable', destination_operation_id: 'saved-exact' });
    expect(wrapper.text()).not.toContain('destinationOperations.stale');
});

it('does not offer a cached historical archive after an exact lookup returns no object', async () => {
    const empty = operation('not-found', 2, 'id:Missing', null, 82);
    empty.data.result.data.objects = [];
    const fetch = vi.fn<typeof globalThis.fetch>().mockResolvedValue(json(empty));
    vi.stubGlobal('fetch', fetch);
    const { wrapper, button, form, request } = await setup({
        targetDockerHostId: 2, backupRunId: 82, preselectedBackupKey: 'id:Missing',
        backups: [{ key: 'id:Missing', backup_run_id: 82, belongs_to_job: true }],
    });
    expect(wrapper.find('input[value="id:Missing"]').exists()).toBe(false);
    expect(form().destination_operation_id).toBeNull();
    expect(button('Continue').attributes('disabled')).toBeDefined();
    expect(request).not.toHaveBeenCalled();
});

it('keeps exact owner receipts across relay target changes and submits new-volume restores only', async () => {
    const fetch = vi.fn<typeof globalThis.fetch>().mockResolvedValue(json(operation('owner-receipt', 2, 'exact-local.tar.gz', null, 82)));
    vi.stubGlobal('fetch', fetch);
    const { wrapper, button, form, request } = await setup({
        hosts: relayHosts, destinationOperationHosts: relayHosts,
        targetDockerHostId: 2, sourceDockerHostId: 2, backupRunId: 82,
        preselectedBackupKey: 'exact-local.tar.gz',
        restoreDestination: { id: 9, name: 'A archives', provider: 'local', docker_host_id: 2 },
        archiveTransfer: { source_docker_host_id: 2, host_bound: true, required_agent_capability: 'archive-relay-v1', mode: 'new_volume', max_bytes: 10737418240,
            targets: [{ docker_host_id: 2, supported: true, transfer_required: false }, { docker_host_id: 3, supported: true, transfer_required: true }] },
        volumes: [{ docker_host_id: 3, name: 'collision' }],
    });
    expect(JSON.parse(fetch.mock.calls[0][1]!.body as string)).toMatchObject({ docker_host_id: 2, backup_run_id: 82 });
    await wrapper.get('input[value="exact-local.tar.gz"]').setValue();
    await button('Continue').trigger('click');
    await wrapper.get('input[value="safe_inplace"]').setValue();
    form().confirmation_text = 'data';
    form().backup_before_overwrite = true;
    expect(wrapper.get('[data-target-host] option[value="3"]').attributes('disabled')).toBeUndefined();
    await wrapper.get('[data-target-host]').setValue('3');
    await flushPromises();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(form()).toMatchObject({ destination_operation_id: 'owner-receipt', selected_backup_key: 'exact-local.tar.gz',
        mode: 'new_volume', confirmation_text: '', backup_before_overwrite: false });
    expect(wrapper.find('input[value="inplace"]').exists()).toBe(false);
    expect(wrapper.find('input[value="safe_inplace"]').exists()).toBe(false);
    await wrapper.get('input.input').setValue('collision');
    expect(button('Continue').attributes('disabled')).toBeDefined();
    await wrapper.get('input.input').setValue('new-on-B');
    await button('Continue').trigger('click');
    await button('Queue restore').trigger('click');
    await flushPromises();
    expect(request.mock.calls[0][0]).toMatchObject({ method: 'post', data: {
        target_docker_host_id: 3, backup_run_id: 82, selected_backup_key: 'exact-local.tar.gz',
        destination_operation_id: 'owner-receipt', mode: 'new_volume', target_volume_name: 'new-on-B',
    } });
});

it.each([[1, 2], [2, 1]])('lists owner %i independently of hybrid target %i', async (owner, target) => {
    const fetch = vi.fn<typeof globalThis.fetch>().mockResolvedValue(json(operation('owner', owner, 'exact', null, 82)));
    vi.stubGlobal('fetch', fetch);
    const { wrapper, form, button, request } = await setup({
        hosts: relayHosts, destinationOperationHosts: relayHosts,
        targetDockerHostId: target, sourceDockerHostId: owner, backupRunId: 82, preselectedBackupKey: 'exact',
        restoreDestination: { id: 9, provider: 'docker_volume', docker_host_id: owner },
        archiveTransfer: { source_docker_host_id: owner, host_bound: true, required_agent_capability: 'archive-relay-v1', mode: 'new_volume', max_bytes: 10737418240,
            targets: [{ docker_host_id: target, supported: true, transfer_required: true }] },
    });
    expect(JSON.parse(fetch.mock.calls[0][1]!.body as string)).toMatchObject({ docker_host_id: owner, backup_run_id: 82 });
    await wrapper.get('input[value="exact"]').setValue();
    expect(form().destination_operation_id).toBe('owner');
    await button('Continue').trigger('click');
    expect(wrapper.find('input[value="inplace"]').exists()).toBe(false);
    await button('Continue').trigger('click');
    await button('Queue restore').trigger('click');
    await flushPromises();
    expect(request.mock.calls[0][0]).toMatchObject({ data: { target_docker_host_id: target, destination_operation_id: 'owner', mode: 'new_volume' } });
});

it.each([false, true])('does not browse a different host when the owner cannot list (manage=%s)', async (manage) => {
    const fetch = vi.fn<typeof globalThis.fetch>();
    vi.stubGlobal('fetch', fetch);
    const { wrapper, button } = await setup({
        can: { manageSensitiveData: manage }, targetDockerHostId: 2,
        restoreDestination: { id: 9, provider: 'local', docker_host_id: 3 },
    });
    expect(fetch).not.toHaveBeenCalled();
    if (manage) {
        expect(wrapper.text()).toContain('destinationOperations.history');
        expect(button('Continue').attributes('disabled')).toBeDefined();
    } else {
        expect(button('Continue')).toBeUndefined();
        expect(wrapper.find('[data-listing-host]').exists()).toBe(false);
    }
});

it.each([[1, 2, 2], [2, 1, 2], [2, 3, 2], [2, 3, 3]])('blocks relay from %i to %i when agent %i lacks relay support', async (owner, target, oldAgent) => {
    const compatibleHosts = relayHosts.map((host) => host.id === oldAgent
        ? { ...host, agent_capabilities: host.agent_capabilities.filter((capability) => capability !== 'archive-relay-v1') } : host);
    const fetch = vi.fn<typeof globalThis.fetch>().mockResolvedValue(json(operation('owner', owner, 'exact', null, 82)));
    vi.stubGlobal('fetch', fetch);
    const { wrapper, button } = await setup({
        hosts: compatibleHosts, destinationOperationHosts: compatibleHosts,
        targetDockerHostId: target, sourceDockerHostId: owner, backupRunId: 82, preselectedBackupKey: 'exact',
        restoreDestination: { id: 9, provider: 'local', docker_host_id: owner },
        archiveTransfer: { source_docker_host_id: owner, host_bound: true, required_agent_capability: 'archive-relay-v1', mode: 'new_volume', max_bytes: 10737418240,
            targets: [{ docker_host_id: owner, supported: true, transfer_required: false }, { docker_host_id: target, supported: false, transfer_required: true }] },
    });
    expect(wrapper.get(`[data-listing-host] option[value="${target}"]`).attributes('disabled')).toBeDefined();
    expect(wrapper.get(`[data-listing-host] option[value="${owner}"]`).attributes('disabled')).toBeUndefined();
    await wrapper.get('input[value="exact"]').setValue();
    await button('Continue').trigger('click');
    expect(wrapper.text()).toContain('archiveRelay.missingCapability');
    expect(button('Continue').attributes('disabled')).toBeDefined();
    expect(JSON.parse(fetch.mock.calls[0][1]!.body as string).docker_host_id).toBe(owner);
});
