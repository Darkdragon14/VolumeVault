import { createInertiaApp, router } from '@inertiajs/vue3';
import type { HttpClient, HttpRequestConfig, HttpResponse, Page } from '@inertiajs/core';
import { VueWrapper } from '@vue/test-utils';
import { createApp, h, nextTick, type App } from 'vue';
import { afterEach, expect, it, vi } from 'vitest';
import RestoreCreate from './Create.vue';

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<main><slot /></main>' } }));
vi.mock('@/i18n', () => ({ useI18n: () => ({
    t: (key: string) => key,
    formatDate: (value: string | null) => value ?? '-',
    timezone: { value: 'UTC' },
}) }));

let app: App | undefined;
afterEach(() => {
    router.cancelAll();
    app?.unmount();
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

it('keeps the live form on context validation errors, then remounts historical context on success', async () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    window.history.replaceState({}, '', '/backup-jobs/7/restore');
    document.body.innerHTML = '<div id="app"></div>';
    const archive = { key: 'historical.tar.gz', backup_run_id: 82, belongs_to_job: true };
    const initialPage: Page = {
        component: 'Restore/Create', url: '/backup-jobs/7/restore', version: null,
        clearHistory: false, encryptHistory: false,
        props: {
            errors: {}, can: { manageSensitiveData: true, runDockerActions: true },
            deployment: { mode: 'hybrid', local_execution_enabled: true },
            hosts: [{ id: 1, name: 'Local', driver: 'local' }], volumes: [],
            job: { id: 7, name: 'Current job', docker_host_id: 1, destination: { provider: 'local', name: 'Archive' } },
            restoreDestination: { id: 3, provider: 'local', docker_host_id: 1, name: 'Archive' },
            backups: [archive], backupRunId: null, preselectedBackupKey: null,
            isDockerVolumeSource: true, sourceVolumeName: 'current-volume', sourceLabel: 'current-volume',
            sourceDockerHostId: 1, targetDockerHostId: 1, generatedTargetVolumeName: 'current-restored',
        },
    };
    const response = (page: Page): HttpResponse => ({ status: 200, headers: { 'x-inertia': 'true' }, data: JSON.stringify(page) });
    const request = vi.fn<HttpClient['request']>();
    // Use Inertia's public HTTP adapter seam; page application, callbacks, router
    // state and useForm are real. Unexpected requests cannot reach the network.
    request.mockRejectedValue(new Error('Unexpected HTTP request'));
    let wrapper: VueWrapper;
    await createInertiaApp({
        page: initialPage,
        resolve: () => RestoreCreate,
        http: { request },
        progress: false,
        setup({ el, App, props, plugin }) {
            app = createApp({ render: () => h(App, props) });
            app.use(plugin);
            wrapper = new VueWrapper(app, app.mount(el));
        },
    });
    await nextTick();
    const pageComponent = () => wrapper.findComponent(RestoreCreate);
    const liveForm = () => (pageComponent().vm as any).form;
    const button = (label: string) => wrapper.findAll('button').find((button) => button.text() === label)!;
    const visit = async (label: string, page: Page) => {
        request.mockResolvedValueOnce(response(page));
        const finished = new Promise<void>((resolve) => {
            const stop = router.on('finish', () => { stop(); resolve(); });
        });
        await button(label).trigger('click');
        await finished;
        await nextTick();
    };

    await wrapper.get('input[value="historical.tar.gz"]').setValue();
    const originalForm = liveForm();
    const originalInstance = pageComponent().vm.$;
    expect(originalForm.backup_run_id).toBe(82);
    expect(wrapper.find('input[value="inplace"]').exists()).toBe(false);

    for (const [field, message] of Object.entries({
        destination: 'The backup destination location has changed since this backup was created.',
        backup_run_id: 'The selected backup run is not available for this job.',
    })) {
        await visit('Continue', { ...initialPage, props: { ...initialPage.props, errors: { [field]: message } } });
        expect(wrapper.findAll('[role="alert"]').map((alert) => alert.text())).toContain(message);
        expect(pageComponent().vm.$).toBe(originalInstance);
        expect(liveForm()).toBe(originalForm);
        expect(liveForm().selected_backup_key).toBe(archive.key);
        expect(liveForm().backup_run_id).toBe(82);
        expect(wrapper.text()).toContain('Select backup');
        expect(wrapper.find('input[value="inplace"]').exists()).toBe(false);
        expect(request.mock.lastCall?.[0]).toMatchObject({ method: 'get' });
        expect(request.mock.lastCall?.[0].url).toContain('backup_run_id=82');
    }

    const historicalPage: Page = {
        ...initialPage, url: '/backup-jobs/7/restore?backup_run_id=82',
        props: {
            ...initialPage.props, errors: {}, backupRunId: 82, preselectedBackupKey: archive.key,
            isDockerVolumeSource: false, sourceVolumeName: null, sourceLabel: '/historical/path',
            sourceDockerHostId: 2, generatedTargetVolumeName: 'historical-restored',
        },
    };
    originalForm.target_volume_name = 'stale-target';
    originalForm.confirmation_text = 'stale-confirmation';
    await visit('Continue', historicalPage);
    expect(pageComponent().vm.$).not.toBe(originalInstance);
    expect(liveForm()).not.toBe(originalForm);
    expect(liveForm().errors).toEqual({});
    expect(liveForm().target_volume_name).toBe('historical-restored');
    expect(liveForm().confirmation_text).toBe('');
    expect(liveForm().mode).toBe('new_volume');
    await button('Continue').trigger('click');
    expect(wrapper.text()).toContain('Select restore mode');
    expect(wrapper.find('input[value="inplace"]').exists()).toBe(false);
    expect(wrapper.find('input[value="safe_inplace"]').exists()).toBe(false);
    await button('Continue').trigger('click');
    expect(wrapper.text()).toContain('/historical/path');
    expect(wrapper.text()).not.toContain('current-volume');
    await visit('Queue restore', historicalPage);
    const submitted = request.mock.lastCall?.[0] as HttpRequestConfig;
    expect(submitted.method).toBe('post');
    expect(submitted.data).toMatchObject({
        backup_run_id: 82, selected_backup_key: archive.key, mode: 'new_volume',
        target_volume_name: 'historical-restored', target_docker_host_id: 1,
    });
});
