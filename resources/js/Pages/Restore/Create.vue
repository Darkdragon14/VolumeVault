<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from '@/i18n';
import { formatBytes } from '@/Composables/useFormatBytes';

const props = defineProps<{
    job: any;
    backups: any[];
    hasOtherBackups?: boolean;
    preselectedBackupKey?: string | null;
    listError?: string | null;
    generatedTargetVolumeName: string;
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

const isDockerVolumeSource = computed(() => !!props.job.is_docker_volume_source);
const sourceVolumeName = computed(() => props.job.volume_name as string);

const form = useForm({
    selected_backup_key: '',
    mode: 'new_volume',
    target_volume_name: props.generatedTargetVolumeName,
    backup_before_overwrite: false,
    confirmation_text: '',
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
    if (isDockerVolumeSource.value) {
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
const confirmationMatches = computed(() => !requiresConfirmation.value || form.confirmation_text === sourceVolumeName.value);

// Keep the target volume in sync with the mode: in-place modes write back into
// the source volume; restore-to-new-volume uses the generated/custom name.
watch(
    () => form.mode,
    (mode) => {
        if (mode === 'inplace' || mode === 'safe_inplace') {
            form.target_volume_name = sourceVolumeName.value;
        } else {
            form.target_volume_name = props.generatedTargetVolumeName;
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
const sourceLabel = (job: any) => job.source_label || job.host_path || job.volume_name || t('Unknown');
const submit = () => form.post(`/backup-jobs/${props.job.id}/restore`);

// Preselect a specific archive (e.g. from a "Restore this backup" link), even
// when it belongs to another job — reveal all backups so it stays visible.
if (props.preselectedBackupKey) {
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
    <Head :title="t('Restore {name}', { name: job.name })" />
    <AppLayout :title="t('Restore {name}', { name: job.name })" :subtitle="t('Choose a backup archive and restore it into a new Docker volume.')">
        <template #actions>
            <Link :href="`/backup-jobs/${job.id}`" class="btn-secondary">{{ t('Back to job') }}</Link>
        </template>

        <div class="mb-6 grid gap-3 sm:grid-cols-2 md:grid-cols-4">
            <div v-for="number in [1, 2, 3, 4]" :key="number" class="rounded-xl border px-4 py-3 text-sm" :class="step >= number ? 'border-sky-300/40 bg-sky-300/10 text-sky-100' : 'border-white/10 bg-white/5 text-slate-400'">
                {{ t('Step {number}', { number }) }}
            </div>
        </div>

        <section v-if="step === 1" class="card p-4 sm:p-6">
            <h2 class="text-xl font-semibold">{{ t('Select backup') }}</h2>
            <p class="mt-1 text-sm text-slate-400">{{ t('Backups are listed newest first from {name}.', { name: job.destination?.name }) }}</p>
            <p v-if="listError" class="mt-4 rounded-xl bg-rose-400/10 p-3 text-sm text-rose-100">{{ listError }}</p>

            <div v-if="backups.length" class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end">
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

            <label v-if="hasOtherBackups" class="mt-4 flex cursor-pointer items-center gap-2 text-sm text-slate-300">
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
            <p v-else-if="backups.length" class="mt-5 rounded-xl border border-dashed border-white/10 p-5 text-sm text-slate-400">{{ t('No backups match the current filters.') }}</p>
            <p v-else class="mt-5 rounded-xl border border-dashed border-white/10 p-5 text-sm text-slate-400">{{ t('No backup objects found. Run a backup first or check the destination path.') }}</p>

            <button class="btn-primary mt-5" :disabled="!form.selected_backup_key" @click="step = 2">{{ t('Continue') }}</button>
        </section>

        <section v-if="step === 2" class="card p-4 sm:p-6">
            <h2 class="text-xl font-semibold">{{ t('Select restore mode') }}</h2>
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

            <div class="mt-5 flex flex-wrap gap-3">
                <button class="btn-secondary" @click="step = 1">{{ t('Back') }}</button>
                <button class="btn-primary" @click="step = 3">{{ t('Continue') }}</button>
            </div>
        </section>

        <section v-if="step === 3" class="card p-4 sm:p-6">
            <h2 class="text-xl font-semibold">{{ t('Confirm restore') }}</h2>
            <div
                class="mt-5 rounded-xl border p-4 text-sm"
                :class="selectedMode?.destructive ? 'border-rose-400/40 bg-rose-400/10 text-rose-100' : 'border-amber-300/30 bg-amber-300/10 text-amber-100'"
            >
                {{ confirmWarning }}
            </div>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Source') }}</dt><dd class="mt-1 break-all text-white">{{ sourceLabel(job) }}</dd></div>
                <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Target volume') }}</dt><dd class="mt-1 break-all text-white">{{ form.target_volume_name }}</dd></div>
                <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Destination') }}</dt><dd class="mt-1 break-words text-white">{{ job.destination?.name }}</dd></div>
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
                <button class="btn-primary" :disabled="form.processing || !confirmationMatches" @click="submit">{{ t('Queue restore') }}</button>
            </div>
        </section>

        <section v-if="step === 4" class="card p-4 sm:p-6">
            <h2 class="text-xl font-semibold">{{ t('Result') }}</h2>
            <p class="mt-2 text-sm text-slate-400">{{ t('The restore run will appear in the restore run detail after submission.') }}</p>
        </section>
    </AppLayout>
</template>
