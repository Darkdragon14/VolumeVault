import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DestinationForm from './Form.vue';

const inertia = vi.hoisted(() => ({
    errors: {} as Record<string, string>,
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    useForm: (data: Record<string, unknown>) => ({
        ...data,
        errors: inertia.errors,
        processing: false,
        post: vi.fn(),
        put: vi.fn(),
    }),
}));

vi.mock('@/i18n', () => ({
    useI18n: () => ({
        t: (key: string) => key,
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
