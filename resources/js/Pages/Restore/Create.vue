<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { destinationMatchesHost, hostId, isHostLocalDestination, useDeployment, type ExecutionHost } from '@/Composables/useDeployment';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from '@/i18n';
import { formatBytes } from '@/Composables/useFormatBytes';

const { localExecutionEnabled, executionHosts, canExecute, canManageBackups } = useDeployment();

const props = defineProps<{
    job: any;
    hosts?: ExecutionHost[];
    volumes?: { docker_host_id?: number; name: string }[];
    sourceDockerHostId?: number;
    targetDockerHostId?: number;
    restoreDestination: any;
    backups: any[];
    hasOtherBackups?: boolean;
    preselectedBackupKey?: string | null;
    backupRunId?: number | null;
    backupRunUnverifiable?: boolean;
    isDockerVolumeSource: boolean;
    sourceVolumeName?: string | null;
    sourceLabel: string;
    listError?: string | null;
    generatedTargetVolumeName: string | null;
}>();

const step = ref(1);
const { t, formatDate, timezone } = useI18n();

// The displayed date uses formatDate() in the app/user timezone, so the date
// filter must derive the backup's calendar date in that SAME timezone — comparing
// the raw UTC ISO prefix would mis-bucket backups near midnight. 'en-CA' yields
// YYYY-MM-DD, matching the <input type="date"> value.
const localDateKey = (value?: string | null): string => value
    ? new Intl.DateTimeFormat('en-CA', { timeZone: timezone.value, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(value))
    : '';

const isDockerVolumeSource = computed(() => props.isDockerVolumeSource);
const sourceVolumeName = computed(() => props.sourceVolumeName ?? '');

const form = useForm({
    target_docker_host_id: props.targetDockerHostId ?? hostId(props.job),
    backup_run_id: props.backupRunId ?? null,
    selected_backup_key: '',
    mode: 'new_volume',
    target_volume_name: props.generatedTargetVolumeName ?? '',
    backup_before_overwrite: false,
    confirmation_text: '',
});

const hosts = computed(() => executionHosts(props.hosts));
const sourceHostId = computed(() => props.sourceDockerHostId ?? hostId(props.job));
const targetHost = computed(() => hosts.value.find((host) => Number(host.id) === Number(form.target_docker_host_id)));
const sameHost = computed(() => Number(form.target_docker_host_id) === Number(sourceHostId.value));
const targetAvailable = (host: ExecutionHost) => canExecute(host, 'restore-v1') && destinationMatchesHost(props.restoreDestination, host.id);
const workflowVisible = computed(() => canManageBackups.value && (localExecutionEnabled.value || hosts.value.length > 0));
const targetExists = computed(() => !isInPlace.value && (props.volumes ?? []).some((volume) => hostId(volume) === Number(form.target_docker_host_id) && volume.name === form.target_volume_name));
const targetValid = computed(() => !!targetHost.value && targetAvailable(targetHost.value) && !targetExists.value
    && !!form.target_volume_name.trim() && (!isInPlace.value || sameHost.value));
watch(() => form.target_docker_host_id, () => {
    form.mode = 'new_volume';
    form.confirmation_text = '';
    form.backup_before_overwrite = false;
});

// --- Restore modes (data-driven so adding/auditing a mode is a one-liner) ---
const modes = computed(() => {
    const list = [
        {
            value: 'new_volume',
            label: t('Restore to new volume'),
            description: t('Recommended. Never overwrites the original volume.'),
            destructive: false,
            requiresConfirmation: false,
        },
    ];

    // In-place modes overwrite the source volume itself, so they only apply to
    // Docker volume sources (host path jobs keep restore-to-new-volume only).
    if (sourceContextReady.value && isDockerVolumeSource.value && sameHost.value) {
        list.push(
            {
                value: 'inplace',
                label: t('Restore in place'),
                description: t('Overwrites the source volume. Requires typed confirmation.'),
                destructive: true,
                requiresConfirmation: true,
            },
            {
                value: 'safe_inplace',
                label: t('Safe in-place restore'),
                description: t('Stops affected containers, overwrites the source volume, then restarts them.'),
                destructive: true,
                requiresConfirmation: true,
            },
        );
    }

    return list;
});

const selectedMode = computed(() => modes.value.find((mode) => mode.value === form.mode));
const requiresConfirmation = computed(() => !!selectedMode.value?.requiresConfirmation);
const isInPlace = computed(() => form.mode === 'inplace' || form.mode === 'safe_inplace');
const dropboxSafetyBackupUnavailable = computed(() => isInPlace.value && props.job.destination?.provider === 'dropbox');
const safetyBackupBlocked = computed(() => dropboxSafetyBackupUnavailable.value && form.backup_before_overwrite);
const confirmationMatches = computed(() => !requiresConfirmation.value || form.confirmation_text === sourceVolumeName.value);

// Keep the target volume in sync with the mode: in-place modes write back into
// the source volume; restore-to-new-volume uses the generated/custom name.
watch(
    () => form.mode,
    (mode) => {
        if (mode === 'inplace' || mode === 'safe_inplace') {
            form.target_volume_name = sourceVolumeName.value;
        } else {
            form.target_volume_name = props.generatedTargetVolumeName ?? '';
            form.confirmation_text = '';
            form.backup_before_overwrite = false;
        }
    },
);

const confirmWarning = computed(() => {
    if (form.mode === 'inplace') {
        return t('This permanently overwrites the contents of volume "{name}" with the selected backup. This cannot be undone.', { name: sourceVolumeName.value });
    }
    if (form.mode === 'safe_inplace') {
        return t('Containers using volume "{name}" are stopped, the volume is overwritten with the selected backup, then the containers are restarted. This cannot be undone.', { name: sourceVolumeName.value });
    }
    return t('Restore can take time. The default mode creates a new Docker volume and does not overwrite the source volume.');
});

// --- Backup selection step ---
const search = ref('');
const dateFilter = ref('');
const showAll = ref(false);

const scopedBackups = computed(() => {
    if (props.backupRunUnverifiable) {
        return [];
    }

    if (props.backupRunId && props.preselectedBackupKey) {
        return props.backups.filter((backup) => backup.key === props.preselectedBackupKey);
    }

    if (showAll.value || !props.hasOtherBackups) {
        return props.backups;
    }

    return props.backups.filter((backup) => backup.belongs_to_job);
});

const visibleBackups = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return scopedBackups.value.filter((backup) => {
        const name = String(backup.display_name || backup.key || '').toLowerCase();
        const matchesName = !needle || name.includes(needle);
        const matchesDate = !dateFilter.value || localDateKey(backup.last_modified) === dateFilter.value;

        return matchesName && matchesDate;
    });
});

// Backups arrive newest-first; flag the most recent of the full scoped list so
// the badge is stable regardless of the active filters.
const latestKey = computed(() => scopedBackups.value[0]?.key ?? null);

const selectedBackup = computed(() => props.backups.find((backup) => backup.key === form.selected_backup_key));
const sourceContextReady = computed(() => form.backup_run_id === (props.backupRunId ?? null));
const loadingContext = ref(false);
watch(selectedBackup, (backup) => {
    form.backup_run_id = backup?.backup_run_id ?? (backup?.key === props.preselectedBackupKey ? props.backupRunId ?? null : null);
    form.mode = 'new_volume';
    form.confirmation_text = '';
    form.backup_before_overwrite = false;
    step.value = 1;
}, { flush: 'sync' });

const continueSelection = () => {
    if (!selectedBackup.value || props.backupRunUnverifiable || loadingContext.value) return;
    if (sourceContextReady.value) {
        step.value = 2;
        return;
    }

    loadingContext.value = true;
    router.get(`/backup-jobs/${props.job.id}/restore`, { backup_run_id: form.backup_run_id }, {
        preserveState: 'errors',
        onError: (errors) => { form.errors = errors; step.value = 1; },
        onFinish: () => { loadingContext.value = false; },
    });
};
const submit = () => {
    if (!sourceContextReady.value || loadingContext.value || !targetValid.value || !confirmationMatches.value || !selectedBackup.value || props.backupRunUnverifiable || safetyBackupBlocked.value) {
        return;
    }

    form.post(`/backup-jobs/${props.job.id}/restore`, {
        onError: (errors) => {
            if (errors.selected_backup_key || errors.backup_run_id) {
                step.value = 1;
            } else if (errors.backup_before_overwrite || errors.target_docker_host_id || errors.target_volume_name || errors.mode) {
                step.value = 2;
            }
        },
    });
};

// Preselect a specific archive (e.g. from a "Restore this backup" link), even
// when it belongs to another job — reveal all backups so it stays visible.
if (props.preselectedBackupKey && !props.backupRunUnverifiable) {
    const match = props.backups.find((backup) => backup.key === props.preselectedBackupKey);
    if (match) {
        form.selected_backup_key = match.key;
        if (!match.belongs_to_job) {
            showAll.value = true;
        }
    }
}
</script>

<template>
    <AppLayout :title="t('Restore {name}', { name: job.name })" :subtitle="t('Choose a backup archive and restore it into a new Docker volume.')">
        <Head :title="t('Restore {name}', { name: job.name })" />
        <template #actions>
            <Link :href="`/backup-jobs/${job.id}`" class="btn-secondary">{{ t('Back to job') }}</Link>
        </template>

        <p v-if="!workflowVisible" role="status" class="card p-4 text-sm text-slate-400">{{ t(localExecutionEnabled ? 'hostWorkflow.unavailable' : 'dockerHosts.localDisabled') }}</p>
        <div v-if="workflowVisible" class="mb-6 grid gap-3 sm:grid-cols-2 md:grid-cols-4">
            <div v-for="number in [1, 2, 3, 4]" :key="number" class="rounded-xl border px-4 py-3 text-sm" :class="step >= number ? 'border-sky-300/40 bg-sky-300/10 text-sky-100' : 'border-white/10 bg-white/5 text-slate-400'">
                {{ t('Step {number}', { number }) }}
            </div>
        </div>

        <section v-if="workflowVisible && step === 1" class="card p-4 sm:p-6">
            <h2 class="text-xl font-semibold">{{ t('Select backup') }}</h2>
            <p class="mt-1 text-sm text-slate-400">{{ t('Backups are listed newest first from {name}.', { name: restoreDestination?.name }) }}</p>
            <p v-if="listError" class="mt-4 rounded-xl bg-rose-400/10 p-3 text-sm text-rose-100">{{ listError }}</p>
            <p v-if="backupRunUnverifiable" role="alert" class="mt-4 rounded-xl bg-amber-300/10 p-3 text-sm text-amber-100">{{ t('This historical Dropbox backup has no stable file ID. Its identity cannot be verified, so restoring this run is unavailable.') }}</p>
            <p v-if="form.errors.selected_backup_key" role="alert" class="mt-4 text-sm text-rose-300">{{ form.errors.selected_backup_key }}</p>
            <p v-if="form.errors.backup_run_id" role="alert" class="mt-4 text-sm text-rose-300">{{ form.errors.backup_run_id }}</p>
            <p v-if="form.errors.destination" role="alert" class="mt-4 text-sm text-rose-300">{{ form.errors.destination }}</p>

            <div v-if="backups.length && !backupRunUnverifiable" class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end">
                <label class="block flex-1 space-y-1">
                    <span class="label">{{ t('Filter by name') }}</span>
                    <input v-model="search" type="search" class="input" :placeholder="t('Search backups')">
                </label>
                <label class="block space-y-1">
                    <span class="label">{{ t('Filter by date') }}</span>
                    <input v-model="dateFilter" type="date" class="input">
                </label>
                <button v-if="dateFilter || search" type="button" class="btn-secondary" @click="search = ''; dateFilter = ''">{{ t('Clear filters') }}</button>
            </div>

            <label v-if="hasOtherBackups && !backupRunId" class="mt-4 flex cursor-pointer items-center gap-2 text-sm text-slate-300">
                <input v-model="showAll" type="checkbox" class="text-sky-400">
                <span>{{ t('Show all backups in this destination') }}</span>
            </label>

            <div v-if="visibleBackups.length" class="mt-5 space-y-3">
                <label
                    v-for="backup in visibleBackups"
                    :key="backup.key"
                    class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition"
                    :class="form.selected_backup_key === backup.key ? 'border-sky-300/60 bg-sky-300/10' : 'border-white/10 bg-white/5 hover:bg-slate-100 dark:hover:bg-white/10'"
                >
                    <input v-model="form.selected_backup_key" type="radio" :value="backup.key" class="mt-1 text-sky-400">
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="block break-all font-medium text-white">{{ backup.display_name || backup.key }}</span>
                            <span v-if="backup.key === latestKey" class="rounded-full bg-emerald-300/15 px-2 py-0.5 text-xs font-medium text-emerald-200">{{ t('latest') }}</span>
                        </span>
                        <span class="mt-1 block text-xs text-slate-400">{{ formatDate(backup.last_modified) }} / {{ formatBytes(backup.size) }}</span>
                    </span>
                </label>
            </div>
            <p v-else-if="backups.length && !backupRunUnverifiable" class="mt-5 rounded-xl border border-dashed border-white/10 p-5 text-sm text-slate-400">{{ t('No backups match the current filters.') }}</p>
            <p v-else-if="!backupRunUnverifiable" class="mt-5 rounded-xl border border-dashed border-white/10 p-5 text-sm text-slate-400">{{ t('No backup objects found. Run a backup first or check the destination path.') }}</p>

            <button class="btn-primary mt-5" :disabled="loadingContext || backupRunUnverifiable || !form.selected_backup_key" @click="continueSelection">{{ t('Continue') }}</button>
        </section>

        <section v-if="workflowVisible && step === 2" class="card p-4 sm:p-6">
            <h2 class="text-xl font-semibold">{{ t('Select restore mode') }}</h2>
            <label class="mt-4 block space-y-2">
                <span class="label">{{ t('hostWorkflow.targetHost') }}</span>
                <select v-model="form.target_docker_host_id" class="input" data-target-host>
                    <option v-for="host in hosts" :key="host.id" :value="host.id" :disabled="!targetAvailable(host)">{{ host.name }}{{ targetAvailable(host) ? '' : ` — ${t('hostWorkflow.unavailable')}` }}</option>
                </select>
                <span v-if="form.errors.target_docker_host_id" class="text-sm text-rose-300">{{ form.errors.target_docker_host_id }}</span>
            </label>
            <p v-if="!targetHost || !targetAvailable(targetHost)" role="status" class="mt-3 text-sm text-amber-600 dark:text-amber-200">{{ t('hostWorkflow.unavailable') }}</p>
            <p v-if="isHostLocalDestination(restoreDestination)" class="mt-3 text-sm text-slate-400">{{ t('hostWorkflow.relayUnsupported') }}</p>
            <p v-else class="mt-3 text-sm text-slate-400">{{ t('hostWorkflow.sharedRestore') }}</p>
            <div class="mt-5 grid gap-4 lg:grid-cols-3">
                <label
                    v-for="mode in modes"
                    :key="mode.value"
                    class="cursor-pointer rounded-2xl border p-5"
                    :class="form.mode === mode.value ? 'border-sky-300/40 bg-sky-300/10' : 'border-white/10 bg-white/5'"
                >
                    <input v-model="form.mode" type="radio" :value="mode.value" class="text-sky-400">
                    <span class="mt-3 block text-lg font-semibold">{{ mode.label }}</span>
                    <span class="mt-2 block text-sm" :class="form.mode === mode.value ? 'text-slate-300' : 'text-slate-400'">{{ mode.description }}</span>
                </label>
            </div>

            <p v-if="!isDockerVolumeSource" class="mt-4 text-sm text-slate-400">{{ t('In-place restore is only available for Docker volume sources.') }}</p>

            <label v-if="!isInPlace" class="mt-5 block space-y-2">
                <span class="label">{{ t('Target volume name') }}</span>
                <input v-model="form.target_volume_name" class="input">
                <span v-if="targetExists" class="text-sm text-rose-300">{{ t('hostWorkflow.targetExists') }}</span>
                <span v-if="form.errors.target_volume_name" class="text-sm text-rose-300">{{ form.errors.target_volume_name }}</span>
            </label>
            <div v-else class="mt-5 space-y-2">
                <span class="label">{{ t('Target volume') }}</span>
                <p class="break-all rounded-xl border border-white/10 bg-white/5 p-3 text-sm text-white">{{ sourceVolumeName }}</p>
                <span class="text-xs text-amber-200">{{ t('The source volume is overwritten in place.') }}</span>
            </div>

            <label v-if="isInPlace" class="mt-5 flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-white/5 p-4">
                <input v-model="form.backup_before_overwrite" type="checkbox" class="mt-1 text-sky-400">
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium text-white">{{ t('Back up the current volume before overwriting it') }}</span>
                    <span class="mt-1 block text-xs text-slate-400">{{ t('Creates a full backup to {name} before the restore. The restore is aborted if this backup fails.', { name: job.destination?.name }) }}</span>
                </span>
            </label>

            <p v-if="dropboxSafetyBackupUnavailable" role="alert" class="mt-4 rounded-xl bg-amber-300/10 p-3 text-sm text-amber-100">{{ t('Safety backup before overwrite is unavailable because the job’s current destination is Dropbox. A newly uploaded Dropbox backup cannot be verified for restore. Choose a different job destination or explicitly turn off the safety backup.') }}</p>
            <p v-if="form.errors.backup_before_overwrite" role="alert" class="mt-4 text-sm text-rose-300">{{ t(form.errors.backup_before_overwrite) }}</p>

            <div class="mt-5 flex flex-wrap gap-3">
                <button class="btn-secondary" @click="step = 1">{{ t('Back') }}</button>
                <button class="btn-primary" :disabled="safetyBackupBlocked || !targetValid" @click="step = 3">{{ t('Continue') }}</button>
            </div>
        </section>

        <section v-if="workflowVisible && step === 3" class="card p-4 sm:p-6">
            <h2 class="text-xl font-semibold">{{ t('Confirm restore') }}</h2>
            <div
                class="mt-5 rounded-xl border p-4 text-sm"
                :class="selectedMode?.destructive ? 'border-rose-400/40 bg-rose-400/10 text-rose-100' : 'border-amber-300/30 bg-amber-300/10 text-amber-100'"
            >
                {{ confirmWarning }}
            </div>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                <div><dt class="text-xs uppercase text-slate-400">{{ t('hostWorkflow.targetHost') }}</dt><dd class="mt-1 break-words text-white">{{ targetHost?.name }}</dd></div>
                <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Source') }}</dt><dd class="mt-1 break-all text-white">{{ sourceLabel }}</dd></div>
                <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Target volume') }}</dt><dd class="mt-1 break-all text-white">{{ form.target_volume_name }}</dd></div>
                <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Destination') }}</dt><dd class="mt-1 break-words text-white">{{ restoreDestination?.name }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-400">{{ t('Selected backup') }}</dt><dd class="mt-1 break-all text-white">{{ selectedBackup?.display_name || selectedBackup?.key }}</dd></div>
                <div v-if="isInPlace"><dt class="text-xs uppercase text-slate-400">{{ t('Safety backup') }}</dt><dd class="mt-1 text-white">{{ form.backup_before_overwrite ? t('Yes, backed up before overwrite') : t('No') }}</dd></div>
            </dl>

            <label v-if="requiresConfirmation" class="mt-5 block space-y-2">
                <span class="label">{{ t('Type "{name}" to confirm', { name: sourceVolumeName }) }}</span>
                <input v-model="form.confirmation_text" class="input" autocomplete="off" :placeholder="sourceVolumeName">
                <span v-if="form.errors.confirmation_text" class="text-sm text-rose-300">{{ form.errors.confirmation_text }}</span>
            </label>

            <div class="mt-5 flex flex-wrap gap-3">
                <button class="btn-secondary" @click="step = 2">{{ t('Back') }}</button>
                <button class="btn-primary" :disabled="form.processing || !confirmationMatches || safetyBackupBlocked || !targetValid" @click="submit">{{ t('Queue restore') }}</button>
            </div>
        </section>

        <section v-if="workflowVisible && step === 4" class="card p-4 sm:p-6">
            <h2 class="text-xl font-semibold">{{ t('Result') }}</h2>
            <p class="mt-2 text-sm text-slate-400">{{ t('The restore run will appear in the restore run detail after submission.') }}</p>
        </section>
    </AppLayout>
</template>
