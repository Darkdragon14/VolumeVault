<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/i18n';

const props = defineProps<{
    job: any | null;
    volumes: any[];
    destinations: any[];
}>();

const page = usePage();
const { t } = useI18n();
const params = new URLSearchParams(page.url.split('?')[1] || '');
const editing = computed(() => Boolean(props.job));
const scheduleTypes = ['hourly', 'daily', 'weekly', 'cron'];
const days = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
const excludeExamples = [
    { label: 'Exclude .log files', value: '\\.log$' },
    { label: 'Exclude cache folders', value: '(^|/)cache(/|$)' },
    { label: 'Exclude node_modules', value: '(^|/)node_modules(/|$)' },
];

const form = useForm({
    name: props.job?.name || '',
    volume_name: props.job?.volume_name || params.get('volume') || '',
    backup_destination_id: props.job?.backup_destination_id || props.destinations[0]?.id || '',
    schedule_type: props.job?.schedule_type || 'daily',
    schedule_config: props.job?.schedule_config || { time: '02:00', everyHours: 6, dayOfWeek: 'sunday', expression: '0 2 * * *' },
    retention_days: props.job?.retention_days || '',
    retention_count: props.job?.retention_count || '',
    backup_exclude_regexp: props.job?.backup_exclude_regexp || '',
    stop_containers_before_backup: props.job?.stop_containers_before_backup || false,
});

const summary = computed(() => {
    if (form.schedule_type === 'hourly') return `Every ${form.schedule_config.everyHours || 1} hours`;
    if (form.schedule_type === 'daily') return `Every day at ${form.schedule_config.time || '02:00'}`;
    if (form.schedule_type === 'weekly') return `Every ${t(form.schedule_config.dayOfWeek || 'sunday')} at ${form.schedule_config.time || '03:00'}`;
    return `Cron: ${form.schedule_config.expression || ''}`;
});

const submit = () => {
    if (editing.value) {
        form.put(`/backup-jobs/${props.job.id}`);
        return;
    }

    form.post('/backup-jobs');
};
</script>

<template>
    <Head :title="editing ? t('Edit backup job') : t('New backup job')" />
    <AppLayout :title="editing ? t('Edit backup job') : t('New backup job')" :subtitle="t('Choose the source volume, destination, schedule, retention, and backup exclusions.')">
        <form class="card max-w-4xl space-y-6 p-4 sm:p-6" @submit.prevent="submit">
            <div v-if="!volumes.length || !destinations.length" class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-100">
                {{ t('You need at least one existing Docker volume and one active backup destination before creating a job.') }}
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Job name') }}</span>
                    <input v-model="form.name" class="input" required placeholder="App data nightly backup">
                    <span v-if="form.errors.name" class="text-sm text-rose-300">{{ form.errors.name }}</span>
                </label>

                <label class="space-y-2">
                    <span class="label">{{ t('Docker volume') }}</span>
                    <select v-model="form.volume_name" class="input" required>
                        <option value="" disabled>{{ t('Select a volume') }}</option>
                        <option v-for="volume in volumes" :key="volume.name" :value="volume.name">{{ volume.name }}</option>
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="label">{{ t('Destination') }}</span>
                    <select v-model="form.backup_destination_id" class="input" required>
                        <option v-for="destination in destinations" :key="destination.id" :value="destination.id">{{ destination.name }} / {{ destination.target_label || destination.bucket }}</option>
                    </select>
                </label>
            </div>

            <section class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <h2 class="mb-4 text-lg font-semibold">{{ t('Schedule') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                    <label v-for="type in scheduleTypes" :key="type" class="flex cursor-pointer items-center gap-2 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-sm capitalize">
                        <input v-model="form.schedule_type" type="radio" :value="type" class="text-sky-400">
                        {{ t(type) }}
                    </label>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label v-if="form.schedule_type === 'hourly'" class="space-y-2">
                        <span class="label">{{ t('Every X hours') }}</span>
                        <input v-model="form.schedule_config.everyHours" class="input" type="number" min="1" max="24">
                    </label>
                    <label v-if="form.schedule_type === 'daily' || form.schedule_type === 'weekly'" class="space-y-2">
                        <span class="label">{{ t('Time') }}</span>
                        <input v-model="form.schedule_config.time" class="input" type="time">
                    </label>
                    <label v-if="form.schedule_type === 'weekly'" class="space-y-2">
                        <span class="label">{{ t('Day of week') }}</span>
                        <select v-model="form.schedule_config.dayOfWeek" class="input">
                            <option v-for="day in days" :key="day" :value="day">{{ t(day) }}</option>
                        </select>
                    </label>
                    <label v-if="form.schedule_type === 'cron'" class="space-y-2 sm:col-span-2">
                        <span class="label">{{ t('Cron expression') }}</span>
                        <input v-model="form.schedule_config.expression" class="input" placeholder="0 2 * * *">
                    </label>
                </div>
                <p class="mt-4 break-words rounded-xl bg-sky-400/10 p-3 text-sm text-sky-100">{{ t('Schedule summary: {summary}', { summary }) }}</p>
                <span v-if="form.errors.schedule_config" class="mt-2 block text-sm text-rose-300">{{ form.errors.schedule_config }}</span>
            </section>

            <div class="grid gap-4 sm:grid-cols-3">
                <label class="space-y-2">
                    <span class="label">{{ t('Retention days') }}</span>
                    <input v-model="form.retention_days" class="input" type="number" min="1" :placeholder="t('Optional')">
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Retention count') }}</span>
                    <input v-model="form.retention_count" class="input" type="number" min="1" :placeholder="t('Optional')">
                </label>
                <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-4 text-sm sm:mt-7">
                    <input v-model="form.stop_containers_before_backup" type="checkbox" class="rounded border-slate-600 bg-slate-950 text-sky-400">
                    {{ t('Stop containers before backup') }}
                </label>
            </div>

            <section class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <div class="space-y-2">
                    <label for="backup_exclude_regexp" class="label">{{ t('Exclude files') }}</label>
                    <textarea id="backup_exclude_regexp" v-model="form.backup_exclude_regexp" class="input min-h-24 font-mono text-sm" :placeholder="t('Optional regex, for example: {example}', { example: '\\.log$' })" />
                    <p class="text-sm text-slate-300">{{ t('Files whose full path matches this Go regular expression are excluded from the archive. Leave empty to include everything.') }}</p>
                    <span v-if="form.errors.backup_exclude_regexp" class="text-sm text-rose-300">{{ form.errors.backup_exclude_regexp }}</span>
                </div>
                <div class="mt-4 rounded-xl border border-sky-300/20 bg-sky-400/10 p-4 text-sm text-sky-50">
                    <p class="font-medium">{{ t('Not comfortable with regex? Start with one of these examples:') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button v-for="example in excludeExamples" :key="example.value" type="button" class="max-w-full break-all rounded-lg border border-sky-200/30 bg-sky-300/10 px-3 py-2 text-left font-mono text-xs text-sky-50 hover:bg-sky-300/20" @click="form.backup_exclude_regexp = example.value">
                            {{ t(example.label) }}: {{ example.value }}
                        </button>
                    </div>
                    <p class="mt-3 text-sky-100">{{ t('Ask for help if you are unsure: describe what should be ignored, such as log files or cache folders, and VolumeVault can guide the regex.') }}</p>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button class="btn-primary" :disabled="form.processing || !volumes.length || !destinations.length">{{ editing ? t('Update job') : t('Create job') }}</button>
                <Link href="/backup-jobs" class="btn-secondary">{{ t('Cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
