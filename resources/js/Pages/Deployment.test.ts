import { mount } from '@vue/test-utils';
import { nextTick, reactive, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AppLayout from '@/Layouts/AppLayout.vue';
import Volumes from './Volumes/Index.vue';
import Stacks from './Stacks/Index.vue';
import BackupJobs from './BackupJobs/Index.vue';
import BackupGroups from './BackupGroups/Index.vue';

const inertia = vi.hoisted(() => ({ page: null as any, post: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => inertia.page,
    router: { post: inertia.post, replace: vi.fn() },
}));
vi.mock('@/i18n', () => ({
    useI18n: () => ({
        t: (key: string) => key,
        formatDate: (value: unknown) => value ?? '—',
        locale: ref('en'), locales: ['en'], languageNames: { en: 'English' },
    }),
}));
vi.mock('@/theme', () => ({ useTheme: () => ({ isDark: ref(true), toggleTheme: vi.fn() }) }));

const wrappers: ReturnType<typeof mount>[] = [];
const layout = { template: '<main><slot name="title-actions" /><slot name="actions" /><slot /></main>' };
const pagination = { data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } };

beforeEach(() => {
    inertia.page = reactive({ props: { can: { runDockerActions: true } }, url: '/dashboard' });
    inertia.post.mockClear();
    vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })));
});
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

describe('Shared deployment mode', () => {
    it('keeps deployment roles out of the global branding', async () => {
        const wrapper = mount(AppLayout, { props: { title: 'Hosts' } });
        wrappers.push(wrapper);
        expect(wrapper.text()).toContain('VolumeVault');
        expect(wrapper.find('[data-deployment-mode]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('dockerHosts.role.hybrid');
        inertia.page.props.deployment = { mode: 'orchestrator', local_execution_enabled: false };
        await nextTick();
        expect(wrapper.find('[data-deployment-mode]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('dockerHosts.role.orchestrator');
    });

    it.each([
        ['volumes', Volumes, { volumes: [] }, 'hostScope.syncLocal'],
        ['jobs', BackupJobs, { jobs: pagination, defaultPerPage: 25 }, 'New backup job'],
        ['groups', BackupGroups, { groups: pagination, defaultPerPage: 25 }, 'New backup group'],
        ['stacks', Stacks, {
            stacks: [{ name: 'app', volumes: [], existing_volumes: 1, total_volumes: 1 }],
            destinations: [{ id: 1, name: 'Local' }], timezones: ['UTC'], appTimezone: 'UTC',
        }, 'Back up stack'],
    ])('hides local %s actions reactively without removing inventory/history', async (_name, component, props, label) => {
        const wrapper = mount(component as any, {
            props: { ...props, ...(_name !== 'groups' ? { hosts: [{ id: 1, name: 'Local', canSync: true }], filters: { docker_host_id: null } } : {}), ...(_name === 'stacks' ? { stacks: [{ ...props.stacks[0], canBackup: true }] } : {}) },
            global: { stubs: { AppLayout: layout, Pagination: true } },
        });
        wrappers.push(wrapper);
        expect(wrapper.text()).toContain(label);
        for (const flag of [false, 0, '0', 'false']) {
            inertia.page.props.deployment = { mode: 'orchestrator', local_execution_enabled: flag };
            if (_name === 'volumes') await wrapper.setProps({ hosts: [{ id: 1, name: 'Local', canSync: false }] });
            if (_name === 'stacks') await wrapper.setProps({ stacks: [{ ...props.stacks[0], canBackup: false }] });
            await nextTick();
            expect(wrapper.text()).not.toContain(label);
            expect(wrapper.find('main').exists()).toBe(true);
        }
        inertia.page.props.deployment = { mode: 'hybrid', local_execution_enabled: true };
        if (_name === 'volumes') await wrapper.setProps({ hosts: [{ id: 1, name: 'Local', canSync: true }] });
        if (_name === 'stacks') await wrapper.setProps({ stacks: [{ ...props.stacks[0], canBackup: true }] });
        await nextTick();
        expect(wrapper.text()).toContain(label);
        inertia.page.props.can.runDockerActions = false;
        if (_name === 'volumes') await wrapper.setProps({ hosts: [{ id: 1, name: 'Local', canSync: false }] });
        if (_name === 'stacks') await wrapper.setProps({ stacks: [{ ...props.stacks[0], canBackup: false }] });
        await nextTick();
        expect(wrapper.text()).not.toContain(label);
        expect(inertia.post).not.toHaveBeenCalled();
    });
});
