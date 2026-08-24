import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import BackupRunShow from './Show.vue';

const inertia = vi.hoisted(() => ({
    usePoll: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({
        props: {
            can: { runDockerActions: true },
        },
    }),
    usePoll: inertia.usePoll,
}));

vi.mock('@/i18n', () => ({
    useI18n: () => ({
        t: (key: string) => key,
        formatDate: (value: string | null) => value ?? '-',
    }),
}));

vi.mock('@/Composables/useFormatBytes', () => ({
    formatBytes: (value: number | null, fallback = '-') => value === null ? fallback : `${value} B`,
}));

const queuedRun = {
    id: 42,
    status: 'queued',
    trigger: 'manual',
    initiated_by: null,
    duration_seconds: null,
    started_at: null,
    finished_at: null,
    backup_size_bytes: null,
    backup_key: null,
    docker_container_id: null,
    error_message: null,
    logs: null,
    job: {
        id: 7,
        name: 'Documents',
    },
};

function mountPage(run = queuedRun) {
    return mount(BackupRunShow, {
        props: { run },
        global: {
            stubs: {
                AppLayout: {
                    props: ['title', 'subtitle'],
                    template: '<main><slot name="actions" /><slot /></main>',
                },
                StatusBadge: {
                    props: ['status'],
                    template: '<span>{{ status }}</span>',
                },
            },
        },
    });
}

describe('Backup run detail', () => {
    beforeEach(() => {
        inertia.usePoll.mockClear();
    });

    it('configures polling for only the run prop in rest mode', () => {
        mountPage();

        expect(inertia.usePoll).toHaveBeenCalledWith(2000, { only: ['run'] }, { mode: 'rest' });
    });

    it('waits for the active poll request to finish before scheduling another', () => {
        vi.useFakeTimers();
        const reload = vi.spyOn(router, 'reload').mockImplementation(() => undefined);
        const polling = router.poll(2000, { only: ['run'] }, { mode: 'rest' });

        try {
            vi.advanceTimersByTime(2000);
            expect(reload).toHaveBeenCalledTimes(1);

            vi.advanceTimersByTime(10000);
            expect(reload).toHaveBeenCalledTimes(1);

            reload.mock.calls[0][0].onFinish?.(undefined as never);
            vi.advanceTimersByTime(2000);
            expect(reload).toHaveBeenCalledTimes(2);
        } finally {
            polling.stop();
            reload.mockRestore();
            vi.useRealTimers();
        }
    });

    it('renders refreshed run details and a reactive restore link', async () => {
        const wrapper = mountPage();

        await wrapper.setProps({
            run: {
                ...queuedRun,
                status: 'success',
                duration_seconds: 12,
                finished_at: '2026-08-24T12:00:00Z',
                backup_size_bytes: 1024,
                backup_key: 'daily/documents #42.tar.gz',
                docker_container_id: 'volumevault-backup-42',
                logs: 'Backup completed.',
            },
        });

        expect(wrapper.text()).toContain('success');
        expect(wrapper.text()).toContain('Backup completed.');
        expect(wrapper.text()).toContain('1024 B');

        const restoreLink = wrapper.findAll('a').find((link) => link.text() === 'Restore this backup');

        expect(restoreLink?.attributes('href')).toBe('/backup-jobs/7/restore?backup=daily%2Fdocuments%20%2342.tar.gz');
    });
});
