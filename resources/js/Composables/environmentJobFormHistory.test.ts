import { createInertiaApp } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { expect, it, vi } from 'vitest';
import JobForm from '@/Pages/BackupJobs/Form.vue';
import ApiTokens from '@/Pages/ApiTokens/Index.vue';
import NotificationForm from '@/Pages/Notifications/Form.vue';
import EnvironmentPicker from '@/Components/EnvironmentPicker.vue';
import { environmentFormsDirty, environmentFormsProcessing } from './useEnvironmentForms';

vi.mock('@/i18n', () => ({ useI18n: () => ({ t: (key: string) => key, translateError: (key: string) => key }) }));

it.each(['job', 'api-token', 'notification'])('protects actual %s form drafts on cancelled environment selection and submits natively on confirmation', async (kind) => {
    const root = document.createElement('div');
    root.id = 'app';
    document.body.append(root);
    const submit = vi.spyOn(HTMLFormElement.prototype, 'requestSubmit').mockImplementation(() => {});
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const component = kind === 'job' ? JobForm : kind === 'api-token' ? ApiTokens : NotificationForm;
    const props = {
        csrfToken: 'current-session-token',
        auth: { user: { id: 1 } }, environment: { docker_host_id: 1, hosts: [{ id: 1, name: 'Local', status: 'local' }, { id: 2, name: 'Remote', status: 'online' }] }, errors: {},
        can: { manageSensitiveData: true, manageUsers: true }, deployment: { local_execution_enabled: true },
        job: { id: 2, name: 'Job 2', docker_host_id: 1, volume_name: 'data', backup_destination_id: 1 },
        hosts: [{ id: 1, name: 'Local', driver: 'local' }], volumes: [{ name: 'data', docker_host_id: 1 }],
        destinations: [{ id: 1, name: 'Archive', provider: 'aws_s3' }],
        notificationChannels: [], defaultNotificationChannelIds: [], alertRules: [], timezones: ['UTC'], appTimezone: 'UTC',
        users: [{ id: 1, name: 'Alice' }], tokens: { data: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } },
        channel: { id: 3, name: 'Notifications', title_template: 'Saved title' }, services: ['discord'],
    };
    let wrapper: ReturnType<typeof mount> | undefined;
    try {
        await createInertiaApp({
            page: { component: kind, url: kind === 'job' ? '/backup-jobs/2/edit' : '/api-tokens', version: 'test', encryptHistory: false, clearHistory: false, rememberedState: {}, props } as any,
            resolve: () => component,
            setup({ App, props: appProps, plugin }) {
                wrapper = mount(App, { props: appProps, attachTo: root, global: { plugins: [plugin], stubs: {
                    AppLayout: defineComponent({ components: { EnvironmentPicker }, template: '<main><EnvironmentPicker /><slot /></main>' }),
                    Head: true, InfoTooltip: true,
                } } });
            },
        });
        const formPage = wrapper!.getComponent(component);
        const form = (formPage.vm as any).form;
        // Defaults/context supplied by the real page do not constitute an edit.
        await vi.waitFor(() => expect(environmentFormsDirty.value).toBe(false));
        if (kind === 'notification') {
            const toggle = formPage.get('button[aria-label="Use custom message"]');
            await toggle.trigger('click');
            expect(form.isDirty).toBe(false);
            expect(environmentFormsDirty.value).toBe(true);
            await toggle.trigger('click');
            expect(environmentFormsDirty.value).toBe(false);
        }
        if (kind === 'job') {
            (formPage.vm as any).volumeSearch = 'unselected volume draft';
            await vi.waitFor(() => expect(environmentFormsDirty.value).toBe(true));
            expect(form.isDirty).toBe(false);
            (formPage.vm as any).volumeSearch = form.volume_name;
        }
        form.name = 'Unsaved draft';
        form.setError('name', 'Existing validation error');
        await vi.waitFor(() => expect(environmentFormsDirty.value).toBe(true));
        const picker = wrapper!.getComponent(EnvironmentPicker);
        await picker.get('button').trigger('click');
        await picker.findAll('[role="menuitemradio"]')[2].trigger('click');
        expect(confirm).toHaveBeenCalledWith('environment.confirmDiscard');
        expect(submit).not.toHaveBeenCalled();
        expect(form.name).toBe('Unsaved draft');
        expect(form.errors.name).toBe('Existing validation error');
        form.processing = true;
        await vi.waitFor(() => expect(environmentFormsProcessing.value).toBe(true));
        expect(picker.get('button').attributes('disabled')).toBeDefined();
        form.processing = false;
        confirm.mockReturnValue(true);
        await picker.findAll('[role="menuitemradio"]')[2].trigger('click');
        expect(submit).toHaveBeenCalledOnce();
        const nativeForm = picker.get('form').element as HTMLFormElement;
        expect(nativeForm.method).toBe('post');
        expect(nativeForm.getAttribute('action')).toBe('/user/environment');
        expect(Object.fromEntries(new FormData(nativeForm))).toEqual({ _token: 'current-session-token', docker_host_id: '2' });
    } finally {
        wrapper?.unmount();
        root.remove();
        vi.restoreAllMocks();
    }
    expect(environmentFormsDirty.value).toBe(false);
    expect(environmentFormsProcessing.value).toBe(false);
});
