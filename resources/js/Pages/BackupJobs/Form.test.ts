import { flushPromises, mount } from '@vue/test-utils';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BackupJobForm from './Form.vue';
import en from '@/i18n/locales/en.json';

const inertia = vi.hoisted(() => ({ form: null as any, errors: {} as Record<string, string> }));

vi.mock('@inertiajs/vue3', async () => {
    const { useForm } = await vi.importActual<typeof import('@inertiajs/vue3')>('@inertiajs/vue3');
    return {
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
        usePage: () => ({ url: '/backup-jobs/create', props: {} }),
        useForm: (data: Record<string, unknown>) => {
            const form = useForm(data);
            form.setError(inertia.errors);
            form.post = vi.fn();
            form.put = vi.fn();
            inertia.form = form;
            return form;
        },
    };
});
vi.mock('@/Composables/useEnvironmentForms', () => ({ useEnvironmentForms: vi.fn() }));
vi.mock('@/Composables/useDeployment', () => ({
    hostId: (value: any) => value.docker_host_id ?? 1,
    destinationMatchesHost: () => true,
    useDeployment: () => ({ executionHosts: (hosts: any) => hosts, canExecute: () => true, canManageBackups: true }),
}));
vi.mock('@/i18n', () => ({
    useI18n: () => ({
        t: (key: string, replacements: Record<string, string> = {}) => Object.entries(replacements).reduce(
            (text, [token, value]) => text.split(`{${token}}`).join(value), (en as Record<string, string>)[key] || key,
        ),
        translateError: (value: string) => value,
    }),
}));

function mountPage(job: any = null, appTimezone = 'UTC') {
    return mount(BackupJobForm, {
        props: {
            job, hosts: [{ id: 1, name: 'Local' }], volumes: [{ name: 'data' }],
            destinations: [{ id: 1, name: 'Destination' }], notificationChannels: [],
            defaultNotificationChannelIds: [], alertRules: [], timezones: ['UTC', 'America/New_York'], appTimezone,
        },
        global: { stubs: { AppLayout: { template: '<main><slot /></main>' }, InfoTooltip: true } },
    });
}

describe('backup archive naming', () => {
    beforeEach(() => {
        inertia.errors = {};
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-01-01T02:03:04Z'));
        vi.stubGlobal('crypto', webcrypto);
    });
    afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals(); });

    it('defaults to namespaced mode and shows an explicitly example UUID and run', async () => {
        const wrapper = mountPage();
        expect(inertia.form.use_exact_filename).toBe(false);
        expect(wrapper.get('[data-archive-preview]').text()).toContain('volumevault-00000000-0000-4000-8000-000000000000-run-123-volumevault-source-run-123.tar.gz');
        expect(wrapper.text()).toContain(en['archiveNaming.exampleNamespace']);
        await wrapper.get('#backup_filename_template').setValue('constant');
        expect(wrapper.find('[data-exact-warning]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('This template may produce the same filename');
    });

    it('preserves exact mode on edit and warns even for timestamp templates', async () => {
        const wrapper = mountPage({ id: 7, archive_namespace: 'job-uuid', use_exact_filename: true, backup_filename_template: '{year}-{month}-{day}-{time}' });
        expect((wrapper.get('#use_exact_filename').element as HTMLInputElement).checked).toBe(true);
        expect(wrapper.get('#backup_filename_template').attributes('required')).toBeDefined();
        expect(wrapper.get('[data-archive-preview]').text()).toBe('Preview: 2026-01-01-02-03-04.tar.gz');
        expect(wrapper.get('[data-exact-warning]').text()).toContain('other jobs');
        expect(wrapper.text()).toContain(en['archiveNaming.safetyHelp']);
        await wrapper.get('#use_exact_filename').setValue(false);
        expect(wrapper.get('[data-archive-preview]').text()).toContain('volumevault-job-uuid-run-123-2026-01-01-02-03-04.tar.gz');
        expect(wrapper.get('#backup_filename_template').attributes('required')).toBeUndefined();
    });

    it('uses job timezone, then application timezone, rather than browser timezone', async () => {
        const wrapper = mountPage({ use_exact_filename: true, timezone: 'America/New_York', backup_filename_template: '{year}-{month}-{day}-{time}-{hour}-{minute}-{second}' });
        expect(wrapper.get('[data-archive-preview]').text()).toBe('Preview: 2025-12-31-21-03-04-21-03-04.tar.gz');
        inertia.form.timezone = '';
        await wrapper.vm.$nextTick();
        expect(wrapper.get('[data-archive-preview]').text()).toBe('Preview: 2026-01-01-02-03-04-02-03-04.tar.gz');
        wrapper.unmount();
        const defaultTimezoneWrapper = mountPage({ use_exact_filename: true, backup_filename_template: '{year}-{month}-{day}-{time}' }, 'America/New_York');
        expect(defaultTimezoneWrapper.get('[data-archive-preview]').text()).toBe('Preview: 2025-12-31-21-03-04.tar.gz');
    });

    it('normalizes only token values, preserves literal Unicode and glob characters, and displays validation errors', () => {
        inertia.errors = { use_exact_filename: 'Invalid boolean', backup_filename_template: 'Template required' };
        const wrapper = mountPage({ use_exact_filename: true, name: 'App données', volume_name: 'data', backup_filename_template: 'café [*]? {name}-{source}' });
        expect(wrapper.get('[data-archive-preview]').text()).toBe('Preview: café [*]? App_donn_es-data.tar.gz');
        expect(wrapper.text()).toContain('Invalid boolean');
        expect(wrapper.text()).toContain('Template required');
    });

    it('keeps the 255-byte boundary and matches the stable backend SHA256 suffix', async () => {
        const wrapper = mountPage({ use_exact_filename: true, backup_filename_template: 'a'.repeat(248) });
        expect(wrapper.get('[data-archive-preview]').text()).toBe(`Preview: ${'a'.repeat(248)}.tar.gz`);
        const stem = 'a'.repeat(249);
        await wrapper.get('#backup_filename_template').setValue(stem);
        await vi.waitFor(() => expect(wrapper.get('[data-archive-preview]').text()).toMatch(/-[0-9a-f]{12}\.tar\.gz$/));
        const digest = await webcrypto.subtle.digest('SHA-256', new TextEncoder().encode(stem));
        const hash = Buffer.from(digest).toString('hex').slice(0, 12);
        expect(wrapper.get('[data-archive-preview]').text()).toBe(`Preview: ${'a'.repeat(235)}-${hash}.tar.gz`);
        expect(new TextEncoder().encode(wrapper.get('[data-archive-preview]').text().replace('Preview: ', '')).length).toBe(255);
        await wrapper.get('#use_exact_filename').setValue(false);
        await vi.waitFor(() => expect(wrapper.get('[data-archive-preview]').text()).toContain('volumevault-00000000-0000-4000-8000-000000000000-run-123-'));
        expect(new TextEncoder().encode(wrapper.get('[data-archive-preview]').text().replace('Preview: ', '')).length).toBe(255);
        await flushPromises();
    });

    it('uses the server formatter for long previews on HTTP without Web Crypto', async () => {
        vi.stubGlobal('crypto', {});
        const stem = 'é'.repeat(125);
        const digest = await webcrypto.subtle.digest('SHA-256', new TextEncoder().encode(stem));
        const expected = `${'é'.repeat(117)}-${Buffer.from(digest).toString('hex').slice(0, 12)}.tar.gz`;
        const fetchPreview = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ filename: expected }) });
        vi.stubGlobal('fetch', fetchPreview);
        const wrapper = mountPage({ use_exact_filename: true, backup_filename_template: stem });
        await flushPromises();
        expect(wrapper.get('[data-archive-preview]').text()).toBe(`Preview: ${expected}`);
        expect(fetchPreview).toHaveBeenCalledWith('/backup-jobs/filename-preview', expect.objectContaining({ method: 'POST', body: JSON.stringify({ stem }) }));
        wrapper.unmount();
    });

    it('discards stale HTTP previews when a newer short filename is entered', async () => {
        vi.stubGlobal('crypto', {});
        let resolvePreview: (value: unknown) => void;
        vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => { resolvePreview = resolve; })));
        const wrapper = mountPage({ use_exact_filename: true, backup_filename_template: 'a'.repeat(249) });
        await wrapper.get('#backup_filename_template').setValue('latest');
        resolvePreview!({ ok: true, json: async () => ({ filename: 'obsolete.tar.gz' }) });
        await flushPromises();
        expect(wrapper.get('[data-archive-preview]').text()).toBe('Preview: latest.tar.gz');
        wrapper.unmount();
    });

    it('does not show a stale long preview after changing modes or templates', async () => {
        const wrapper = mountPage({ use_exact_filename: true, backup_filename_template: 'a'.repeat(249) });
        await wrapper.get('#backup_filename_template').setValue('latest');
        await flushPromises();
        expect(wrapper.get('[data-archive-preview]').text()).toBe('Preview: latest.tar.gz');
    });

    it('truncates Unicode on complete UTF-8 characters and hashes the original literal stem', async () => {
        const stem = 'é'.repeat(125);
        const wrapper = mountPage({ use_exact_filename: true, backup_filename_template: stem });
        const digest = await webcrypto.subtle.digest('SHA-256', new TextEncoder().encode(stem));
        const hash = Buffer.from(digest).toString('hex').slice(0, 12);
        await vi.waitFor(() => expect(wrapper.get('[data-archive-preview]').text()).toBe(`Preview: ${'é'.repeat(117)}-${hash}.tar.gz`));
        expect(new TextEncoder().encode(wrapper.get('[data-archive-preview]').text().replace('Preview: ', '')).length).toBe(254);
        await wrapper.get('#backup_filename_template').setValue('é'.repeat(124));
        expect(wrapper.get('[data-archive-preview]').text()).toBe(`Preview: ${'é'.repeat(124)}.tar.gz`);
        expect(new TextEncoder().encode(wrapper.get('[data-archive-preview]').text().replace('Preview: ', '')).length).toBe(255);

        const punctuationStem = 'a'.repeat(234) + '.' + 'é'.repeat(20);
        const punctuationDigest = await webcrypto.subtle.digest('SHA-256', new TextEncoder().encode(punctuationStem));
        const punctuationHash = Buffer.from(punctuationDigest).toString('hex').slice(0, 12);
        await wrapper.get('#backup_filename_template').setValue(punctuationStem);
        await vi.waitFor(() => expect(wrapper.get('[data-archive-preview]').text()).toBe(`Preview: ${'a'.repeat(234)}-${punctuationHash}.tar.gz`));
    });

    it('translates all new UI keys and the changelog entry in every locale', () => {
        const locales = import.meta.glob('../../i18n/locales/*.json', { eager: true, import: 'default' });
        const keys = Object.keys(en).filter((key) => key.startsWith('archiveNaming.'));
        for (const [path, messages] of Object.entries(locales)) {
            for (const key of keys) {
                expect((messages as Record<string, string>)[key], `${path}: ${key}`).toBeTruthy();
            }
            const locale = path.split('/').at(-1)!.replace('.json', '');
            const changelog = readFileSync(`resources/changelog/${locale}.php`, 'utf8');
            expect(changelog).toMatch(/'exact_backup_filenames' => \[\s*'title' => '.+',\s*'description' => '.+',/);
        }
        expect(readFileSync('config/changelog.php', 'utf8')).toContain("'key' => 'exact_backup_filenames'");
    });
});
