import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import HostScope from '@/Components/HostScope.vue';
import Volumes from './Volumes/Index.vue';
import Stacks from './Stacks/Index.vue';
import Jobs from './BackupJobs/Index.vue';
import Job from './BackupJobs/Show.vue';
import Pagination from '@/Components/Pagination.vue';
import ActionIcon from '@/Components/ActionIcon.vue';

const inertia = vi.hoisted(() => ({ page: { url: '/volumes', props: { can: { manageSensitiveData: true }, deployment: { local_execution_enabled: false } } }, get: vi.fn(), post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => inertia.page, router: { get: inertia.get, post: inertia.post, reload: inertia.reload, replace: vi.fn() },
}));
vi.mock('@/i18n', () => ({ useI18n: () => ({ t: (key: string) => key, formatDate: (date: any) => date, timezone: 'UTC' }) }));
const hosts = [
    { id: 1, name: 'Local', canSync: true, canBackup: true, last_inventory_at: '2026-09-20', status: 'local' },
    { id: 2, name: 'Remote', canSync: false, canBackup: true, last_inventory_at: '2026-09-21', status: 'online' },
];
const filters = { docker_host_id: null };
const volume = (id: number) => ({ id, name: 'data', identity: `${id}:data`, docker_host_id: id, docker_host: hosts[id - 1], exists: true, canBackup: true, create_job_url: `/backup-jobs/create?volume=data&docker_host_id=${id}` });
const paginated = (data: any[]) => ({ data, meta: { current_page: 1, last_page: 3, per_page: 10, total: 30 } });
const wrappers: ReturnType<typeof mount>[] = [];
function render(component: any, props: any) {
    const wrapper = mount(component, { props, global: { stubs: { AppLayout: { template: '<main><slot name="actions"/><slot/></main>' }, StatusBadge: true } } });
    wrappers.push(wrapper);
    return wrapper;
}
beforeEach(() => { vi.clearAllMocks(); inertia.page.url = '/volumes'; });
afterEach(() => {
    wrappers.splice(0).forEach(wrapper => wrapper.unmount());
    vi.useRealTimers();
});

it('translates host scope labels and reasons in all nine locales', () => {
    const locales = import.meta.glob('../i18n/locales/*.json', { eager: true, import: 'default' }) as Record<string, Record<string, string>>;
    expect(Object.keys(locales)).toHaveLength(9);
    const keys = Object.keys(locales['../i18n/locales/en.json']).filter(key => key.startsWith('hostScope.'));
    for (const locale of Object.values(locales)) {
        for (const key of keys) expect(locale[key]?.length).toBeGreaterThan(0);
        expect(locale['stackBackup.details']?.length).toBeGreaterThan(0);
        expect(locale).not.toHaveProperty('hostScope.remoteStack');
    }
});

it('changes scope repeatedly, omits all and resets all pagers while preserving filters', async () => {
    inertia.page.url = '/backup-jobs?search=data&sort=name&direction=asc&per_page=25&page=3&runs_page=4&restores_page=2';
    const wrapper = render(HostScope, { hosts, filters });
    await wrapper.find('select').setValue('2');
    expect(inertia.get.mock.lastCall?.[0]).toBe('/backup-jobs?search=data&sort=name&direction=asc&per_page=25&docker_host_id=2');
    expect(inertia.get.mock.lastCall?.[2].preserveState).toBe(false);
    inertia.page.url = inertia.get.mock.lastCall![0];
    await wrapper.setProps({ filters: { docker_host_id: 2 } });
    inertia.get.mock.lastCall![2].onFinish();
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).not.toContain('2026-09-20');
    await wrapper.find('select').setValue('');
    expect(inertia.get.mock.lastCall?.[0]).not.toContain('docker_host_id');
    inertia.get.mock.lastCall![2].onFinish();
    await wrapper.vm.$nextTick();
    await wrapper.find('button').trigger('click');
    expect(inertia.reload).toHaveBeenCalledOnce();
    expect(inertia.post).not.toHaveBeenCalled();
});

it('keeps homonymous volume shortcuts host-qualified and sync explicitly local', async () => {
    const wrapper = render(Volumes, { hosts, filters, volumes: [volume(1), volume(2)] });
    expect(wrapper.findAll('a[href="/backup-jobs/create?volume=data&docker_host_id=2"]')).toHaveLength(2);
    expect(wrapper.findAll('a[href="/backup-jobs?search=data&docker_host_id=1"]')).toHaveLength(2);
    await wrapper.findAll('button').find(button => button.text() === 'hostScope.syncLocal')!.trigger('click');
    expect(inertia.post.mock.lastCall?.slice(0, 2)).toEqual(['/volumes/sync', { async: true, docker_host_id: 1 }]);
    await wrapper.setProps({ filters: { docker_host_id: 2 }, volumes: [{ ...volume(2), canBackup: false }] });
    expect(wrapper.text()).not.toContain('hostScope.syncLocal');
    expect(wrapper.findAll('a[href*="/create?"]')).toHaveLength(0);
});

it('distinguishes same-name stacks and honors server bulk backup availability with unknown counts', async () => {
    const stacks = hosts.map(host => ({ name: 'app', identity: `${host.id}:app`, docker_host_id: host.id, docker_host: host, volumes: [volume(host.id)], existing_volumes: 1, total_volumes: 1, container_count: null, canBackup: true }));
    const wrapper = render(Stacks, { hosts, filters, stacks, destinations: [{ id: 1 }], timezones: [], appTimezone: 'UTC' });
    expect(wrapper.text()).toContain('hostScope.containers: Unknown');
    expect(wrapper.findAll('button').filter(button => button.text() === 'Back up stack')).toHaveLength(2);
    await wrapper.setProps({ stacks: [{ ...stacks[1], canBackup: false, backup_unavailable_reason: 'host_maintenance' }] });
    expect(wrapper.findAll('button').filter(button => button.text() === 'Back up stack')).toHaveLength(0);
});

it.each([null, 2])('targets remote stack A explicitly in scope %s and excludes host B destinations', async (scope) => {
    inertia.page.url = '/stacks';
    const remoteHosts = [{ ...hosts[1], name: 'Remote A' }, { ...hosts[1], id: 3, name: 'Remote B' }];
    const stacks = remoteHosts.map(host => ({ name: 'same-project', identity: `${host.id}:same-project`, docker_host_id: host.id, docker_host: host, volumes: [{ ...volume(host.id), name: `data-${host.id}` }], existing_volumes: 1, canBackup: true }));
    const destinations = [
        { id: 31, name: 'B archive', provider: 'local', docker_host_id: 3 },
        { id: 11, name: 'Central archive', provider: 'docker_volume', docker_host_id: 1 },
        { id: 21, name: 'A archive', provider: 'local', docker_host_id: 2 },
        { id: 22, name: 'A volume archive', provider: 'docker_volume', docker_host_id: 2 },
        { id: 40, name: 'Shared archive', provider: 'aws_s3', docker_host_id: null },
    ];
    const wrapper = render(Stacks, { hosts: remoteHosts, filters: { docker_host_id: scope }, stacks: scope ? [stacks[0]] : stacks, destinations, timezones: [], appTimezone: 'UTC' });
    const sections = wrapper.findAll('section').filter(section => section.find('h2').exists());
    expect(sections[0].text()).toContain('data-2');
    expect(sections[0].text()).not.toContain('data-3');
    await sections[0].findAll('button').find(button => button.text() === 'Back up stack')!.trigger('click');
    const dialog = wrapper.find('[role="dialog"]');
    expect(dialog.text()).toContain('Remote A · #2');
    expect(dialog.text()).not.toContain('Remote B');
    expect(dialog.text()).toContain('stackBackup.details');
    expect(dialog.find('select').findAll('option').map(option => option.attributes('value'))).toEqual(['21', '22', '40']);
    await dialog.findAll('button').find(button => button.text() === 'Start backup')!.trigger('click');
    expect(inertia.post).toHaveBeenCalledOnce();
    expect(inertia.post.mock.lastCall?.slice(0, 2)).toEqual(['/stacks/backup', {
        stack: 'same-project', docker_host_id: 2, backup_destination_id: 21,
        schedule_type: 'daily', schedule_config: { time: '02:00', everyHours: 6, dayOfWeek: 'sunday', expression: '0 2 * * *' }, timezone: '',
    }]);
    inertia.post.mock.lastCall![2].onSuccess();
    await wrapper.vm.$nextTick();
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    if (!scope) {
        await sections[1].findAll('button').find(button => button.text() === 'Back up stack')!.trigger('click');
        const secondDialog = wrapper.find('[role="dialog"]');
        expect(secondDialog.text()).toContain('Remote B · #3');
        expect(secondDialog.find('select').findAll('option').map(option => option.attributes('value'))).toEqual(['31', '40']);
        await secondDialog.findAll('button').find(button => button.text() === 'Start backup')!.trigger('click');
        expect(inertia.post.mock.lastCall?.[1]).toMatchObject({ stack: 'same-project', docker_host_id: 3, backup_destination_id: 31 });
    }
});

it('runs existing remote jobs without destination or schedule overrides', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const stack = { name: null, identity: '2:', docker_host_id: 2, docker_host: hosts[1], volumes: [volume(2)], existing_volumes: 1, configured_job_volumes: 1, canBackup: true };
    const wrapper = render(Stacks, { hosts, filters, stacks: [stack], destinations: [], timezones: [], appTimezone: 'UTC' });
    await wrapper.findAll('button').find(button => button.text() === 'Run all jobs')!.trigger('click');
    expect(inertia.post.mock.lastCall?.slice(0, 2)).toEqual(['/stacks/backup', { stack: null, docker_host_id: 2 }]);
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    confirm.mockRestore();
});

it('retains host scope in job pagination and honors server backup availability', async () => {
    const wrapper = render(Jobs, { hosts, filters: { docker_host_id: 2 }, jobs: paginated([{ id: 7, name: 'data', docker_host_id: 2, docker_host: { ...hosts[1], canBackup: false }, status: 'active' }]), defaultPerPage: 10 });
    await wrapper.findComponent(Pagination).findAll('button').find(button => button.text() === '2')!.trigger('click');
    expect(inertia.get.mock.lastCall?.[1]).toMatchObject({ page: 2, docker_host_id: 2 });
    const actions = wrapper.findAllComponents(ActionIcon).filter(action => action.props('label') === 'Run now');
    expect(actions).toHaveLength(2);
    expect(actions.every(action => action.props('disabled') === true)).toBe(true);
});

it('shows historical backup and restore source/target identities separately from current job', async () => {
    const wrapper = render(Job, { hosts, filters: { docker_host_id: 2 }, job: { id: 7, docker_host_id: 2, name: 'current' }, runs: paginated([{ id: 8, source_name: 'old-source', docker_host: hosts[0] }]), restoreRuns: paginated([{ id: 9, source_docker_host: hosts[0], target_docker_host: hosts[1], source_volume_name: 'data', target_volume_name: 'data-restored' }]) });
    expect(wrapper.text()).toContain('old-source');
    expect(wrapper.text()).toContain('Local · #1');
    expect(wrapper.text()).toContain('Remote · #2');
    for (const pager of wrapper.findAllComponents(Pagination)) {
        await pager.findAll('button').find(button => button.text() === '2')!.trigger('click');
        expect(inertia.get.mock.lastCall?.[1]).toMatchObject({ [pager.props('pageParam')!]: 2, docker_host_id: 2 });
    }
});

it('cancels a pending old-host search before a delayed host navigation completes', async () => {
    vi.useFakeTimers();
    inertia.page.url = '/backup-jobs?docker_host_id=1&page=3';
    const wrapper = render(Jobs, { hosts, filters: { docker_host_id: 1 }, jobs: paginated([]), defaultPerPage: 10 });
    await wrapper.find('[data-list-search]').setValue('pending search');
    await vi.advanceTimersByTimeAsync(100);
    await wrapper.findComponent(HostScope).find('select').setValue('2');
    expect(inertia.get).toHaveBeenCalledOnce();
    const url = new URL(inertia.get.mock.lastCall![0], 'http://localhost');
    expect(url.searchParams.get('docker_host_id')).toBe('2');
    expect(url.searchParams.get('search')).toBe('pending search');
    expect(url.searchParams.has('page')).toBe(false);
    // Keep the old page mounted while the new host request is in flight.
    await vi.advanceTimersByTimeAsync(1000);
    expect(inertia.get).toHaveBeenCalledOnce();
});

it.each(['success', 'error'] as const)('blocks old-host controls during navigation and recovers on %s finish', async (outcome) => {
    vi.useFakeTimers();
    inertia.page.url = '/backup-jobs?docker_host_id=1';
    const wrapper = render(Jobs, { hosts, filters: { docker_host_id: 1 }, jobs: paginated([{ id: 7, name: 'old host job' }]), defaultPerPage: 10 });
    await wrapper.findAll('button').find(button => button.text() === 'Filters')!.trigger('click');
    const scope = wrapper.findComponent(HostScope);
    await scope.find('select').setValue('2');
    const finish = inertia.get.mock.lastCall![2].onFinish;
    const search = wrapper.find('[data-list-search]');
    expect(search.attributes('disabled')).toBeDefined();
    expect(wrapper.findAll('input, select').every(input => input.attributes('disabled') !== undefined)).toBe(true);
    expect(wrapper.findAll('th button').every(button => button.attributes('disabled') !== undefined)).toBe(true);
    const pager = wrapper.findComponent(Pagination);
    expect(pager.findAll('button').every(button => button.attributes('disabled') !== undefined)).toBe(true);
    await search.setValue('typed while pending');
    search.element.dispatchEvent(new Event('input', { bubbles: true }));
    const status = wrapper.findAll('label').find(label => label.text().startsWith('Status'))!.find('select');
    await status.setValue('paused');
    status.element.dispatchEvent(new Event('change', { bubbles: true }));
    await pager.find('select').setValue('20');
    pager.find('select').element.dispatchEvent(new Event('change', { bubbles: true }));
    pager.findAll('button').find(button => button.text() === '2')!.element.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    wrapper.find('th button').element.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(1000);
    expect(inertia.get).toHaveBeenCalledOnce();
    if (outcome === 'success') {
        await wrapper.setProps({ filters: { docker_host_id: 2 }, jobs: paginated([{ id: 8, name: 'new host job' }]) });
    }
    finish();
    await wrapper.vm.$nextTick();
    expect(search.attributes('disabled')).toBeUndefined();
    expect((scope.find('select').element as HTMLSelectElement).value).toBe(outcome === 'success' ? '2' : '1');
    expect(wrapper.text()).toContain(outcome === 'success' ? 'new host job' : 'old host job');
    await search.setValue('after finish');
    await vi.advanceTimersByTimeAsync(300);
    expect(inertia.get).toHaveBeenCalledTimes(2);
    expect(inertia.get.mock.lastCall![1].docker_host_id).toBe(outcome === 'success' ? 2 : 1);
});

it('blocks overlapping host selection and ignores a stale finish after a later navigation starts', async () => {
    const wrapper = render(HostScope, { hosts, filters: { docker_host_id: 1 } });
    await wrapper.find('select').setValue('2');
    const firstFinish = inertia.get.mock.lastCall![2].onFinish;
    await wrapper.find('select').setValue('');
    wrapper.find('select').element.dispatchEvent(new Event('change', { bubbles: true }));
    expect(inertia.get).toHaveBeenCalledOnce();
    firstFinish();
    await wrapper.vm.$nextTick();
    await wrapper.find('select').setValue('2');
    expect(inertia.get).toHaveBeenCalledTimes(2);
    firstFinish();
    await wrapper.vm.$nextTick();
    expect(wrapper.find('select').attributes('disabled')).toBeDefined();
    expect(wrapper.emitted('navigating')?.at(-1)).toEqual([true]);
    inertia.get.mock.lastCall![2].onFinish();
    await wrapper.vm.$nextTick();
    expect(wrapper.find('select').attributes('disabled')).toBeUndefined();
});

it('only offers shared or same-host stack destinations and defaults to an eligible one', async () => {
    const stack = { name: 'app', identity: '1:app', docker_host_id: 1, docker_host: hosts[0], volumes: [volume(1)], existing_volumes: 1, canBackup: true };
    const destinations = [
        { id: 2, name: 'A remote archive', provider: 'local', docker_host_id: 2 },
        { id: 3, name: 'B shared archive', provider: 'aws_s3', docker_host_id: null },
        { id: 1, name: 'C local archive', provider: 'docker_volume', docker_host_id: 1 },
    ];
    const wrapper = render(Stacks, { hosts, filters, stacks: [stack], destinations, timezones: [], appTimezone: 'UTC' });
    await wrapper.findAll('button').find(button => button.text() === 'Back up stack')!.trigger('click');
    const dialog = wrapper.find('.fixed');
    const select = dialog.find('select');
    expect(select.findAll('option').map(option => option.attributes('value'))).toEqual(['3', '1']);
    expect((select.element as HTMLSelectElement).value).toBe('3');
    await select.setValue('1');
    await dialog.findAll('button').find(button => button.text() === 'Start backup')!.trigger('click');
    expect(inertia.post.mock.lastCall?.[1]).toMatchObject({ docker_host_id: 1, backup_destination_id: 1 });
    await dialog.findAll('button').find(button => button.text() === 'Cancel')!.trigger('click');
    await wrapper.setProps({ destinations: [destinations[0]] });
    expect(wrapper.findAll('button').find(button => button.text() === 'Back up stack')!.attributes('disabled')).toBeDefined();
    expect(wrapper.find('.fixed').exists()).toBe(false);
});

it('preserves the restore tab while changing scope and resets both history pagers', async () => {
    inertia.page.url = '/backup-jobs/7?docker_host_id=1&runs_page=3&restores_page=2&per_page=10';
    const wrapper = render(Job, { hosts, filters: { docker_host_id: 1 }, job: { id: 7, docker_host_id: 1, name: 'current' }, runs: paginated([]), restoreRuns: paginated([]) });
    const restoreTab = wrapper.findAll('[role="tab"]').find(tab => tab.text() === 'Restore history')!;
    await restoreTab.trigger('click');
    await wrapper.findComponent(HostScope).find('select').setValue('2');
    expect(inertia.get.mock.lastCall?.[0]).toBe('/backup-jobs/7?docker_host_id=2&per_page=10');
    expect(inertia.get.mock.lastCall?.[2].preserveState).toBe(true);
    await wrapper.setProps({ filters: { docker_host_id: 2 }, runs: paginated([]), restoreRuns: paginated([{ id: 9, source_docker_host: hosts[0], target_docker_host: hosts[1] }]) });
    expect(restoreTab.attributes('aria-selected')).toBe('true');
    expect(wrapper.findAll('[role="tabpanel"]')[1].isVisible()).toBe(true);
});
