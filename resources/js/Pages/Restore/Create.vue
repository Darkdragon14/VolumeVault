<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from '@/i18n';

const props = defineProps<{
    job: any;
    backups: any[];
    listError?: string | null;
    generatedTargetVolumeName: string;
}>();

const step = ref(1);
const { t, formatDate } = useI18n();
const form = useForm({
    selected_backup_key: '',
    mode: 'new_volume',
    target_volume_name: props.generatedTargetVolumeName,
    confirmation_text: '',
});

const selectedBackup = computed(() => props.backups.find((backup) => backup.key === form.selected_backup_key));
const formatSize = (bytes?: number) => {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${(bytes / Math.pow(1024, index)).toFixed(1)} ${units[index]}`;
};
const submit = () => form.post(`/backup-jobs/${props.job.id}/restore`);
</script>

<template>
    <Head :title="`Restore ${job.name}`" />
    <AppLayout :title="`Restore ${job.name}`" :subtitle="t('Choose a backup archive and restore it into a new Docker volume.')">
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
            <p class="mt-1 text-sm text-slate-400">Backups are listed newest first from {{ job.destination?.name }}.</p>
            <p v-if="listError" class="mt-4 rounded-xl bg-rose-400/10 p-3 text-sm text-rose-100">{{ listError }}</p>

            <div v-if="backups.length" class="mt-5 space-y-3">
                <label v-for="backup in backups" :key="backup.key" class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-white/5 p-4 hover:bg-white/10">
                    <input v-model="form.selected_backup_key" type="radio" :value="backup.key" class="mt-1 text-sky-400">
                    <span class="min-w-0 flex-1">
                        <span class="block break-all font-medium text-white">{{ backup.display_name || backup.key }}</span>
                        <span class="mt-1 block text-xs text-slate-400">{{ formatDate(backup.last_modified) }} / {{ formatSize(backup.size) }}</span>
                    </span>
                </label>
            </div>
            <p v-else class="mt-5 rounded-xl border border-dashed border-white/10 p-5 text-sm text-slate-400">{{ t('No backup objects found. Run a backup first or check the destination path.') }}</p>

            <button class="btn-primary mt-5" :disabled="!form.selected_backup_key" @click="step = 2">{{ t('Continue') }}</button>
        </section>

        <section v-if="step === 2" class="card p-4 sm:p-6">
            <h2 class="text-xl font-semibold">{{ t('Select restore mode') }}</h2>
            <div class="mt-5 grid gap-4 lg:grid-cols-3">
                <label class="cursor-pointer rounded-2xl border border-sky-300/40 bg-sky-300/10 p-5">
                    <input v-model="form.mode" type="radio" value="new_volume" class="text-sky-400">
                    <span class="mt-3 block text-lg font-semibold">{{ t('Restore to new volume') }}</span>
                    <span class="mt-2 block text-sm text-slate-300">Recommended. Never overwrites the original volume.</span>
                </label>
                <label class="cursor-not-allowed rounded-2xl border border-white/10 bg-white/5 p-5 opacity-60">
                    <input type="radio" disabled>
                    <span class="mt-3 block text-lg font-semibold">{{ t('Restore in place') }}</span>
                    <span class="mt-2 block text-sm text-slate-400">Coming later. Requires typed confirmation.</span>
                </label>
                <label class="cursor-not-allowed rounded-2xl border border-white/10 bg-white/5 p-5 opacity-60">
                    <input type="radio" disabled>
                    <span class="mt-3 block text-lg font-semibold">{{ t('Safe in-place restore') }}</span>
                    <span class="mt-2 block text-sm text-slate-400">Coming later. Will stop/restart affected containers.</span>
                </label>
            </div>

            <label class="mt-5 block space-y-2">
                <span class="label">{{ t('Target volume name') }}</span>
                <input v-model="form.target_volume_name" class="input">
                <span v-if="form.errors.target_volume_name" class="text-sm text-rose-300">{{ form.errors.target_volume_name }}</span>
            </label>

            <div class="mt-5 flex flex-wrap gap-3">
                <button class="btn-secondary" @click="step = 1">{{ t('Back') }}</button>
                <button class="btn-primary" @click="step = 3">{{ t('Continue') }}</button>
            </div>
        </section>

        <section v-if="step === 3" class="card p-4 sm:p-6">
            <h2 class="text-xl font-semibold">{{ t('Confirm restore') }}</h2>
            <div class="mt-5 rounded-xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-100">
                Restore can take time. The default mode creates a new Docker volume and does not overwrite the source volume.
            </div>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Source volume') }}</dt><dd class="mt-1 break-all text-white">{{ job.volume_name }}</dd></div>
                <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Target volume') }}</dt><dd class="mt-1 break-all text-white">{{ form.target_volume_name }}</dd></div>
                <div class="min-w-0"><dt class="text-xs uppercase text-slate-400">{{ t('Destination') }}</dt><dd class="mt-1 break-words text-white">{{ job.destination?.name }}</dd></div>
                <div><dt class="text-xs uppercase text-slate-400">{{ t('Selected backup') }}</dt><dd class="mt-1 break-all text-white">{{ selectedBackup?.display_name || selectedBackup?.key }}</dd></div>
            </dl>
            <div class="mt-5 flex flex-wrap gap-3">
                <button class="btn-secondary" @click="step = 2">{{ t('Back') }}</button>
                <button class="btn-primary" :disabled="form.processing" @click="submit">{{ t('Queue restore') }}</button>
            </div>
        </section>

        <section v-if="step === 4" class="card p-4 sm:p-6">
            <h2 class="text-xl font-semibold">Result</h2>
            <p class="mt-2 text-sm text-slate-400">The restore run will appear in the restore run detail after submission.</p>
        </section>
    </AppLayout>
</template>
