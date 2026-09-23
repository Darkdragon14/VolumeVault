import { mount } from '@vue/test-utils';
import { beforeEach, expect, it, vi } from 'vitest';
import Show from './Show.vue';

const polling = vi.hoisted(() => ({ start: vi.fn(), stop: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' }, usePoll: () => polling }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<main><slot /></main>' } }));
vi.mock('@/i18n', () => ({ useI18n: () => ({ t: (key: string) => key, formatDate: (date: string) => date }) }));

beforeEach(() => vi.clearAllMocks());

it('refreshes relay progress through failure and acknowledged cleanup without exposing spool metadata', async () => {
    const relay = { source_docker_host_id: 2, target_docker_host_id: 3, status: 'exporting',
        source_docker_host: { id: 2, name: 'Archive owner A' }, target_docker_host: { id: 3, name: 'Restore target B' },
        uploaded_bytes: 1048576, downloaded_bytes: 0, size_bytes: 2097152, expires_at: '2026-09-23T10:00:00Z',
        cleaned_at: null, spool_path: '/private/do-not-render' };
    const run = { id: 9, job: { id: 7 }, status: 'queued', archive_relay: relay };
    const wrapper = mount(Show, { props: { run }, global: { stubs: { StatusBadge: true } } });
    expect(polling.start).toHaveBeenCalled();
    expect(wrapper.text()).toContain('archiveRelay.exporting');
    expect(wrapper.text()).toContain('1.0 MB / 2.0 MB');
    expect(wrapper.text()).toContain('0 B / 2.0 MB');
    expect(wrapper.text()).toContain('(#2)');
    expect(wrapper.text()).toContain('(#3)');
    expect(wrapper.text()).toContain('Archive owner A (#2)');
    expect(wrapper.text()).toContain('Restore target B (#3)');
    expect(wrapper.text()).toContain(relay.expires_at);
    expect(wrapper.text()).not.toContain(relay.spool_path);
    await wrapper.setProps({ run: { ...run, archive_relay: { ...relay, status: 'downloading', uploaded_bytes: 2097152, downloaded_bytes: 1048576 } } });
    expect(wrapper.text()).toContain('archiveRelay.downloading');
    expect(wrapper.text()).toContain('2.0 MB / 2.0 MB');
    expect(wrapper.text()).toContain('1.0 MB / 2.0 MB');
    await wrapper.setProps({ run: { ...run, status: 'failed', archive_relay: { ...relay, status: 'failed', error_message: 'Transfer expired' } } });
    expect(wrapper.get('[role="alert"]').text()).toBe('Transfer expired');
    expect(wrapper.text()).toContain('archiveRelay.retained');
    expect(polling.stop).not.toHaveBeenCalled();
    const cleaned = { ...run, status: 'failed', archive_relay: { ...relay, status: 'failed', cleaned_at: '2026-09-23T10:01:00Z' } };
    await wrapper.setProps({ run: { ...cleaned, docker_container_cleanup_pending: true, stopped_container_ids: ['container-a'] } });
    expect(wrapper.text()).toContain('archiveRelay.cleaned');
    expect(polling.stop).not.toHaveBeenCalled();
    await wrapper.setProps({ run: { ...cleaned, docker_container_cleanup_pending: false, stopped_container_ids: ['container-a'] } });
    expect(polling.stop).not.toHaveBeenCalled();
    await wrapper.setProps({ run: { ...cleaned, docker_container_cleanup_pending: false, stopped_container_ids: [] } });
    expect(polling.stop).toHaveBeenCalled();
    wrapper.unmount();
});

it.each(['failed', 'success', 'cancelled'])('polls terminal %s non-relay runs until helper cleanup and container recovery both clear', async (status) => {
    const run = { id: 9, job: { id: 7 }, status, archive_relay: null,
        docker_container_cleanup_pending: true, stopped_container_ids: ['container-a'] };
    const wrapper = mount(Show, { props: { run }, global: { stubs: { StatusBadge: true } } });
    expect(polling.start).toHaveBeenCalledTimes(1);
    expect(polling.stop).not.toHaveBeenCalled();
    await wrapper.setProps({ run: { ...run, docker_container_cleanup_pending: false } });
    expect(polling.stop).not.toHaveBeenCalled();
    await wrapper.setProps({ run: { ...run, stopped_container_ids: [] } });
    expect(polling.stop).not.toHaveBeenCalled();
    const cleared = { ...run, docker_container_cleanup_pending: false, stopped_container_ids: [] };
    await wrapper.setProps({ run: cleared });
    expect(polling.stop).toHaveBeenCalledTimes(1);
    await wrapper.setProps({ run: { ...cleared, status: 'queued' } });
    expect(polling.start).toHaveBeenCalledTimes(2);
    await wrapper.setProps({ run: { ...cleared, status: 'running' } });
    expect(polling.stop).toHaveBeenCalledTimes(1);
    await wrapper.setProps({ run: cleared });
    expect(polling.stop).toHaveBeenCalledTimes(2);
    await wrapper.setProps({ run: { ...cleared, docker_container_cleanup_pending: true } });
    expect(polling.start).toHaveBeenCalledTimes(3);
    await wrapper.setProps({ run: cleared });
    await wrapper.setProps({ run: { ...cleared, stopped_container_ids: ['container-b'] } });
    expect(polling.start).toHaveBeenCalledTimes(4);
    await wrapper.setProps({ run: cleared });
    expect(polling.stop).toHaveBeenCalledTimes(4);
    wrapper.unmount();
});
