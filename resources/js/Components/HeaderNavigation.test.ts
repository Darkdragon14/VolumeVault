import { mount } from '@vue/test-utils';
import { defineComponent, nextTick, reactive, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AppLayout from '@/Layouts/AppLayout.vue';
import EnvironmentPicker from './EnvironmentPicker.vue';
import HeaderDropdown from './HeaderDropdown.vue';
import { useEnvironmentForms } from '@/Composables/useEnvironmentForms';

const inertia = vi.hoisted(() => ({ page: null as any, patch: vi.fn(), toggleTheme: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href" @click.prevent><slot /></a>' },
    usePage: () => inertia.page,
    router: { patch: inertia.patch, visit: vi.fn() },
}));
vi.mock('@/theme', () => ({ useTheme: () => ({ isDark: ref(false), toggleTheme: inertia.toggleTheme }) }));
vi.mock('@/i18n', () => ({ useI18n: () => ({
    t: (key: string, values?: Record<string, unknown>) => key + (values?.count !== undefined ? ` ${values.count}` : ''),
    locale: ref('en'), locales: ['en'], languageNames: { en: 'English' },
}) }));
const hosts = [{ id: 1, name: 'Local', status: 'local', is_local: true }, { id: 2, name: 'Remote host with a long name', status: 'offline', is_local: false }];
const wrappers: ReturnType<typeof mount>[] = [];
function render(component: any, props = {}, options = {}) {
    const wrapper = mount(component, { props, attachTo: document.body, ...options });
    wrappers.push(wrapper);
    return wrapper;
}
let submit: ReturnType<typeof vi.spyOn>;
beforeEach(() => {
    vi.clearAllMocks();
    submit = vi.spyOn(HTMLFormElement.prototype, 'requestSubmit').mockImplementation(() => {});
    window.matchMedia = vi.fn().mockReturnValue({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() });
    inertia.page = reactive({ url: '/volumes?docker_host_id=1', props: {
        csrfToken: 'fresh-token', auth: { user: { id: 1, name: 'Alice', email: 'alice@example.test', role: 'admin' } },
        can: { manageSensitiveData: true, manageUsers: true }, activeAlertCount: 3,
        environment: { hosts, docker_host_id: 1 },
    } });
});
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); vi.restoreAllMocks(); });

describe('header environment and navigation', () => {
    it('groups desktop navigation beside the bell while preserving mobile auto spacing', () => {
        const header = render(AppLayout, { title: 'Test' }).get('header');
        const navigation = header.get('nav');
        expect(navigation.classes()).toEqual(expect.arrayContaining(['ml-auto', 'justify-end', 'xl:flex']));
        expect(navigation.classes()).not.toContain('flex-1');
        expect(navigation.classes()).not.toContain('justify-center');
        const tools = header.get('a[href="/alerts"]').element.parentElement!;
        expect(navigation.element.nextElementSibling).toBe(tools);
        expect(tools.classList.contains('ml-auto')).toBe(true);
        expect(tools.classList.contains('xl:ml-4')).toBe(true);
        expect(navigation.element.parentElement!.classList.contains('gap-2')).toBe(true);
    });

    it('keeps the responsive logo, environment, backups, bell and compact account', async () => {
        const wrapper = render(AppLayout, { title: 'Test' }, { slots: { default: '<form id="business-form"><input></form>' } });
        const header = wrapper.get('header');
        expect(header.get('a[aria-label="VolumeVault"] img').attributes('src')).toBe('/logo.png');
        expect(header.findAllComponents(EnvironmentPicker)).toHaveLength(1);
        const nativeForm = header.get('form').element;
        expect(nativeForm.parentElement?.closest('form')).toBeNull();
        expect(wrapper.get('#business-form').find('form').exists()).toBe(false);
        expect(header.get('a[href="/alerts"]').attributes('aria-label')).toBe('environment.alerts 3');
        expect(header.get('button[aria-label="Account"]').text()).toBe('A');
        expect(header.get('nav').classes()).toContain('xl:flex');
        expect(header.get('button[aria-controls="mobile-navigation"]').classes()).toContain('xl:hidden');
        await header.get('button[aria-label="environment.backups"]').trigger('click');
        expect(wrapper.get('[role="menu"]').findAll('a').map(link => link.attributes('href'))).toEqual(['/backup-jobs', '/backup-groups']);
        await header.get('button[aria-controls="mobile-navigation"]').trigger('click');
        expect(wrapper.get('#mobile-navigation').find('a[href="/backup-groups"]').exists()).toBe(true);
        expect(wrapper.get('#mobile-navigation').find('a[href="/docker-hosts"]').exists()).toBe(true);
        expect(wrapper.get('main').attributes('inert')).toBeUndefined();
    });

    it('supports selected focus, arrows, Home, End, Escape, tab-away and outside clicks', async () => {
        const wrapper = render(EnvironmentPicker);
        const trigger = wrapper.get('button');
        await trigger.trigger('keydown', { key: 'ArrowDown' });
        const options = wrapper.findAll('[role="menuitemradio"]');
        expect(document.activeElement).toBe(options[1].element);
        expect(options[1].attributes('aria-checked')).toBe('true');
        expect(options[1].text()).toContain('environment.local');
        expect(options[2].text()).toContain('dockerHosts.status.offline');
        await options[1].trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(options[2].element);
        await options[2].trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(options[0].element);
        await options[0].trigger('keydown', { key: 'End' });
        expect(document.activeElement).toBe(options[2].element);
        await options[2].trigger('keydown', { key: 'Home' });
        expect(document.activeElement).toBe(options[0].element);
        await options[0].trigger('keydown', { key: 'ArrowUp' });
        expect(document.activeElement).toBe(options[2].element);
        await options[2].trigger('keydown', { key: 'Escape' });
        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        expect(document.activeElement).toBe(trigger.element);
        await trigger.trigger('click');
        document.body.click();
        await nextTick();
        expect(trigger.attributes('aria-expanded')).toBe('false');
        await trigger.trigger('click');
        await wrapper.get('[role="menuitemradio"]').trigger('focusout', { relatedTarget: document.body });
        expect(trigger.attributes('aria-expanded')).toBe('false');
    });

    it('uses native button activation and focuses navigation links', async () => {
        const wrapper = render(HeaderDropdown, { label: 'Backups' }, { slots: { default: '<a href="/backup-jobs" role="menuitem" @click.prevent>Jobs</a><a href="/backup-groups" role="menuitem" @click.prevent>Groups</a>' } });
        await wrapper.get('button').trigger('keydown', { key: 'ArrowUp' });
        expect(document.activeElement).toBe(wrapper.get('a[href="/backup-groups"]').element);
        await wrapper.get('a[href="/backup-groups"]').trigger('click');
        expect(wrapper.get('button').attributes('aria-expanded')).toBe('false');
        expect(wrapper.get('button').attributes('type')).toBe('button');
    });

    it.each([null, 1, 2])('submits only CSRF and host %s using a native POST, even for the displayed host', async (host) => {
        const wrapper = render(EnvironmentPicker);
        inertia.page.props.csrfToken = 'rotated-current-token';
        await wrapper.get('button').trigger('click');
        await wrapper.findAll('[role="menuitemradio"]')[host ?? 0].trigger('click');
        expect(submit).toHaveBeenCalledOnce();
        const form = wrapper.get('form').element as HTMLFormElement;
        expect(form.method).toBe('post');
        expect(form.getAttribute('action')).toBe('/user/environment');
        expect(Object.fromEntries(new FormData(form))).toEqual({ _token: 'rotated-current-token', docker_host_id: host === null ? '' : String(host) });
        expect(inertia.patch).not.toHaveBeenCalled();
    });

    it('confirms only dirty forms, cancellation preserves draft, and processing blocks selection', async () => {
        const form = reactive({ isDirty: true, processing: false, draft: 'unchanged draft' });
        render(defineComponent({ setup() { useEnvironmentForms(form); }, template: '<div />' }));
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const wrapper = render(EnvironmentPicker);
        await wrapper.get('button').trigger('click');
        await wrapper.findAll('[role="menuitemradio"]')[2].trigger('click');
        expect(confirm).toHaveBeenCalledWith('environment.confirmDiscard');
        expect(submit).not.toHaveBeenCalled();
        expect(form.draft).toBe('unchanged draft');
        expect(wrapper.get('button').text()).toContain('Local');
        form.processing = true;
        await nextTick();
        expect(wrapper.get('button').attributes('disabled')).toBeDefined();
        await wrapper.findAll('[role="menuitemradio"]')[2].trigger('click');
        expect(confirm).toHaveBeenCalledOnce();
        expect(submit).not.toHaveBeenCalled();
        form.processing = false;
        confirm.mockReturnValue(true);
        await wrapper.findAll('[role="menuitemradio"]')[2].trigger('click');
        expect(submit).toHaveBeenCalledOnce();
    });

    it('translates every header label and discard warning in all nine locales', () => {
        const locales = import.meta.glob('../i18n/locales/*.json', { eager: true, import: 'default' }) as Record<string, Record<string, string>>;
        expect(Object.keys(locales)).toHaveLength(9);
        for (const messages of Object.values(locales)) {
            for (const key of ['environment.label', 'environment.local', 'environment.backups', 'environment.alerts', 'environment.confirmDiscard']) expect(messages[key]).toBeTruthy();
            expect(messages).not.toHaveProperty('environment.recovery');
        }
    });
});
