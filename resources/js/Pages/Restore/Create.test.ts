import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RestoreCreate from './Create.vue';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    get: vi.fn(),
    deployment: undefined as any,
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    router: { get: inertia.get },
    usePage: () => ({ props: { deployment: inertia.deployment, can: { runDockerActions: true } } }),
    Link: { template: '<a><slot /></a>' },
    useForm: (data: Record<string, unknown>) => reactive({
        ...data,
        errors: {},
        processing: false,
        post: inertia.post,
    }),
}));

vi.mock('@/i18n', () => ({
    useI18n: () => ({
        t: (key: string) => key,
        formatDate: (value: string | null) => value ?? '-',
        timezone: { value: 'UTC' },
    }),
}));

vi.mock('@/Composables/useFormatBytes', () => ({
    formatBytes: (value: number | null) => `${value ?? 0} B`,
}));

describe('Restore form', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        inertia.deployment = undefined;
    });

    const warning = 'This historical Dropbox backup has no stable file ID. Its identity cannot be verified, so restoring this run is unavailable.';
    const safetyWarning = 'Safety backup before overwrite is unavailable because the job’s current destination is Dropbox. A newly uploaded Dropbox backup cannot be verified for restore. Choose a different job destination or explicitly turn off the safety backup.';
    const mountForm = (backupRunUnverifiable = false, currentProvider = 'dropbox', historicalProvider = 'dropbox', overrides = {}) => mount(RestoreCreate, {
        props: {
            job: { id: 7, name: 'Documents', destination: { name: 'Current', provider: currentProvider } },
            restoreDestination: { id: 3, name: 'Historical', provider: historicalProvider },
            backups: [{ key: 'id:original', display_name: 'backup.tar.gz', belongs_to_job: true }],
            backupRunId: 42,
            backupRunUnverifiable,
            preselectedBackupKey: 'id:original',
            isDockerVolumeSource: true,
            sourceLabel: 'documents',
            sourceVolumeName: 'documents',
            generatedTargetVolumeName: 'documents-restored',
            ...overrides,
        },
        global: { stubs: { AppLayout: { template: '<main><slot /></main>' } } },
    });

    it('blocks an unverifiable historical run even if an archive was supplied', () => {
        const wrapper = mountForm(true);

        expect(wrapper.text()).toContain(warning);
        expect(wrapper.find('input[type="radio"]').exists()).toBe(false);
        expect(wrapper.findAll('button').find((button) => button.text() === 'Continue')?.attributes('disabled')).toBeDefined();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it.each([false, 0, '0', 'false'])('hides the restore workflow when local execution is %s', (flag) => {
        inertia.deployment = { mode: 'orchestrator', local_execution_enabled: flag };
        const wrapper = mountForm();
        expect(wrapper.text()).toContain('dockerHosts.localDisabled');
        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.find('button').exists()).toBe(false);
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it.each(['inplace', 'safe_inplace'])('retains and blocks requested Dropbox safety for %s using the current destination', async (mode) => {
        const wrapper = mountForm(false, 'dropbox', 'local');
        const next = () => wrapper.findAll('button').find((button) => button.text() === 'Continue')!;
        await next().trigger('click');
        await wrapper.find(`input[value="${mode}"]`).setValue();
        expect(wrapper.text()).toContain(safetyWarning);
        await wrapper.find('input[type="checkbox"]').setValue(true);
        expect(next().attributes('disabled')).toBeDefined();
        expect((wrapper.find('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(true);
        expect(inertia.post).not.toHaveBeenCalled();
        await wrapper.find('input[type="checkbox"]').setValue(false);
        expect(next().attributes('disabled')).toBeUndefined();
        await next().trigger('click');
        await wrapper.find('input[autocomplete="off"]').setValue('documents');
        await wrapper.findAll('button').find((button) => button.text() === 'Queue restore')!.trigger('click');
        expect(inertia.post).toHaveBeenCalledOnce();
    });

    it('allows safety with a current local destination even when restoring a Dropbox archive', async () => {
        const wrapper = mountForm(false, 'local');
        const next = () => wrapper.findAll('button').find((button) => button.text() === 'Continue')!;
        await next().trigger('click');
        await wrapper.find('input[value="safe_inplace"]').setValue();
        await wrapper.find('input[type="checkbox"]').setValue(true);
        expect(wrapper.text()).not.toContain(safetyWarning);
        expect(next().attributes('disabled')).toBeUndefined();
        await next().trigger('click');
        expect((wrapper.vm as unknown as { form: { backup_before_overwrite: boolean } }).form.backup_before_overwrite).toBe(true);
    });

    it('returns to mode selection and displays server safety validation without clearing the request', async () => {
        const wrapper = mountForm(false, 'local');
        const next = () => wrapper.findAll('button').find((button) => button.text() === 'Continue')!;
        await next().trigger('click');
        await wrapper.find('input[value="inplace"]').setValue();
        await wrapper.find('input[type="checkbox"]').setValue(true);
        await next().trigger('click');
        await wrapper.find('input[autocomplete="off"]').setValue('documents');
        inertia.post.mockImplementationOnce((_url, options) => {
            (wrapper.vm as unknown as { form: { errors: Record<string, string> } }).form.errors.backup_before_overwrite = safetyWarning;
            options.onError({ backup_before_overwrite: safetyWarning });
        });
        await wrapper.findAll('button').find((button) => button.text() === 'Queue restore')!.trigger('click');
        expect(wrapper.text()).toContain('Select restore mode');
        expect(wrapper.find('[role="alert"]').text()).toBe(safetyWarning);
        expect((wrapper.find('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(true);
    });

    it.each(['selected_backup_key', 'backup_run_id'])('returns to selection and displays %s validation errors', async (field) => {
        const wrapper = mountForm();
        const continueButton = () => wrapper.findAll('button').find((button) => button.text() === 'Continue');
        await continueButton()?.trigger('click');
        await continueButton()?.trigger('click');
        inertia.post.mockImplementationOnce((_url, options) => {
            (wrapper.vm as unknown as { form: { errors: Record<string, string> } }).form.errors[field] = warning;
            options.onError({ [field]: warning });
        });
        await wrapper.findAll('button').find((button) => button.text() === 'Queue restore')?.trigger('click');

        expect(wrapper.text()).toContain('Select backup');
        expect(wrapper.find('[role="alert"]').text()).toBe(warning);
        expect(wrapper.text()).not.toContain('Confirm restore');
    });

    it('resolves each selected remote run before showing its historical source or submitting its ID', async () => {
        const backups = [
            { key: 'old.tar.gz', backup_run_id: 81, belongs_to_job: true },
            { key: 'path.tar.gz', backup_run_id: 82, belongs_to_job: true },
        ];
        const wrapper = mountForm(false, 'local', 'docker_volume', { backupRunId: null, preselectedBackupKey: null, backups });
        const form = (wrapper.vm as any).form;
        await wrapper.get('input[value="old.tar.gz"]').setValue();
        expect(form.backup_run_id).toBe(81);
        await wrapper.findAll('button').find((button) => button.text() === 'Continue')!.trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/backup-jobs/7/restore', { backup_run_id: 81 }, expect.objectContaining({ preserveState: 'errors' }));
        expect(wrapper.find('input[value="inplace"]').exists()).toBe(false);
        const options = inertia.get.mock.calls[0][2];
        options.onError({ backup_run_id: 'Historical run unavailable' });
        options.onFinish();
        await wrapper.get('input[value="path.tar.gz"]').setValue();
        expect(wrapper.text()).toContain('Historical run unavailable');
        expect(form.backup_run_id).toBe(82);
        await wrapper.findAll('button').find((button) => button.text() === 'Continue')!.trigger('click');
        expect(inertia.get).toHaveBeenLastCalledWith('/backup-jobs/7/restore', { backup_run_id: 82 }, expect.any(Object));
        expect(inertia.post).not.toHaveBeenCalled();
        wrapper.unmount();

        // A non-preserving Inertia visit mounts the server's historical context.
        const resolved = mountForm(false, 'local', 'docker_volume', {
            backupRunId: 82, preselectedBackupKey: 'path.tar.gz', backups: [backups[1]],
            isDockerVolumeSource: false, sourceVolumeName: null, sourceLabel: '/historical/path',
            sourceDockerHostId: 2, generatedTargetVolumeName: 'historical-path-restored',
        });
        const next = () => resolved.findAll('button').find((button) => button.text() === 'Continue')!;
        await next().trigger('click');
        expect(resolved.find('input[value="inplace"]').exists()).toBe(false);
        await next().trigger('click');
        expect(resolved.text()).toContain('/historical/path');
        inertia.post.mockImplementationOnce(function (this: any) {
            expect(this.backup_run_id).toBe(82);
            expect(this.selected_backup_key).toBe('path.tar.gz');
            expect(this.mode).toBe('new_volume');
        });
        await resolved.findAll('button').find((button) => button.text() === 'Queue restore')!.trigger('click');
        expect(inertia.post).toHaveBeenCalledOnce();
    });

    it('submits the historical backup run context', async () => {
        const wrapper = mount(RestoreCreate, {
            props: {
                job: { id: 7, name: 'Documents', volume_name: 'current-documents', is_docker_volume_source: true, destination: { name: 'Current' } },
                restoreDestination: { id: 3, name: 'Historical' },
                backups: [
                    { key: 'daily.tar.gz', display_name: 'daily.tar.gz', belongs_to_job: true, last_modified: null, size: 42 },
                    { key: 'other.tar.gz', display_name: 'other.tar.gz', belongs_to_job: true, last_modified: null, size: 42 },
                ],
                backupRunId: 42,
                preselectedBackupKey: 'daily.tar.gz',
                isDockerVolumeSource: true,
                sourceVolumeName: 'historical-documents',
                sourceLabel: 'historical-documents',
                generatedTargetVolumeName: 'historical-documents-restored',
            },
            global: {
                stubs: {
                    AppLayout: { template: '<main><slot name="actions" /><slot /></main>' },
                },
            },
        });

        expect(wrapper.text()).not.toContain('other.tar.gz');
        const continueButton = () => wrapper.findAll('button').find((button) => button.text() === 'Continue');
        await continueButton()?.trigger('click');
        await continueButton()?.trigger('click');
        await wrapper.findAll('button').find((button) => button.text() === 'Queue restore')?.trigger('click');

        expect(inertia.post).toHaveBeenCalledWith('/backup-jobs/7/restore', expect.objectContaining({ onError: expect.any(Function) }));
        expect((wrapper.vm as unknown as { form: { backup_run_id: number } }).form.backup_run_id).toBe(42);
        expect(wrapper.text()).toContain('historical-documents');
        expect(wrapper.text()).not.toContain('current-documents');
    });
});
