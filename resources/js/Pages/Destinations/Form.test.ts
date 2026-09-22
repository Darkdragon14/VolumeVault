import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import DestinationForm from './Form.vue';

const inertia = vi.hoisted(() => ({
    errors: {} as Record<string, string>,
    form: null as any,
    submitted: vi.fn(),
    admin: true,
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { can: { manageSensitiveData: inertia.admin } } }),
    useForm: (data: Record<string, unknown>) => {
        let transform = (value: any) => value;
        const form = reactive({ ...data, errors: inertia.errors, processing: false,
            clearErrors: (...keys: string[]) => keys.forEach(key => delete form.errors[key]),
            transform: (callback: any) => { transform = callback; return form; },
            post: (url: string) => inertia.submitted(url, transform(Object.fromEntries(Object.keys(data).map(key => [key, form[key]])))),
            put: (url: string) => form.post(url),
        });
        inertia.form = form;
        return form;
    },
}));

vi.mock('@/i18n', () => ({
    useI18n: () => ({
        t: (key: string, values?: Record<string, string>) => key + (values?.host ? ` ${values.host}` : ''),
        translateError: (message: string) => message,
    }),
}));

describe('Destination SFTP form', () => {
    beforeEach(() => {
        inertia.errors = {};
    });

    it('renders nested Inertia validation errors beside SFTP fields', () => {
        const validationErrors = {
            'settings.host': 'SSH host error',
            'settings.port': 'SSH port error',
            'settings.remote_path': 'Remote path error',
            'secrets.user': 'SSH user error',
            'secrets.password': 'SSH password error',
            'secrets.private_key': 'Private key error',
            'secrets.private_key_passphrase': 'Passphrase error',
            'settings.identity_file': 'Identity file error',
            'settings.host_key': 'Host key error',
        };

        inertia.errors = validationErrors;

        const wrapper = mount(DestinationForm, {
            props: {
                destination: {
                    id: 1,
                    name: 'SFTP',
                    provider: 'ssh',
                    endpoint: 'server.local',
                    settings: {
                        host: 'server.local',
                        port: 22,
                        remote_path: '/backups',
                    },
                    has_secrets: {
                        private_key: true,
                        user: true,
                    },
                },
                providers: [{ value: 'ssh', label: 'SFTP', secret_fields: [] }],
            },
            global: {
                stubs: {
                    AppLayout: {
                        props: ['title', 'subtitle'],
                        template: '<main><slot /></main>',
                    },
                    Head: true,
                    Link: { template: '<a><slot /></a>' },
                    PasswordInput: { template: '<input>' },
                },
            },
        });

        const labeledErrors = {
            'SSH host': validationErrors['settings.host'],
            'Port': validationErrors['settings.port'],
            'Remote path': validationErrors['settings.remote_path'],
            'Username': validationErrors['secrets.user'],
            'Password': validationErrors['secrets.password'],
            'Private key': validationErrors['secrets.private_key'],
            'Private key passphrase': validationErrors['secrets.private_key_passphrase'],
            'Identity file path': validationErrors['settings.identity_file'],
        };

        for (const [fieldName, message] of Object.entries(labeledErrors)) {
            const field = wrapper.findAll('label').find((label) => label.find('.label').text() === fieldName);

            expect(field, `${fieldName} field`).toBeDefined();
            expect(field!.text()).toContain(message);
        }

        const hostKeyField = wrapper.get('textarea[placeholder^="ssh-ed25519"]').element.parentElement;

        expect(hostKeyField?.textContent).toContain(validationErrors['settings.host_key']);
    });
});

const wrappers: ReturnType<typeof mount>[] = [];
const render = (destination: any = null) => {
    const wrapper = mount(DestinationForm, { props: {
        destination,
        providers: ['ssh', 'aws_s3', 'local', 'docker_volume'].map(value => ({ value, label: value, secret_fields: [] })),
        hosts: [{ id: 1, name: 'Central' }, { id: 2, name: 'Agent A' }, { id: 3, name: 'Agent B' }],
        destinationOperationHosts: [
            { id: 2, name: 'Agent A', supports_destination_operations: true, supports_sftp_host_key: true, maintenance_requested: true },
            { id: 3, name: 'Agent B', supports_destination_operations: true, supports_sftp_host_key: false },
            { id: 4, name: 'Old', supports_destination_operations: false, supports_sftp_host_key: true },
        ],
    }, global: { stubs: {
        AppLayout: { template: '<main><slot /></main>' }, DestinationOperations: true,
    } } });
    wrappers.push(wrapper);
    return wrapper;
};
const scan = async (wrapper: ReturnType<typeof mount>) => {
    await flushPromises();
    await wrapper.findAll('button').find(button => button.text() === 'Fetch key from server')!.trigger('click');
};
const response = (data: any, status = 200) => ({ ok: status < 400, status, json: async () => data });
const operation = (overrides: any = {}) => ({ id: 'scan-1', destination_id: null, docker_host_id: 2, action: 'host_key',
    status: 'pending', endpoint: { host: 'private.test', port: 22 }, result: null, ...overrides });
const completed = () => operation({ status: 'completed', result: { status: 'success', data: { key: 'ssh-ed25519 discovered', fingerprint: 'SHA256:valid' } } });

beforeEach(() => { inertia.errors = {}; inertia.admin = true; vi.clearAllMocks(); });
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); vi.useRealTimers(); vi.unstubAllGlobals(); });

describe('Automated measurement executor', () => {
    it.each([
        { id: 4, name: 'Old', status: 'incompatible' },
        { id: 5, name: 'Revoked agent', status: 'revoked' },
        { id: 3, name: 'Agent B', status: 'missing-operation-prop' },
        { id: 99, name: null, status: 'missing' },
    ])('shows and preserves the saved $status executor until explicitly changed', async ({ id, name, status }) => {
        const wrapper = render({ id: 7, provider: 'aws_s3', storage_measurement_host_id: id });
        const options = [
            { id: 2, name: 'Agent A', supports_destination_operations: true },
            ...(status === 'incompatible' || status === 'revoked' ? [{ id, name: name!, status, supports_destination_operations: false }] : []),
        ];
        await wrapper.setProps({ destinationOperationHosts: options });
        const selector = wrapper.get('[data-testid="measurement-executor"]');
        const savedOption = selector.get(`option[value="${id}"]`);
        expect(savedOption.attributes('disabled')).toBeDefined();
        expect((savedOption.element as HTMLOptionElement).selected).toBe(true);
        expect(savedOption.text()).toContain(name ? `${name} (#${id})` : `#${id}`);
        expect(savedOption.text()).toContain('remoteAudit.savedUnavailable');
        expect(wrapper.text()).toContain('remoteAudit.measureUnavailableHelp');
        inertia.form.name = 'Renamed destination';
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted.mock.lastCall![1]).toMatchObject({ name: 'Renamed destination', storage_measurement_host_id: id });
        inertia.form.errors.storage_measurement_host_id = 'This agent is incompatible.';
        await flushPromises();
        expect(wrapper.text()).toContain('This agent is incompatible.');
        expect(inertia.form.storage_measurement_host_id).toBe(id);
        await selector.setValue('2');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted.mock.lastCall![1].storage_measurement_host_id).toBe(2);
        expect(wrapper.text()).not.toContain('remoteAudit.measureUnavailableHelp');
        (selector.element as HTMLSelectElement).selectedIndex = 0;
        await selector.trigger('change');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted.mock.lastCall![1].storage_measurement_host_id).toBeNull();
    });

    it.each(['local', 'docker_volume'])('keeps basic %s editing available without destination-v1 and submits the owner', async provider => {
        const wrapper = render({ id: 7, provider, docker_host_id: 2, storage_measurement_host_id: 2 });
        await wrapper.setProps({ destinationOperationHosts: [{ id: 2, name: 'Agent A', supports_destination_operations: false, supports_host_bound_destinations: false }] });
        inertia.form.name = 'Edited local storage';
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted).toHaveBeenCalledWith('/destinations/7', expect.objectContaining({
            name: 'Edited local storage', provider, docker_host_id: 2, storage_measurement_host_id: 2,
        }));
        expect(wrapper.text()).not.toContain('remoteAudit.measureUnavailableHelp');
    });

    it('requires administrator permission for executor controls and host-key discovery', async () => {
        inertia.admin = false;
        const fetcher = vi.fn();
        vi.stubGlobal('fetch', fetcher);
        const wrapper = render();
        inertia.form.settings.host = 'private.test';
        await scan(wrapper);
        expect(wrapper.get('[data-testid="measurement-executor"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="host-key-executor"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="measurement-executor"]').text()).not.toContain('Agent A');
        expect(fetcher).not.toHaveBeenCalled();
    });
    it('displays a saved explicit central host as the central default', () => {
        const wrapper = render({ id: 7, provider: 'aws_s3', storage_measurement_host_id: 1 });
        expect(inertia.form.storage_measurement_host_id).toBeNull();
        expect((wrapper.get('[data-testid="measurement-executor"]').element as HTMLSelectElement).selectedIndex).toBe(0);
    });
    it.each([false, true])('round-trips a selected agent and explicit null reset on edit=%s', async (editing) => {
        const wrapper = render(editing ? { id: 7, provider: 'aws_s3', storage_measurement_host_id: 2 } : null);
        const selector = wrapper.get('[data-testid="measurement-executor"]');
        expect(inertia.form.storage_measurement_host_id).toBe(editing ? 2 : null);
        expect(selector.text()).toContain('Agent A'); // Maintenance does not alter persisted configuration eligibility.
        expect(selector.text()).not.toContain('Old');
        await selector.setValue('2');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted).toHaveBeenLastCalledWith(editing ? '/destinations/7' : '/destinations', expect.objectContaining({ storage_measurement_host_id: 2, docker_host_id: null, settings: expect.any(Object) }));
        (selector.element as HTMLSelectElement).selectedIndex = 0;
        await selector.trigger('change');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted.mock.lastCall![1].storage_measurement_host_id).toBeNull();
    });

    it('resets on provider changes and fixes local measurements to the changing owner', async () => {
        const wrapper = render({ id: 7, provider: 'aws_s3', storage_measurement_host_id: 2 });
        inertia.form.provider = 'local';
        await flushPromises();
        expect(inertia.form.storage_measurement_host_id).toBe(1);
        expect(wrapper.get('[data-testid="measurement-executor"]').attributes('disabled')).toBeDefined();
        inertia.form.docker_host_id = 3;
        await flushPromises();
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted.mock.lastCall![1]).toMatchObject({ docker_host_id: 3, storage_measurement_host_id: 3 });
        inertia.form.provider = 'ssh';
        await flushPromises();
        expect(inertia.form.storage_measurement_host_id).toBeNull();
    });
});

describe('Unsaved host key discovery', () => {
    it.each(['2001:db8::1', 'backup_service', 'Backup-Service.internal'])('sends the original SFTP hostname %s without stricter client validation', async host => {
        for (const executor of [1, 2]) {
            const fetcher = vi.fn().mockResolvedValue(response(executor === 1
                ? { key: 'ssh-ed25519 valid', fingerprint: 'SHA256:valid' }
                : { data: { ...completed(), endpoint: { host, port: 22 } } }));
            vi.stubGlobal('fetch', fetcher);
            const wrapper = render();
            await wrapper.get('input[placeholder="server.local"]').setValue(host);
            await wrapper.get('[data-testid="host-key-executor"]').setValue(String(executor));
            await scan(wrapper);
            await flushPromises();
            expect(JSON.parse(fetcher.mock.calls[0][1].body)).toEqual({ host, port: 22, docker_host_id: executor });
            expect(inertia.form.settings.host).toBe(host);
            expect(inertia.form.settings.host_key).toBe(executor === 1 ? 'ssh-ed25519 valid' : 'ssh-ed25519 discovered');
        }
    });

    it('supports central discovery and preserves manual entry without sending credentials', async () => {
        const fetcher = vi.fn().mockResolvedValue(response({ key: 'ssh-ed25519 central', fingerprint: 'SHA256:central' }));
        vi.stubGlobal('fetch', fetcher);
        const wrapper = render();
        inertia.form.settings.host = 'private.test';
        inertia.form.secrets.password = 'never-send';
        inertia.form.secrets.private_key = 'private-secret';
        await scan(wrapper);
        await flushPromises();
        expect(JSON.parse(fetcher.mock.calls[0][1].body)).toEqual({ host: 'private.test', port: 22, docker_host_id: 1 });
        expect(inertia.form.settings.host_key).toBe('ssh-ed25519 central');
        await wrapper.get('textarea[placeholder^="ssh-ed25519"]').setValue('manual-key');
        expect(inertia.form.settings.host_key).toBe('manual-key');
        expect(wrapper.text()).not.toContain('SHA256:central');
    });

    it('polls a capable agent and fills only its exact endpoint result', async () => {
        vi.useFakeTimers();
        const fetcher = vi.fn().mockResolvedValueOnce(response({ data: operation() }, 202)).mockResolvedValueOnce(response({ data: completed() }));
        vi.stubGlobal('fetch', fetcher);
        const wrapper = render();
        expect(wrapper.get('[data-testid="host-key-executor"]').text()).not.toContain('Agent B');
        expect(wrapper.get('[data-testid="host-key-executor"]').text()).not.toContain('Old');
        inertia.form.settings.host = 'private.test';
        await wrapper.get('[data-testid="host-key-executor"]').setValue('2');
        await scan(wrapper);
        await flushPromises();
        expect(inertia.form.settings.host_key).toBe('');
        await vi.advanceTimersByTimeAsync(1500);
        expect(fetcher.mock.calls[1][0]).toBe('/destinations/host-key/operations/scan-1');
        expect(inertia.form.settings.host_key).toBe('ssh-ed25519 discovered');
    });

    it.each(['host', 'port', 'executor', 'provider', 'manual', 'unmount'])('rejects late POST and GET responses after %s changes', async (change) => {
        vi.useFakeTimers();
        for (const phase of ['post', 'get']) {
            let resolve!: (value: any) => void;
            const pending = new Promise(value => { resolve = value; });
            const fetcher = vi.fn();
            if (phase === 'get') fetcher.mockResolvedValueOnce(response({ data: operation() }, 202));
            fetcher.mockReturnValueOnce(pending);
            vi.stubGlobal('fetch', fetcher);
            const wrapper = render();
            inertia.form.settings.host = 'private.test';
            await wrapper.get('[data-testid="host-key-executor"]').setValue('2');
            await scan(wrapper);
            await flushPromises();
            if (phase === 'get') await vi.advanceTimersByTimeAsync(1500);
            if (change === 'host') inertia.form.settings.host = 'other.test';
            if (change === 'port') inertia.form.settings.port = 2222;
            if (change === 'executor') await wrapper.get('[data-testid="host-key-executor"]').setValue('1');
            if (change === 'provider') inertia.form.provider = 'aws_s3';
            if (change === 'manual') inertia.form.settings.host_key = 'manual-key';
            if (change === 'unmount') wrapper.unmount();
            await flushPromises();
            resolve(response({ data: completed() }));
            await flushPromises();
            expect(inertia.form.settings.host_key).toBe(change === 'manual' ? 'manual-key' : '');
            await vi.advanceTimersByTimeAsync(3000);
            expect(fetcher).toHaveBeenCalledTimes(phase === 'get' ? 2 : 1);
        }
    });

    it.each(['endpoint', 'id'])('rejects a mismatching %s in a poll result', async (field) => {
        vi.useFakeTimers();
        const result = completed();
        if (field === 'endpoint') result.endpoint.port = 2222;
        else result.id = 'unrelated-scan';
        vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(response({ data: operation() }, 202)).mockResolvedValueOnce(response({ data: result })));
        const wrapper = render();
        inertia.form.settings.host = 'private.test';
        await wrapper.get('[data-testid="host-key-executor"]').setValue('2');
        await scan(wrapper);
        await flushPromises();
        await vi.advanceTimersByTimeAsync(1500);
        expect(inertia.form.settings.host_key).toBe('');
        expect(wrapper.text()).toContain('destinationOperations.stale');
    });

    it('clears remote errors and discovered data when switching provider', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({ message: 'Remote scan refused' }, 422)));
        const wrapper = render();
        inertia.form.settings.host = 'private.test';
        await scan(wrapper);
        await flushPromises();
        expect(wrapper.text()).toContain('Remote scan refused');
        inertia.form.settings.host_key = 'manual-key';
        inertia.form.provider = 'aws_s3';
        await flushPromises();
        inertia.form.provider = 'ssh';
        await flushPromises();
        expect(wrapper.text()).not.toContain('Remote scan refused');
        expect(inertia.form.settings.host_key).toBe('');
    });
});
