import { mount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import JobForm from './BackupJobs/Form.vue';
import JobIndex from './BackupJobs/Index.vue';
import JobShow from './BackupJobs/Show.vue';
import RestoreForm from './Restore/Create.vue';
import DestinationForm from './Destinations/Form.vue';
import RunShow from './BackupRuns/Show.vue';
import VolumeIndex from './Volumes/Index.vue';
import GroupForm from './BackupGroups/Form.vue';
import GroupIndex from './BackupGroups/Index.vue';
import GroupShow from './BackupGroups/Show.vue';
import GroupRunShow from './BackupGroups/RunShow.vue';
import StackIndex from './Stacks/Index.vue';
import { hostSupports } from '@/Composables/useDeployment';

const inertia = vi.hoisted(() => ({ page: null as any, form: null as any, submitted: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => inertia.page,
    usePoll: vi.fn(),
    router: { post: inertia.post },
    useForm: (data: any) => {
        let transform = (value: any) => value;
        const form = reactive({ ...data, errors: {}, processing: false,
            transform: (callback: any) => { transform = callback; return form; },
            post: (url: string) => inertia.submitted(url, transform(Object.fromEntries(Object.keys(data).map((key) => [key, form[key]])))),
            put: (url: string) => form.post(url),
        });
        inertia.form = form;
        return form;
    },
}));
vi.mock('@/i18n', () => ({ useI18n: () => ({
    t: (key: string, values?: any) => key + (values?.paths ? ` ${values.paths}` : ''),
    translateError: (key: string) => key, formatDate: (value: any) => value ?? '—', timezone: { value: 'UTC' },
}) }));

const local = { id: 1, name: 'Local', driver: 'local' };
const remote = { id: 2, name: 'Agent A', driver: 'agent', agent_capabilities: ['inventory', 'backup-v1', 'restore-v1'], agent_protocol_version: 1 };
const other = { ...remote, id: 3, name: 'Agent B' };
const old = { ...remote, id: 4, name: 'Old agent', agent_capabilities: ['inventory'] };
const pagination = { data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } };
const destinations = [
    { id: 1, name: 'Local archive', provider: 'local', docker_host_id: 1 },
    { id: 2, name: 'A archive', provider: 'docker_volume', docker_host_id: 2 },
    { id: 3, name: 'B archive', provider: 'local', docker_host_id: 3 },
    { id: 4, name: 'Shared S3', provider: 'aws_s3', docker_host_id: null },
];
const volumes = [
    { docker_host_id: 1, name: 'data' }, { docker_host_id: 2, name: 'data' },
    { docker_host_id: 2, name: 'a-only' }, { docker_host_id: 3, name: 'b-only' },
];
const wrappers: ReturnType<typeof mount>[] = [];
const render = (component: any, props: any) => {
    const wrapper = mount(component, { props: { ...([VolumeIndex, StackIndex, JobIndex, JobShow].includes(component) ? { hosts: [{ ...remote, canBackup: true }], filters: { docker_host_id: null } } : {}), ...props }, global: { stubs: {
        AppLayout: { template: '<main><slot name="title-actions" /><slot name="actions" /><slot /></main>' },
        InfoTooltip: true, Pagination: true, StatusBadge: true,
    } } });
    wrappers.push(wrapper);
    return wrapper;
};
const jobProps = () => ({ job: null, hosts: [local, remote, other, old], volumes, destinations,
    containers: [{ id: 'a', names: 'app-a', docker_host_id: 2 }, { id: 'b', names: 'app-b', docker_host_id: 3 }],
    notificationChannels: [], defaultNotificationChannelIds: [], alertRules: [], timezones: ['UTC'], appTimezone: 'UTC',
});
const restoreProps = () => ({
    hosts: [local, remote, other, old], volumes,
    job: { id: 7, name: 'Data', docker_host_id: 2, destination: destinations[3] },
    restoreDestination: destinations[3], sourceDockerHostId: 2, targetDockerHostId: 2,
    backups: [{ key: 'backup.tar.gz', belongs_to_job: true }], preselectedBackupKey: 'backup.tar.gz',
    isDockerVolumeSource: true, sourceVolumeName: 'data', sourceLabel: 'data', generatedTargetVolumeName: 'data-restored',
});
const button = (wrapper: any, label: string) => wrapper.findAll('button').find((item: any) => item.text() === label)!;

beforeEach(() => {
    vi.clearAllMocks();
    inertia.page = reactive({ url: '/backup-jobs', props: {
        can: { manageSensitiveData: true, runDockerActions: false },
        deployment: { mode: 'orchestrator', local_execution_enabled: false },
    } });
});
afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()));

describe('Host-scoped backup and restore workflows', () => {
    it('includes the local host in both volume shortcut links', () => {
        inertia.page.props.deployment = { mode: 'hybrid', local_execution_enabled: true };
        inertia.page.props.can.runDockerActions = true;
        const wrapper = render(VolumeIndex, { volumes: [{ name: 'app_data', docker_host_id: 1, exists: true, backup_jobs: [], canBackup: true, create_job_url: '/backup-jobs/create?volume=app_data&docker_host_id=1' }] });
        const links = wrapper.findAll('a[href^="/backup-jobs/create?"]');
        expect(links).toHaveLength(2);
        for (const link of links) expect(link.attributes('href')).toBe('/backup-jobs/create?volume=app_data&docker_host_id=1');
    });

    it.each(['?volume=app_data', '?volume=app_data&docker_host_id=1', '?volume=app_data&docker_host_id=2'])('preserves shortcut host identity for %s with agents sorted first', async (query) => {
        inertia.page.props.deployment = { mode: 'hybrid', local_execution_enabled: true };
        inertia.page.url = `/backup-jobs/create${query}`;
        const wrapper = render(JobForm, { ...jobProps(), hosts: [remote, local], volumes: [
            { docker_host_id: 1, name: 'app_data' }, { docker_host_id: 2, name: 'app_data' },
        ] });
        const expectedHost = query.endsWith('=2') ? 2 : 1;
        expect(inertia.form.docker_host_id).toBe(expectedHost);
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted).toHaveBeenCalledWith('/backup-jobs', expect.objectContaining({ docker_host_id: expectedHost, volume_name: 'app_data' }));
    });

    it.each(['', '&docker_host_id=1', '&docker_host_id=99', '&docker_host_id=4'])('blocks unavailable shortcut hosts without selecting a namesake elsewhere: %s', async (hostQuery) => {
        inertia.page.url = `/backup-jobs/create?volume=data${hostQuery}`;
        const wrapper = render(JobForm, { ...jobProps(), hosts: [remote, old] });
        expect(inertia.form.docker_host_id).not.toBe(2);
        expect(inertia.form.volume_name).toBe('');
        expect(wrapper.text()).toContain('hostWorkflow.unavailable');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted).not.toHaveBeenCalled();
    });

    it('keeps the stored job host when editing despite conflicting shortcut parameters', () => {
        inertia.page.url = '/backup-jobs/7/edit?volume=data&docker_host_id=3';
        render(JobForm, { ...jobProps(), job: { id: 7, docker_host_id: 2, volume_name: 'a-only' } });
        expect(inertia.form.docker_host_id).toBe(2);
        expect(inertia.form.volume_name).toBe('a-only');
    });

    it('offers a new remote job in orchestrator mode and submits its host', async () => {
        const index = render(JobIndex, { jobs: pagination, defaultPerPage: 25 });
        expect(index.find('a[href="/backup-jobs/create"]').exists()).toBe(true);
        const wrapper = render(JobForm, jobProps());
        expect(wrapper.get('[data-source-host]').element.value).toBe('2');
        await wrapper.get('input[placeholder="App data nightly backup"]').setValue('Remote data');
        await wrapper.get('input[autocomplete="off"]').setValue('data');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted).toHaveBeenCalledWith('/backup-jobs', expect.objectContaining({ docker_host_id: 2, volume_name: 'data', backup_destination_id: 2 }));
    });

    it('scopes homonymous volumes, containers and destination ownership when switching hosts', async () => {
        const wrapper = render(JobForm, jobProps());
        await wrapper.get('input[autocomplete="off"]').trigger('focus');
        expect(wrapper.findAll('button').filter((item) => item.text() === 'data')).toHaveLength(1);
        expect(wrapper.text()).toContain('a-only');
        expect(wrapper.text()).not.toContain('b-only');
        expect(wrapper.text()).not.toContain('Local archive');
        expect(wrapper.text()).not.toContain('B archive');
        expect(wrapper.text()).toContain('Shared S3');
        await wrapper.get('input[value="host_path"]').setValue();
        await wrapper.get('button[aria-label="Stop containers before backup"]').trigger('click');
        expect(wrapper.text()).toContain('app-a');
        expect(wrapper.text()).not.toContain('app-b');
        inertia.form.stop_container_names = ['app-a'];
        await wrapper.get('[data-source-host]').setValue('3');
        expect(inertia.form.stop_container_names).toEqual([]);
        expect(inertia.form.volume_name).toBe('');
        expect(inertia.form.backup_destination_id).toBe(3);
        expect(wrapper.text()).toContain('app-b');
        expect(wrapper.text()).not.toContain('app-a');
    });

    it('disables local execution and old capabilities but allows remote group mode', async () => {
        const wrapper = render(JobForm, jobProps());
        expect(wrapper.get('[data-source-host] option[value="1"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-source-host] option[value="4"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('input[value="group"]').attributes('disabled')).toBeUndefined();
        inertia.form.docker_host_id = 1;
        inertia.form.volume_name = 'data';
        await nextTick();
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted).not.toHaveBeenCalled();
    });

    it.each(['existing', 'new'])('submits a remote grouped job with %s group selection and keeps grouping across hosts', async (selection) => {
        const wrapper = render(JobForm, { ...jobProps(), groups: [{ id: 9, name: 'Nightly' }] });
        await wrapper.get('input[value="group"]').setValue();
        await wrapper.get(`input[value="${selection}"]`).setValue();
        inertia.form.new_group.name = 'Multi-host';
        await wrapper.get('[data-source-host]').setValue('3');
        expect(inertia.form.planning_mode).toBe('group');
        await wrapper.get('input[autocomplete="off"]').setValue('b-only');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted).toHaveBeenCalledWith('/backup-jobs', expect.objectContaining({
            docker_host_id: 3, volume_name: 'b-only', backup_destination_id: 3,
            planning_mode: 'group', group_selection: selection, backup_job_group_id: 9,
        }));
        expect(wrapper.text()).toContain('hostWorkflow.groupExecution');
    });

    it('preserves a remote member when editing and allows resume without an individual run action', async () => {
        const job = { id: 7, name: 'Data', docker_host_id: 2, docker_host: remote, volume_name: 'data', backup_job_group_id: 9, status: 'paused' };
        const form = render(JobForm, { ...jobProps(), job, groups: [{ id: 9, name: 'Nightly' }] });
        await form.get('form').trigger('submit');
        expect(inertia.submitted).toHaveBeenCalledWith('/backup-jobs/7', expect.objectContaining({ planning_mode: 'group', docker_host_id: 2, backup_job_group_id: 9 }));
        const show = render(JobShow, { job, runs: pagination, restoreRuns: pagination });
        expect(button(show, 'Run now')).toBeUndefined();
        expect(button(show, 'Resume').attributes('disabled')).toBeUndefined();
        await button(show, 'Resume').trigger('click');
        expect(inertia.post).toHaveBeenCalledWith('/backup-jobs/7/resume');
    });

    it.each([old, { ...remote, maintenance_requested: true }, { ...remote, agent_revoked_at: '2026-09-18' }])('blocks grouped submission on an ineligible host: $name', async (host) => {
        const wrapper = render(JobForm, { ...jobProps(), hosts: [host],
            job: { id: 7, docker_host_id: host.id, volume_name: 'data', backup_job_group_id: 9 },
            volumes: [{ docker_host_id: host.id, name: 'data' }], groups: [{ id: 9, name: 'Nightly' }],
        });
        expect(wrapper.get('input[value="group"]').attributes('disabled')).toBeDefined();
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted).not.toHaveBeenCalled();
    });

    it('offers group creation and actions in orchestrator mode and distinguishes homonymous members by host', async () => {
        const members = [remote, other].map((host) => ({ id: host.id, name: 'Data', job_name: 'Data', source_label: 'data', status: 'active', docker_host_id: host.id, docker_host: { id: host.id, name: host.name, is_local: false } }));
        const group = { id: 9, name: 'Nightly', status: 'active', can_run: true, can_run_reason: null, members, members_count: 2 };
        const index = render(GroupIndex, { groups: { ...pagination, data: [group] }, defaultPerPage: 25 });
        expect(index.find('a[href="/backup-groups/create"]').exists()).toBe(true);
        const stacks = render(StackIndex, { stacks: [{ name: 'Local stack', volumes: [], existing_volumes: 1, configured_job_volumes: 1 }], destinations: [], timezones: ['UTC'], appTimezone: 'UTC' });
        expect(button(stacks, 'Run all jobs')).toBeUndefined();
        const form = render(GroupForm, { group, notificationChannels: [], timezones: ['UTC'], appTimezone: 'UTC' });
        await form.get('form').trigger('submit');
        expect(inertia.submitted).toHaveBeenCalledWith('/backup-groups/9', expect.objectContaining({ name: 'Nightly' }));
        const show = render(GroupShow, { group, runs: pagination });
        await button(show, 'Run now').trigger('click');
        expect(inertia.post).toHaveBeenCalledWith('/backup-groups/9/run');
        const run = render(GroupRunShow, { run: { id: 1, members } });
        for (const wrapper of [form, show, run]) {
            expect(wrapper.text()).toContain('Agent A (#2)');
            expect(wrapper.text()).toContain('Agent B (#3)');
        }
        inertia.page.props.can.manageSensitiveData = false;
        await nextTick();
        expect(form.find('form').exists()).toBe(false);
        expect(show.find('button').exists()).toBe(false);
        expect(index.find('a[href="/backup-groups/create"]').exists()).toBe(false);
    });

    it('uses server group eligibility on both list layouts and details while preserving management', async () => {
        const group = { id: 9, name: 'Unavailable', status: 'active', members: [], can_run: false, can_run_reason: 'hostWorkflow.groupRunUnavailable' };
        const index = render(GroupIndex, { groups: { ...pagination, data: [group] }, defaultPerPage: 25 });
        const show = render(GroupShow, { group, runs: pagination });
        const runs = index.findAll('button[aria-label="Run now"]');
        expect(runs).toHaveLength(2);
        for (const run of runs) {
            expect(run.attributes('disabled')).toBeDefined();
            await run.trigger('click');
        }
        expect(button(show, 'Run now').attributes('disabled')).toBeDefined();
        await button(show, 'Run now').trigger('click');
        expect(inertia.post).not.toHaveBeenCalled();
        for (const wrapper of [index, show]) {
            expect(wrapper.text()).toContain('hostWorkflow.groupRunUnavailable');
            expect(wrapper.find('a[href="/backup-groups/9/edit"]').exists()).toBe(true);
        }
        expect(button(show, 'Pause').attributes('disabled')).toBeUndefined();
        await index.setProps({ groups: { ...pagination, data: [{ ...group, can_run: true, can_run_reason: null }] } });
        expect(index.get('button[aria-label="Run now"]').attributes('disabled')).toBeUndefined();
        await index.get('button[aria-label="Run now"]').trigger('click');
        expect(inertia.post).toHaveBeenCalledWith('/backup-groups/9/run');
    });

    it('shows remote path allowlists from the selected host', async () => {
        const wrapper = render(JobForm, { ...jobProps(), hosts: [{ ...remote, host_path_allowlist: ['/srv/data'] }] });
        await wrapper.get('input[value="host_path"]').setValue();
        expect(wrapper.text()).toContain('/srv/data');
    });

    it('defaults restore to the job host and submits A → B with the same source volume name', async () => {
        const wrapper = render(RestoreForm, restoreProps());
        await button(wrapper, 'Continue').trigger('click');
        expect(wrapper.get('[data-target-host]').element.value).toBe('2');
        expect(wrapper.get('[data-target-host] option[value="1"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-target-host] option[value="4"]').attributes('disabled')).toBeDefined();
        await wrapper.get('[data-target-host]').setValue('3');
        expect(wrapper.find('input[value="inplace"]').exists()).toBe(false);
        await wrapper.get('input.input').setValue('data');
        expect(button(wrapper, 'Continue').attributes('disabled')).toBeUndefined();
        await button(wrapper, 'Continue').trigger('click');
        expect(wrapper.text()).toContain('Agent B');
        await button(wrapper, 'Queue restore').trigger('click');
        expect(inertia.submitted).toHaveBeenCalledWith('/backup-jobs/7/restore', expect.objectContaining({ target_docker_host_id: 3, mode: 'new_volume', target_volume_name: 'data' }));
    });

    it('rejects an existing volume only on the selected target host', async () => {
        const wrapper = render(RestoreForm, restoreProps());
        await button(wrapper, 'Continue').trigger('click');
        await wrapper.get('input.input').setValue('data');
        expect(wrapper.text()).toContain('hostWorkflow.targetExists');
        expect(button(wrapper, 'Continue').attributes('disabled')).toBeDefined();
        await wrapper.get('[data-target-host]').setValue('3');
        expect(wrapper.text()).not.toContain('hostWorkflow.targetExists');
        expect(button(wrapper, 'Continue').attributes('disabled')).toBeUndefined();
    });

    it('allows same-host local archives but blocks cross-host archive transfer', async () => {
        const wrapper = render(RestoreForm, { ...restoreProps(), restoreDestination: destinations[1] });
        await button(wrapper, 'Continue').trigger('click');
        expect(wrapper.get('[data-target-host] option[value="2"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('[data-target-host] option[value="3"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).not.toContain('hostWorkflow.relayUnsupported');
        await button(wrapper, 'Continue').trigger('click');
        await button(wrapper, 'Queue restore').trigger('click');
        expect(inertia.submitted).toHaveBeenCalledWith('/backup-jobs/7/restore', expect.objectContaining({ target_docker_host_id: 2 }));
    });

    it('blocks maintenance hosts and read-only users', async () => {
        const props = { ...jobProps(), hosts: [{ ...remote, maintenance_requested_at: '2026-09-18' }] };
        const wrapper = render(JobForm, props);
        expect(wrapper.get('[data-source-host] option[value="2"]').attributes('disabled')).toBeDefined();
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted).not.toHaveBeenCalled();
        inertia.page.props.can.manageSensitiveData = false;
        await nextTick();
        expect(wrapper.find('form').exists()).toBe(false);
        const restore = render(RestoreForm, restoreProps());
        expect(restore.find('button').exists()).toBe(false);
    });

    it('runs capable remote jobs in orchestrator mode and disables maintenance runs', async () => {
        const job = { id: 7, name: 'Remote', status: 'active', docker_host_id: 2, docker_host: remote };
        const wrapper = render(JobShow, { job, runs: pagination, restoreRuns: pagination });
        expect(button(wrapper, 'Run now').attributes('disabled')).toBeUndefined();
        await button(wrapper, 'Run now').trigger('click');
        expect(inertia.post).toHaveBeenCalledWith('/backup-jobs/7/run');
        await wrapper.setProps({ hosts: [{ ...remote, maintenance_requested: true, canBackup: false }] });
        expect(button(wrapper, 'Run now').attributes('disabled')).toBeDefined();
    });

    it('keeps successful remote run restore links available in orchestrator mode', () => {
        const wrapper = render(RunShow, { run: { id: 8, status: 'success', backup_key: 'a.tar.gz', job: { id: 7, name: 'Remote', docker_host_id: 2 } } });
        expect(wrapper.find('a[href*="backup_run_id=8"]').exists()).toBe(true);
    });

    it('sends destination ownership for local providers and clears it for shared providers', async () => {
        const wrapper = render(DestinationForm, { destination: null, hosts: [remote, other], providers: [
            { value: 'local', label: 'Local', secret_fields: [] }, { value: 'aws_s3', label: 'S3', secret_fields: [] },
        ] });
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted).toHaveBeenLastCalledWith('/destinations', expect.objectContaining({ docker_host_id: 2 }));
        inertia.form.provider = 'aws_s3';
        await nextTick();
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted).toHaveBeenLastCalledWith('/destinations', expect.objectContaining({ docker_host_id: null }));
    });

    it('does not fall back to central Docker for a missing or incompatible remote host', () => {
        expect(hostSupports(undefined, 'backup-v1', true)).toBe(false);
        expect(hostSupports(old, 'backup-v1', true)).toBe(false);
        expect(hostSupports({ ...remote, agent_protocol_version: 2 }, 'backup-v1', true)).toBe(false);
        expect(hostSupports({ ...remote, agent_revoked_at: '2026-09-18' }, 'restore-v1', true)).toBe(false);
    });

    it('preserves legacy host 1 defaults and locks the host selector while running', async () => {
        inertia.page.props.deployment = { mode: 'hybrid', local_execution_enabled: true };
        const wrapper = render(JobForm, { ...jobProps(), hosts: undefined, volumes: [{ name: 'legacy' }] });
        expect(wrapper.get('[data-source-host]').element.value).toBe('1');
        await wrapper.get('input[autocomplete="off"]').setValue('legacy');
        await wrapper.get('form').trigger('submit');
        expect(inertia.submitted).toHaveBeenCalledWith('/backup-jobs', expect.objectContaining({ docker_host_id: 1, volume_name: 'legacy' }));
        await wrapper.setProps({ job: { id: 7, status: 'running' } });
        expect(wrapper.get('[data-source-host]').attributes('disabled')).toBeDefined();
    });

    it('returns target validation failures to the target selection step', async () => {
        const wrapper = render(RestoreForm, restoreProps());
        await button(wrapper, 'Continue').trigger('click');
        await button(wrapper, 'Continue').trigger('click');
        inertia.form.post = (_url: string, options: any) => options.onError({ target_docker_host_id: 'Host in maintenance' });
        await button(wrapper, 'Queue restore').trigger('click');
        expect(wrapper.find('[data-target-host]').exists()).toBe(true);
    });

    it('translates every host workflow label in all nine UI locales', () => {
        const locales = import.meta.glob('../i18n/locales/*.json', { eager: true, import: 'default' }) as Record<string, Record<string, string>>;
        const keys = Object.keys(locales['../i18n/locales/en.json']).filter((key) => key.startsWith('hostWorkflow.'));
        expect(Object.keys(locales)).toHaveLength(9);
        for (const [locale, messages] of Object.entries(locales)) {
            for (const key of keys) expect(messages[key], `${locale}: ${key}`).toBeTruthy();
        }
    });
});
