import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DockerLabelBackups from './DockerLabelBackups.vue';

const inertia = vi.hoisted(() => ({
    form: null as Record<string, unknown> | null,
    errors: {} as Record<string, string>,
    deployment: undefined as any,
    canManage: true,
    get: vi.fn(),
    payload: null as Record<string, unknown> | null,
}));

vi.mock('@inertiajs/vue3', async () => {
    const { useForm } = await vi.importActual<typeof import('@inertiajs/vue3')>('@inertiajs/vue3');

    return {
        Head: { template: '<div />' },
        router: { get: inertia.get },
        usePage: () => ({ props: { deployment: inertia.deployment, can: { manageSensitiveData: inertia.canManage } } }),
        useForm: (data: Record<string, unknown>) => {
            const form = useForm(data);
            form.setError(inertia.errors);
            form.put = vi.fn(() => {
                inertia.payload = form.data();
            });
            inertia.form = form;

            return inertia.form;
        },
    };
});

vi.mock('@/i18n', () => ({
    useI18n: () => ({
        t: (key: string) => key,
        formatDate: (value: string | null) => value ?? '-',
    }),
}));

const settings = {
    docker_host_id: 1,
    enabled: true,
    backup_destination_id: 1,
    schedule_type: 'weekly' as const,
    schedule_config: {
        dayOfWeek: 'friday' as const,
        time: '21:30',
        everyHours: 12,
        expression: '15 4 * * *',
    },
    timezone: null,
    retention_days: null,
    retention_count: null,
    backup_filter_mode: 'exclude' as const,
    backup_include_paths: null,
    backup_exclude_regexp: null,
    backup_filename_template: null,
    notifications_enabled: true,
    notification_channel_ids: [],
    alert_notifications_enabled: true,
    stop_containers_before_backup: false,
    last_sync_error: null,
    last_synced_at: null,
};

function mountPage(errors: Record<string, string> = {}, notificationChannels: Array<{ id: number; name: string }> = []) {
    inertia.errors = errors;

    return mount(DockerLabelBackups, {
        props: {
            settings,
            hosts: [
                { id: 1, name: 'Local', supports_docker_labels: true },
                { id: 2, name: 'Remote NAS', supports_docker_labels: true },
                { id: 3, name: 'Older agent', supports_docker_labels: false },
            ],
            destinations: [{ id: 1, name: 'Local' }],
            notificationChannels,
            timezones: ['UTC'],
        },
        global: {
            stubs: {
                AppLayout: {
                    props: ['title', 'subtitle'],
                    template: '<main><slot /></main>',
                },
            },
        },
    });
}

describe('Docker label backup settings schedule', () => {
    beforeEach(() => {
        inertia.deployment = undefined;
        inertia.canManage = true;
        inertia.get.mockReset();
        inertia.payload = null;
    });

    it('reloads host context, resets errors and edits, and submits only the loaded host payload', async () => {
        const wrapper = mountPage({ backup_destination_id: 'Old destination error' });
        inertia.form!.retention_days = 99;
        await wrapper.get('[data-host-selector]').setValue(2);
        expect(inertia.get).toHaveBeenCalledWith('/settings/docker-label-backups', { docker_host_id: 2 }, expect.objectContaining({ preserveState: true }));
        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.get('[data-host-selector]').attributes('disabled')).toBeDefined();
        expect(inertia.form!.put).not.toHaveBeenCalled();

        await wrapper.setProps({
            settings: { ...settings, docker_host_id: 2, backup_destination_id: 20, retention_days: 7, last_sync_error: 'Remote sync failed', last_synced_at: '2026-09-18T12:00:00Z' },
            destinations: [{ id: 20, name: 'Remote destination' }],
        });
        inertia.get.mock.calls[0][2].onFinish();
        await wrapper.vm.$nextTick();
        expect(inertia.form).toMatchObject({ docker_host_id: 2, backup_destination_id: 20, retention_days: 7, errors: {} });
        expect(wrapper.text()).toContain('Remote NAS (#2)');
        expect(wrapper.text()).toContain('Remote sync failed');
        expect(wrapper.text()).toContain('2026-09-18T12:00:00Z');
        expect(wrapper.text()).not.toContain('Old destination error');
        await wrapper.get('form').trigger('submit');
        expect(inertia.payload).toMatchObject({ docker_host_id: 2, backup_destination_id: 20, retention_days: 7 });
        expect(inertia.payload).not.toHaveProperty('last_sync_error');

        await wrapper.get('[data-host-selector]').setValue(1);
        await wrapper.setProps({ settings, destinations: [{ id: 1, name: 'Local destination' }] });
        inertia.get.mock.calls[1][2].onFinish();
        await wrapper.vm.$nextTick();
        expect(inertia.form).toMatchObject({ docker_host_id: 1, backup_destination_id: 1, retention_days: null });
        expect(wrapper.text()).not.toContain('Remote sync failed');
    });

    it('restores the loaded host after a failed or cancelled navigation without mixing contexts', async () => {
        const wrapper = mountPage();
        await wrapper.get('[data-host-selector]').setValue(2);
        inertia.get.mock.calls[0][2].onFinish();
        await wrapper.vm.$nextTick();
        expect((wrapper.get('[data-host-selector]').element as HTMLSelectElement).value).toBe('1');
        expect(wrapper.text()).toContain('dockerLabels.loadFailed');
        await wrapper.get('form').trigger('submit');
        expect(inertia.payload).toMatchObject({ docker_host_id: 1, backup_destination_id: 1 });
        await wrapper.get('[data-host-selector]').setValue(2);
        expect(wrapper.text()).not.toContain('dockerLabels.loadFailed');
    });

    it('clears a destination absent from the newly loaded host choices', async () => {
        const wrapper = mountPage();
        await wrapper.setProps({ settings: { ...settings, docker_host_id: 2 }, destinations: [{ id: 20, name: 'Remote destination' }] });
        expect(inertia.form!.backup_destination_id).toBeNull();
    });

    it('allows remote configuration in orchestrator mode and warns for an older selected agent', async () => {
        inertia.deployment = { mode: 'orchestrator', local_execution_enabled: false };
        const wrapper = mountPage();
        expect(wrapper.get('[data-host-selector] option[value="1"]').attributes('disabled')).toBeDefined();
        await wrapper.setProps({ settings: { ...settings, docker_host_id: 3 }, destinations: [] });
        expect(wrapper.find('form').exists()).toBe(true);
        expect(wrapper.text()).toContain('dockerLabels.upgradeAgent');
        await wrapper.get('form').trigger('submit');
        expect(inertia.payload).toMatchObject({ docker_host_id: 3, backup_destination_id: null });
        await wrapper.setProps({ settings: { ...settings, docker_host_id: 2 } });
        expect(wrapper.text()).not.toContain('dockerLabels.upgradeAgent');
        expect(wrapper.text()).toContain('dockerLabels.nextInventory');
    });

    it('prevents changes without management permission and host navigation during a save', async () => {
        inertia.canManage = false;
        const wrapper = mountPage();
        expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined();
        expect(wrapper.find('button.btn-primary').exists()).toBe(false);
        await wrapper.get('form').trigger('submit');
        expect(inertia.form!.put).not.toHaveBeenCalled();
        inertia.form!.processing = true;
        await wrapper.vm.$nextTick();
        expect(wrapper.get('[data-host-selector]').attributes('disabled')).toBeDefined();
        await wrapper.get('[data-host-selector]').trigger('change');
        expect(inertia.get).not.toHaveBeenCalled();
    });

    it.each([false, 0, '0', 'false'])('hides local source settings when execution is %s', (flag) => {
        inertia.deployment = { mode: 'orchestrator', local_execution_enabled: flag };
        const wrapper = mountPage();
        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.text()).toContain('dockerHosts.localDisabled');
    });

    it('preserves valid values for the initial mode and removes unrelated keys', () => {
        mountPage();

        expect(inertia.form?.schedule_config).toEqual({
            dayOfWeek: 'friday',
            time: '21:30',
        });
    });

    it('replaces schedule config with canonical defaults when the mode changes', async () => {
        const wrapper = mountPage();
        const scheduleSelect = wrapper.findAll('select').find((select) => select.element.options[0]?.value === 'hourly');

        expect(scheduleSelect).toBeDefined();

        await scheduleSelect!.setValue('hourly');
        expect(inertia.form?.schedule_config).toEqual({ everyHours: 1 });

        await scheduleSelect!.setValue('daily');
        expect(inertia.form?.schedule_config).toEqual({ time: '02:00' });

        await scheduleSelect!.setValue('weekly');
        expect(inertia.form?.schedule_config).toEqual({ dayOfWeek: 'sunday', time: '03:00' });

        await scheduleSelect!.setValue('cron');
        expect(inertia.form?.schedule_config).toEqual({ expression: '0 2 * * *' });
    });

    it('shows backend validation messages beside their fields and groups', () => {
        const wrapper = mountPage({
            enabled: 'Choose whether automation is enabled.',
            'schedule_config.dayOfWeek': 'Choose a valid schedule day.',
            retention_days: 'Retention days must be positive.',
            backup_exclude_regexp: 'The exclusion pattern is invalid.',
            'notification_channel_ids.0': 'The selected notification channel is invalid.',
            stop_containers_before_backup: 'Choose whether containers should stop.',
            unexpected_setting: 'An unexpected setting is invalid.',
        });

        expect(wrapper.text()).toContain('Choose whether automation is enabled.');
        expect(wrapper.text()).toContain('Choose a valid schedule day.');
        expect(wrapper.text()).toContain('Retention days must be positive.');
        expect(wrapper.text()).toContain('The exclusion pattern is invalid.');
        expect(wrapper.text()).toContain('The selected notification channel is invalid.');
        expect(wrapper.text()).toContain('Choose whether containers should stop.');
        expect(wrapper.text()).toContain('An unexpected setting is invalid.');
        expect(wrapper.text().match(/The selected notification channel is invalid\./g)).toHaveLength(1);
    });

    it('keeps include and exclude errors visible exactly once across filter mode changes', async () => {
        const wrapper = mountPage({
            backup_include_paths: 'Choose at least one included path.',
            backup_exclude_regexp: 'The exclusion pattern is invalid.',
        });
        const filterMode = wrapper.findAll('select').find((select) => select.element.options[0]?.value === 'exclude');
        const assertErrorsOnce = () => {
            expect(wrapper.text().match(/Choose at least one included path\./g)).toHaveLength(1);
            expect(wrapper.text().match(/The exclusion pattern is invalid\./g)).toHaveLength(1);
        };

        assertErrorsOnce();
        await filterMode!.setValue('include');
        assertErrorsOnce();
        await filterMode!.setValue('exclude');
        assertErrorsOnce();
    });

    it('updates controls and channels without submitting until the form is submitted', async () => {
        const wrapper = mountPage({}, [
            { id: 2, name: 'Email' },
            { id: 3, name: 'Webhook' },
        ]);
        const put = inertia.form?.put as ReturnType<typeof vi.fn>;
        const selects = wrapper.findAll('select');
        const filterMode = selects.find((select) => select.element.options[0]?.value === 'exclude');

        await wrapper.get('[role="switch"]').trigger('click');
        await filterMode!.setValue('include');
        await wrapper.findAll('input[type="checkbox"]')[0].setValue(false);
        await wrapper.findAll('button').find((button) => button.text() === 'Email')!.trigger('click');
        await wrapper.findAll('button').find((button) => button.text() === 'Webhook')!.trigger('click');

        expect(put).not.toHaveBeenCalled();
        expect(inertia.form).toMatchObject({
            enabled: false,
            backup_filter_mode: 'include',
            notifications_enabled: false,
            notification_channel_ids: [2, 3],
        });

        await wrapper.get('form').trigger('submit');

        expect(put).toHaveBeenCalledTimes(1);
        expect(put).toHaveBeenCalledWith('/settings/docker-label-backups');
    });
});
