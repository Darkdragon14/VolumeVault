import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

import DockerLabelBackups from './DockerLabelBackups.vue';

const inertia = vi.hoisted(() => ({
    form: null as Record<string, unknown> | null,
    errors: {} as Record<string, string>,
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual<typeof import('vue')>('vue');

    return {
        Head: { template: '<div />' },
        useForm: (data: Record<string, unknown>) => {
            inertia.form = reactive({
                ...data,
                errors: reactive({ ...inertia.errors }),
                processing: false,
                put: vi.fn(),
            });

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
