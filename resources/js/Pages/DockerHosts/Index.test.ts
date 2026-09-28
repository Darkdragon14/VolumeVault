import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Index from './Index.vue';
import en from '@/i18n/locales/en.json';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    get: vi.fn(),
    page: { props: {} } as { props: Record<string, any> },
    delete: vi.fn(),
    reload: vi.fn(),
    poll: vi.fn(),
    useHttp: vi.fn(),
    instances: [] as any[],
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual<typeof import('vue')>('vue');

    return {
        Head: { template: '<div />' },
        usePage: () => inertia.page,
        router: { reload: inertia.reload },
        usePoll: inertia.poll,
        useHttp: (...args: any[]) => {
            inertia.useHttp(...args);
            const data = args[0];
            const http = reactive({
                ...data,
                errors: {} as Record<string, string>,
                processing: false,
                response: null,
                reset: () => Object.assign(http, data),
                cancel: vi.fn(),
                post: async (url: string) => {
                    http.processing = true;
                    try {
                        http.response = await inertia.post(url, 'name' in data ? { name: http.name } : 'enabled' in data ? { enabled: http.enabled } : {});
                        return http.response;
                    } finally {
                        http.processing = false;
                    }
                },
                delete: async (url: string) => inertia.delete(url),
                get: async (url: string) => {
                    http.processing = true;
                    try {
                        http.response = await inertia.get(url);
                        return http.response;
                    } finally {
                        http.processing = false;
                    }
                },
            });
            inertia.instances.push(http);
            return http;
        },
    };
});

vi.mock('@/i18n', () => ({
    useI18n: () => ({
        t: (key: string, replacements: Record<string, string> = {}) => ((en as Record<string, string>)[key] || key)
            .replace(/\{(\w+)\}/g, (_, name: string) => replacements[name] ?? `{${name}}`),
        formatDate: (value: string | null) => value ?? 'Never',
    }),
}));

const local = {
    id: 1, uuid: 'local', name: 'Local Docker', driver: 'local' as const, status: 'local' as const,
    last_seen_at: null, last_inventory_at: null, agent_version: null, docker_status: 'ready' as const,
    volume_count: 3, container_count: 4, docker_version: null,
};
const agent = {
    ...local, id: 2, uuid: 'remote', name: 'Remote NAS', driver: 'agent' as const, status: 'pending' as const,
    docker_status: null,
};
const result = {
    host: { id: 2, uuid: 'remote', name: 'Remote NAS' },
    installation: { command: 'docker run agent --enrollment=one-time-secret', expires_at: '2026-09-17T12:10:00Z' },
};
const wrappers: ReturnType<typeof mount>[] = [];

function mountPage(agentsEnabled = true) {
    const wrapper = mount(Index, {
        props: { hosts: [local, agent], agentsEnabled, agentUrl: 'https://vault.example:8443' },
        global: { stubs: { AppLayout: { template: '<main><slot /></main>' } } },
    });
    wrappers.push(wrapper);
    return wrapper;
}

function button(wrapper: ReturnType<typeof mount>, text: string) {
    return wrapper.findAll('button').find((item) => item.text() === text)!;
}

async function createHost(wrapper: ReturnType<typeof mount>) {
    await wrapper.get('#host-name').setValue('Remote NAS');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
}

beforeEach(() => {
    vi.clearAllMocks();
    inertia.instances = [];
    inertia.page.props = {};
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-17T12:00:00Z'));
    inertia.post.mockResolvedValue(result);
    inertia.delete.mockResolvedValue({ revoked: true });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
});

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('Docker host management', () => {
    it('shows local inventory without agent actions and explains pending discovery-only hosts', () => {
        const wrapper = mountPage();
        const localCard = wrapper.get('[data-host-id="1"]');
        expect(wrapper.get('#host-name').attributes('maxlength')).toBe('100');
        expect(localCard.text()).toContain('Local Docker');
        expect(localCard.text()).toContain('3');
        expect(localCard.text()).toContain('4');
        expect(localCard.findAll('button').map((item) => item.text())).toEqual([en['dockerHosts.enterMaintenance']]);
        expect(wrapper.get('[data-host-id="2"]').text()).toContain(en['dockerHosts.pendingHelp']);
        expect(wrapper.text()).toContain(en['hostWorkflow.agentUpgrade']);
        expect(inertia.poll).toHaveBeenCalledWith(30_000, { only: ['hosts'] });
    });

    it('keeps the local card and disables registration and renewal when agents are disabled', () => {
        const wrapper = mountPage(false);
        expect(wrapper.text()).toContain('VOLUMEVAULT_AGENTS_ENABLED=true');
        expect(wrapper.text()).toContain('VOLUMEVAULT_AGENT_URL=https://host:8443');
        expect(wrapper.text()).toContain(en['dockerHosts.enableHelp']);
        expect(wrapper.find('[data-host-id="1"]').exists()).toBe(true);
        expect(wrapper.find('form').exists()).toBe(false);
        expect(button(wrapper, en['dockerHosts.renew']).attributes('disabled')).toBeDefined();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('creates with JSON, keeps the command out of reload/history/storage, and forgets it on remount', async () => {
        const storage = vi.spyOn(Storage.prototype, 'setItem');
        const history = vi.spyOn(window.history, 'replaceState');
        const wrapper = mountPage();
        await createHost(wrapper);

        expect(inertia.post).toHaveBeenCalledWith('/docker-hosts', { name: 'Remote NAS' });
        expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe(result.installation.command);
        expect(wrapper.get('textarea').attributes('readonly')).toBeDefined();
        expect(wrapper.text()).toContain(result.installation.expires_at);
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['hosts'] });
        expect(inertia.useHttp.mock.calls).toEqual([[{ name: '' }], [{}], [{}], [{ enabled: false }], [{}]]);
        expect(inertia.instances.every((http) => http.response === null)).toBe(true);
        expect(storage).not.toHaveBeenCalled();
        expect(history).not.toHaveBeenCalled();

        wrapper.unmount();
        expect(mountPage().find('textarea').exists()).toBe(false);
    });

    it('preserves the command during host-only updates and clears it when dismissed', async () => {
        const wrapper = mountPage();
        await createHost(wrapper);
        await wrapper.setProps({ hosts: [local, { ...agent, status: 'online', last_seen_at: '2026-09-17T12:00:01Z' }] });
        expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe(result.installation.command);
        expect(wrapper.get('[data-host-id="2"]').text()).toContain('Online');
        await button(wrapper, 'Close').trigger('click');
        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    it('copies the command and selects it for manual copying if the clipboard fails', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        const select = vi.spyOn(HTMLTextAreaElement.prototype, 'select');
        const wrapper = mountPage();
        await createHost(wrapper);
        await button(wrapper, en['dockerHosts.copy']).trigger('click');
        await flushPromises();
        expect(writeText).toHaveBeenCalledWith(result.installation.command);
        expect(wrapper.text()).toContain(en['dockerHosts.copied']);

        writeText.mockRejectedValueOnce(new Error('Denied'));
        await button(wrapper, en['dockerHosts.copy']).trigger('click');
        await flushPromises();
        expect(select).toHaveBeenCalled();
        expect(wrapper.text()).toContain(en['dockerHosts.copyManually']);
    });

    it('marks a command expired while the page stays open', async () => {
        const wrapper = mountPage();
        await createHost(wrapper);
        await vi.advanceTimersByTimeAsync(600_000);
        expect(wrapper.text()).toContain(en['dockerHosts.expired']);
        expect(button(wrapper, en['dockerHosts.copy']).attributes('disabled')).toBeDefined();
    });

    it('confirms renewal, replaces the command, and refreshes only hosts', async () => {
        const wrapper = mountPage();
        await createHost(wrapper);
        inertia.reload.mockClear();
        inertia.post.mockResolvedValueOnce({ installation: { ...result.installation, command: 'new-secret-command' } });
        await button(wrapper, en['dockerHosts.renew']).trigger('click');
        await flushPromises();
        expect(window.confirm).toHaveBeenCalledWith(en['dockerHosts.confirmRenew'].replace('{name}', agent.name));
        expect(inertia.post).toHaveBeenLastCalledWith('/docker-hosts/2/enrollment', {});
        expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('new-secret-command');
        expect(inertia.reload).toHaveBeenCalledExactlyOnceWith({ only: ['hosts'] });
    });

    it('confirms revocation, clears the affected command, and updates host status', async () => {
        const wrapper = mountPage();
        await createHost(wrapper);
        inertia.reload.mockClear();
        await button(wrapper, 'Revoke').trigger('click');
        await flushPromises();
        expect(window.confirm).toHaveBeenCalledWith(en['dockerHosts.confirmRevoke'].replace('{name}', agent.name));
        expect(inertia.delete).toHaveBeenCalledWith('/docker-hosts/2/agent');
        expect(wrapper.find('textarea').exists()).toBe(false);
        expect(inertia.reload).toHaveBeenCalledExactlyOnceWith({ only: ['hosts'] });
        await wrapper.setProps({ hosts: [local, { ...agent, status: 'revoked' }] });
        expect(button(wrapper, 'Revoke')).toBeUndefined();
        expect(button(wrapper, en['dockerHosts.renew'])).toBeDefined();
    });

    it('does not send destructive requests when confirmation is cancelled', async () => {
        vi.mocked(window.confirm).mockReturnValue(false);
        const wrapper = mountPage();
        await button(wrapper, 'Revoke').trigger('click');
        await button(wrapper, en['dockerHosts.renew']).trigger('click');
        expect(inertia.post).not.toHaveBeenCalled();
        expect(inertia.delete).not.toHaveBeenCalled();
    });

    it('keeps the command and host list intact when revocation fails', async () => {
        const wrapper = mountPage();
        await createHost(wrapper);
        inertia.reload.mockClear();
        inertia.delete.mockRejectedValueOnce(new Error('Forbidden'));
        await button(wrapper, 'Revoke').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toBe(en['dockerHosts.requestFailed']);
        expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe(result.installation.command);
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    it('does not leave a potentially revoked command visible if renewal fails', async () => {
        const wrapper = mountPage();
        await createHost(wrapper);
        inertia.reload.mockClear();
        inertia.post.mockRejectedValueOnce(new Error('Network failure'));
        await button(wrapper, en['dockerHosts.renew']).trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toBe(en['dockerHosts.requestFailed']);
        expect(wrapper.find('textarea').exists()).toBe(false);
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    it('shows validation and request failures without exposing response details', async () => {
        const wrapper = mountPage();
        inertia.post.mockImplementationOnce(() => {
            inertia.instances[0].errors.name = 'The name field is required.';
            throw new Error('sensitive response details');
        });
        await createHost(wrapper);
        expect(wrapper.text()).toContain('The name field is required.');
        expect(wrapper.get('[role="alert"]').text()).toBe(en['dockerHosts.requestFailed']);
        expect(wrapper.text()).not.toContain('sensitive response details');
        expect(wrapper.find('textarea').exists()).toBe(false);
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    it('prevents duplicate creates while a request is pending', async () => {
        let resolve!: (value: typeof result) => void;
        inertia.post.mockReturnValueOnce(new Promise((done) => resolve = done));
        const wrapper = mountPage();
        await wrapper.get('#host-name').setValue('Remote NAS');
        await wrapper.get('form').trigger('submit');
        await wrapper.get('form').trigger('submit');
        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(button(wrapper, en['dockerHosts.add']).attributes('disabled')).toBeDefined();
        resolve(result);
        await flushPromises();
    });
});

describe('Host lifecycle and deployment modes', () => {
    const online = { ...agent, status: 'online' as const, role: 'agent' as const, compatibility: 'compatible' as const, maintenance_requested: false, maintenance_ready: false, active_operations: 0 };
    const ready = { ...online, maintenance_requested: true, maintenance_ready: true };
    const updateGuide = {
        image: 'ghcr.io/example/volumevault-agent:v1.2.3', version: 'v1.2.3',
        container_name: 'existing-agent', volume_name: 'existing-identity',
        command: 'docker run --name existing-agent -v existing-identity:/identity dedicated-agent:v1.2.3',
        maintenance_ready: true, uses_existing_identity: true,
    };

    it.each([4, 0, null])('shows local Docker version, volume sync and container count %s without last contact', async (count) => {
        const wrapper = mountPage();
        await wrapper.setProps({ hosts: [{
            ...local, container_count: count, docker_version: '28.3.2', agent_version: 'v1.2.3',
            last_seen_at: '2026-09-17T11:00:00Z', last_inventory_at: '2026-09-17T12:00:00Z',
        }] });
        const card = wrapper.get('[data-host-id="1"]');
        const fields = Object.fromEntries(card.findAll('dl > div').map((field) => [field.get('dt').text(), field.get('dd').text()]));
        expect(fields).toMatchObject({
            Containers: count === null ? '—' : String(count),
            Volumes: '3',
            'Docker version': '28.3.2',
            'Docker availability': en['dockerHosts.docker.ready'],
            'VolumeVault version': 'v1.2.3',
            'Last volume sync': '2026-09-17T12:00:00Z',
        });
        expect(card.text()).not.toContain(en['dockerHosts.lastContact']);
        expect(card.text()).not.toContain('2026-09-17T11:00:00Z');
        expect(card.text()).not.toContain(en['dockerHosts.lastInventory']);
        expect(card.text()).toContain(en['dockerHosts.lastVolumeSyncHelp']);
        expect(card.text()).toContain(en['dockerHosts.localMaintenanceHelp']);
        expect(card.text()).not.toContain(en['dockerHosts.maintenanceHelp']);
    });

    it('retains agent contact, inventory, distinct versions and protocol v1 capabilities', async () => {
        const wrapper = mountPage();
        await wrapper.setProps({ hosts: [{
            ...online, agent_version: 'v1.2.3', docker_version: '28.3.2', docker_status: 'ready',
            protocol_version: 1, capabilities: ['inventory'],
            last_seen_at: '2026-09-17T12:01:00Z', last_inventory_at: '2026-09-17T12:00:00Z',
        }] });
        const card = wrapper.get('[data-host-id="2"]');
        const fields = Object.fromEntries(card.findAll('dl > div').map((field) => [field.get('dt').text(), field.get('dd').text()]));
        expect(fields).toMatchObject({
            [en['dockerHosts.lastContact']]: '2026-09-17T12:01:00Z',
            'Last inventory sync': '2026-09-17T12:00:00Z',
            'VolumeVault agent version': 'v1.2.3',
            'Docker version': '28.3.2',
            [en['dockerHosts.protocol']]: '1',
        });
        expect(card.text()).toContain(en['dockerHosts.lastInventoryHelp']);
        expect(card.text()).toContain(en['dockerHosts.maintenanceHelp']);
        expect(card.text()).toContain('inventory');
        expect(card.text()).not.toContain(en['dockerHosts.lastVolumeSync']);
    });

    it.each(['main', 'dev', 'development'])('labels %s honestly for local and agent versions', async (version) => {
        const wrapper = mountPage();
        await wrapper.setProps({
            orchestratorVersion: 'v9.9.9',
            hosts: [{ ...local, agent_version: version }, { ...online, agent_version: version }],
        });
        for (const card of wrapper.findAll('article')) {
            expect(card.text()).toContain(`Development build (${version})`);
            expect(card.text()).not.toContain('v9.9.9');
        }
    });

    it('shows only the VolumeVault version for a local orchestrator even with stale Docker data', async () => {
        const wrapper = mountPage();
        await wrapper.setProps({ hosts: [{
            ...local, role: 'orchestrator', agent_version: 'main', docker_version: '28.3.2',
            last_seen_at: '2026-09-17T11:00:00Z', last_inventory_at: '2026-09-17T12:00:00Z',
        }] });
        const card = wrapper.get('[data-host-id="1"]');
        expect(card.findAll('dt').map((field) => field.text())).toEqual(['VolumeVault version']);
        expect(card.get('dd').text()).toBe('Development build (main)');
        expect(card.text()).not.toContain('28.3.2');
        expect(card.text()).not.toContain('2026-09-17');
        expect(card.text()).not.toContain(en['dockerHosts.lastVolumeSyncHelp']);
    });

    it('renders roles, app version, protocol, compatibility, target and unknown inventory honestly', async () => {
        const wrapper = mountPage();
        await wrapper.setProps({
            deploymentMode: 'hybrid', orchestratorVersion: 'main', targetAgentImage: updateGuide.image,
            hosts: [{ ...local, role: 'hybrid', container_count: null, agent_version: 'main' }, { ...online, protocol_version: 2, capabilities: ['inventory'], compatibility: 'incompatible', update_status: 'ahead', target_version: null }],
        });
        const localCard = wrapper.get('[data-host-id="1"]');
        expect(localCard.text()).toContain('Hybrid');
        expect(localCard.text()).toContain('main');
        expect(localCard.findAll('dd').filter((item) => item.text() === '—')).toHaveLength(3);
        const agentCard = wrapper.get('[data-host-id="2"]');
        expect(agentCard.text()).toContain('Incompatible');
        expect(agentCard.text()).toContain('Ahead of orchestrator');
        expect(agentCard.text()).toContain('inventory');
        expect(wrapper.text()).toContain(updateGuide.image);
        expect(button(localCard as any, en['dockerHosts.updateGuide'])).toBeUndefined();
    });

    it.each([false, 0, '0', 'false'])('hides local actions for equivalent disabled flag %s', async (flag) => {
        inertia.page.props = { deployment: { mode: 'orchestrator', local_execution_enabled: flag } };
        const wrapper = mountPage();
        await wrapper.setProps({ hosts: [{ ...local, role: 'orchestrator' }, online] });
        expect(wrapper.get('[data-host-id="1"]').findAll('button')).toHaveLength(0);
        expect(wrapper.get('[data-host-id="1"]').text()).toContain('Orchestrator');
        expect(button(wrapper.get('[data-host-id="2"]') as any, en['dockerHosts.enterMaintenance'])).toBeDefined();
    });

    it('never shows local actions for an orchestrator role even with missing shared props', async () => {
        const wrapper = mountPage();
        await wrapper.setProps({ hosts: [{ ...local, role: 'orchestrator' }] });
        expect(wrapper.get('[data-host-id="1"]').findAll('button')).toHaveLength(0);
    });

    it.each([false, 0, '0', 'false'])('honors the host local execution flag %s', async (flag) => {
        const wrapper = mountPage();
        await wrapper.setProps({ hosts: [{ ...local, role: 'hybrid', local_execution_enabled: flag as any }] });
        expect(wrapper.get('[data-host-id="1"]').findAll('button')).toHaveLength(0);
    });

    it('enters local maintenance and uses server readiness without killing or inferring completion', async () => {
        const wrapper = mountPage();
        inertia.post.mockResolvedValueOnce({ maintenance_requested: true, maintenance_ready: false, active_operations: 2 });
        await button(wrapper, en['dockerHosts.enterMaintenance']).trigger('click');
        await flushPromises();
        expect(inertia.post).toHaveBeenCalledWith('/docker-hosts/1/maintenance', { enabled: true });
        expect(wrapper.text()).toContain(en['dockerHosts.localMaintenancePending']);
        expect(wrapper.get('[data-host-id="1"]').text()).not.toContain(en['dockerHosts.maintenancePending']);
        expect(wrapper.text()).toContain('Active operations: 2');
        await wrapper.setProps({ hosts: [{ ...local, maintenance_requested: true, maintenance_ready: false, active_operations: 0 }] });
        expect(wrapper.text()).toContain(en['dockerHosts.localMaintenancePending']);
        expect(wrapper.text()).not.toContain(en['dockerHosts.maintenanceReady']);
        await wrapper.setProps({ hosts: [{ ...local, maintenance_requested: true, maintenance_ready: true, active_operations: 0 }] });
        expect(wrapper.text()).toContain(en['dockerHosts.maintenanceReady']);
    });

    it('shows resume for incompatible offline hosts and safely reports server rejection', async () => {
        const wrapper = mountPage();
        await wrapper.setProps({ hosts: [{ ...ready, status: 'offline', compatibility: 'incompatible', active_operations: 1 }] });
        inertia.post.mockRejectedValueOnce(new Error('secret server error'));
        await button(wrapper, en['dockerHosts.resume']).trigger('click');
        await flushPromises();
        expect(inertia.post).toHaveBeenCalledWith('/docker-hosts/2/maintenance', { enabled: false });
        expect(wrapper.get('[role="alert"]').text()).toBe(en['dockerHosts.maintenanceFailed']);
        expect(wrapper.text()).not.toContain('secret server error');
        expect(button(wrapper, en['dockerHosts.resume'])).toBeDefined();
        inertia.post.mockResolvedValueOnce({ maintenance_requested: false, maintenance_ready: false, active_operations: 0 });
        await button(wrapper, en['dockerHosts.resume']).trigger('click');
        await flushPromises();
        expect(button(wrapper, en['dockerHosts.enterMaintenance'])).toBeDefined();
    });

    it.each([false, 0, '0', 'false'])('does not mistake false-like maintenance flags %s for readiness', async (flag) => {
        const wrapper = mountPage();
        await wrapper.setProps({ hosts: [{ ...online, maintenance_requested: flag as any, maintenance_ready: flag as any }] });
        expect(button(wrapper, en['dockerHosts.enterMaintenance'])).toBeDefined();
        expect(button(wrapper, en['dockerHosts.resume'])).toBeUndefined();
    });

    it('loads a manual identity-preserving guide separately from enrollment and outside history/storage', async () => {
        const storage = vi.spyOn(Storage.prototype, 'setItem');
        const history = vi.spyOn(window.history, 'replaceState');
        const wrapper = mountPage();
        await createHost(wrapper);
        await wrapper.setProps({ hosts: [local, ready] });
        inertia.get.mockResolvedValueOnce(updateGuide);
        inertia.reload.mockClear();
        await button(wrapper, en['dockerHosts.updateGuide']).trigger('click');
        await flushPromises();
        expect(inertia.get).toHaveBeenCalledWith('/docker-hosts/2/update-guide');
        expect(wrapper.get<HTMLTextAreaElement>('#update-command').element.value).toBe(updateGuide.command);
        expect(wrapper.get<HTMLTextAreaElement>('#installation-command').element.value).toBe(result.installation.command);
        const panel = wrapper.get('[data-update-guide]');
        expect(panel.text()).toContain('existing-identity');
        expect(panel.text()).toContain(en['dockerHosts.preserveDeployment']);
        expect(panel.find('input').exists()).toBe(false);
        expect(inertia.instances.every((http) => http.response === null)).toBe(true);
        expect(storage).not.toHaveBeenCalled();
        expect(history).not.toHaveBeenCalled();
        expect(inertia.reload).not.toHaveBeenCalled();
        await button(panel as any, 'Close').trigger('click');
        expect(wrapper.find('#update-command').exists()).toBe(false);
        expect(wrapper.find('#installation-command').exists()).toBe(true);
    });

    it('handles a 409 guide failure safely and permits reloading after a fresh ready heartbeat', async () => {
        const wrapper = mountPage();
        await wrapper.setProps({ hosts: [{ ...ready, maintenance_ready: false }] });
        inertia.get.mockRejectedValueOnce({ status: 409, message: 'sensitive details' });
        await button(wrapper, en['dockerHosts.updateGuide']).trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toBe(en['dockerHosts.guideFailed']);
        expect(wrapper.find('#update-command').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('sensitive details');
        await wrapper.setProps({ hosts: [ready] });
        inertia.get.mockResolvedValueOnce(updateGuide);
        await button(wrapper, en['dockerHosts.reloadGuide']).trigger('click');
        await flushPromises();
        expect(wrapper.find('#update-command').exists()).toBe(true);
        await wrapper.setProps({ hosts: [online] });
        expect(wrapper.find('#update-command').exists()).toBe(false);
    });

    it.each(['maintenance_ready', 'uses_existing_identity'])('rejects an unsafe guide with %s false and clears stale commands on failure', async (flag) => {
        const wrapper = mountPage();
        await wrapper.setProps({ hosts: [ready] });
        inertia.get.mockResolvedValueOnce(updateGuide);
        await button(wrapper, en['dockerHosts.updateGuide']).trigger('click');
        await flushPromises();
        inertia.get.mockResolvedValueOnce({ ...updateGuide, [flag]: 'false' });
        await button(wrapper, en['dockerHosts.reloadGuide']).trigger('click');
        await flushPromises();
        expect(wrapper.find('#update-command').exists()).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toBe(en['dockerHosts.guideFailed']);
    });

    it('copies the guide with the existing manual fallback and forgets it on unmount', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        const select = vi.spyOn(HTMLTextAreaElement.prototype, 'select');
        const wrapper = mountPage();
        await wrapper.setProps({ hosts: [ready] });
        inertia.get.mockResolvedValueOnce(updateGuide);
        await button(wrapper, en['dockerHosts.updateGuide']).trigger('click');
        await flushPromises();
        await button(wrapper, en['dockerHosts.copy']).trigger('click');
        await flushPromises();
        expect(writeText).toHaveBeenCalledWith(updateGuide.command);
        writeText.mockRejectedValueOnce(new Error('Denied'));
        await button(wrapper, en['dockerHosts.copy']).trigger('click');
        await flushPromises();
        expect(select).toHaveBeenCalled();
        expect(wrapper.text()).toContain(en['dockerHosts.copyManually']);
        wrapper.unmount();
        expect(mountPage().find('#update-command').exists()).toBe(false);
    });

    it('does not reveal a late guide response after polling invalidates maintenance readiness', async () => {
        let resolve!: (value: typeof updateGuide) => void;
        inertia.get.mockReturnValueOnce(new Promise((done) => resolve = done));
        const wrapper = mountPage();
        await wrapper.setProps({ hosts: [ready] });
        await button(wrapper, en['dockerHosts.updateGuide']).trigger('click');
        await wrapper.setProps({ hosts: [online] });
        resolve(updateGuide);
        await flushPromises();
        expect(wrapper.find('#update-command').exists()).toBe(false);
        expect(inertia.instances.every((http) => http.response === null)).toBe(true);
    });
});

describe('Docker host translations', () => {
    it('provides all host labels and interpolation variables in every locale', () => {
        const catalogs = import.meta.glob('../../i18n/locales/*.json', { eager: true, import: 'default' }) as Record<string, Record<string, string>>;
        const entries = Object.entries(en).filter(([key]) => key.startsWith('dockerHosts.'));
        expect(Object.keys(catalogs)).toHaveLength(9);
        for (const catalog of Object.values(catalogs)) {
            for (const [key, value] of entries) {
                expect(catalog[key], key).toBeTruthy();
                expect(catalog[key].match(/\{\w+\}/g) || []).toEqual(value.match(/\{\w+\}/g) || []);
            }
        }
    });
});
