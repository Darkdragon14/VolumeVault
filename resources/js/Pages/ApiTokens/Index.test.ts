import { mount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import ApiTokens from './Index.vue';

const inertia = vi.hoisted(() => ({ page: null as any }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    usePage: () => inertia.page,
    useForm: (data: any) => reactive({ ...data, errors: {}, processing: false, post: vi.fn() }),
    router: { delete: vi.fn() },
}));
vi.mock('@/i18n', () => ({ useI18n: () => ({ t: (key: string) => key, formatDate: (value: unknown) => value ?? '—' }) }));

describe('API token creation', () => {
    it('shows the new token after a preserved-state redirect and hides it on the next visit', async () => {
        inertia.page = reactive({ props: { flash: { api_token: null } } });
        const wrapper = mount(ApiTokens, {
            props: { users: [], tokens: { data: [], meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 } } },
            global: { stubs: { AppLayout: { template: '<main><slot /></main>' }, Pagination: true } },
        });

        expect(wrapper.text()).not.toContain('Copy this token now.');

        inertia.page.props.flash = { api_token: '42|new-secret-token' };
        await nextTick();
        expect(wrapper.text()).toContain('42|new-secret-token');

        inertia.page.props.flash = { api_token: null };
        await nextTick();
        expect(wrapper.text()).not.toContain('42|new-secret-token');

        wrapper.unmount();
    });
});
