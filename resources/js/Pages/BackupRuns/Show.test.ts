import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import BackupRunShow from './Show.vue';

const inertia = vi.hoisted(() => ({
    usePoll: vi.fn(),
    deployment: undefined as any,
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
            deployment: inertia.deployment,
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
    backup_key: null as string | null,
    restore_unverifiable: false,
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
    const unverifiableMessage = 'This Dropbox backup completed successfully, but no stable file ID was recorded. Its identity cannot be verified, so restoring this run is unavailable.';

    it.each([null, '/backups/documents.tar.gz'])('explains an unverifiable successful Dropbox run with key %s', (backupKey) => {
        const wrapper = mountPage({ ...queuedRun, status: 'success', backup_key: backupKey, restore_unverifiable: true });

        expect(wrapper.get('[role="status"]').text()).toBe(unverifiableMessage);
        expect(wrapper.text()).not.toContain('Restore this backup');
        expect(wrapper.text()).toContain('success');
    });

    it.each(['id:stable-file', 'daily/documents.tar.gz', null])('keeps unaffected runs unchanged with key %s', (backupKey) => {
        const wrapper = mountPage({ ...queuedRun, status: 'success', backup_key: backupKey });

        expect(wrapper.text()).not.toContain(unverifiableMessage);
        const link = wrapper.findAll('a').find((link) => link.text() === 'Restore this backup');
        expect(Boolean(link)).toBe(Boolean(backupKey));
        if (backupKey) {
            expect(link?.attributes('href')).toBe(`/backup-jobs/7/restore?backup=${encodeURIComponent(backupKey)}&backup_run_id=42`);
        }
    });

    beforeEach(() => {
        inertia.usePoll.mockClear();
        inertia.deployment = undefined;
    });

    it.each([false, 0, '0', 'false'])('keeps history visible but hides local restore when execution is %s', (flag) => {
        inertia.deployment = { mode: 'orchestrator', local_execution_enabled: flag };
        const wrapper = mountPage({ ...queuedRun, status: 'success', backup_key: 'daily/backup.tar.gz' });
        expect(wrapper.text()).not.toContain('Restore this backup');
        expect(wrapper.text()).toContain('Back to job');
        expect(wrapper.text()).toContain('success');
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

        expect(restoreLink?.attributes('href')).toBe('/backup-jobs/7/restore?backup=daily%2Fdocuments%20%2342.tar.gz&backup_run_id=42');
    });
});
