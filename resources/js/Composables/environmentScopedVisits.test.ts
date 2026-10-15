import { mount } from '@vue/test-utils';
import { defineComponent, ref } from 'vue';
import { expect, it, vi } from 'vitest';
import { useUrlFilters } from './useListFilters';

const inertia = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: { replace: inertia.replace } }));

it('keeps normal URL filter debounce independent and cancels it on unmount', async () => {
    vi.useFakeTimers();
    window.history.replaceState({}, '', '/volumes?docker_host_id=2&page=3');
    const wrapper = mount(defineComponent({ setup() {
        const search = ref('');
        useUrlFilters({ search }, { debounce: 300 });
        return { search };
    }, template: '<input v-model="search">' }));
    try {
        await wrapper.get('input').setValue('data');
        await vi.advanceTimersByTimeAsync(299);
        expect(inertia.replace).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(inertia.replace).toHaveBeenCalledWith({ url: '/volumes?docker_host_id=2&page=3&search=data', preserveState: true, preserveScroll: true });
        await wrapper.get('input').setValue('cancelled');
        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(300);
        expect(inertia.replace).toHaveBeenCalledOnce();
    } finally { vi.useRealTimers(); }
});
