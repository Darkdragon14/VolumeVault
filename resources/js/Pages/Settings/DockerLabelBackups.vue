<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { useDeployment } from '@/Composables/useDeployment';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import { computed, ref, watch } from 'vue';

const { localExecutionEnabled } = useDeployment();

type ScheduleType = 'hourly' | 'daily' | 'weekly' | 'cron';
type DayOfWeek = 'sunday' | 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday';
type BackupFilterMode = 'exclude' | 'include';

interface ScheduleConfig {
    everyHours?: number;
    time?: string;
    dayOfWeek?: DayOfWeek;
    expression?: string;
}

interface DockerLabelBackupSettings {
    docker_host_id: number;
    enabled: boolean;
    backup_destination_id: number | null;
    schedule_type: ScheduleType;
    schedule_config: ScheduleConfig;
    timezone: string | null;
    retention_days: number | null;
    retention_count: number | null;
    backup_filter_mode: BackupFilterMode;
    backup_include_paths: string | null;
    backup_exclude_regexp: string | null;
    backup_filename_template: string | null;
    notifications_enabled: boolean;
    notification_channel_ids: number[];
    alert_notifications_enabled: boolean;
    stop_containers_before_backup: boolean;
    last_sync_error: string | null;
    last_synced_at: string | null;
}

interface DockerLabelBackupForm {
    docker_host_id: number;
    enabled: boolean;
    backup_destination_id: number | null;
    schedule_type: ScheduleType;
    schedule_config: ScheduleConfig;
    timezone: string | null;
    retention_days: number | null;
    retention_count: number | null;
    backup_filter_mode: BackupFilterMode;
    backup_include_paths: string | null;
    backup_exclude_regexp: string | null;
    backup_filename_template: string | null;
    notifications_enabled: boolean;
    notification_channel_ids: number[];
    alert_notifications_enabled: boolean;
    stop_containers_before_backup: boolean;
}

interface NamedResource {
    id: number;
    name: string;
}

const props = defineProps<{
    settings: DockerLabelBackupSettings;
    hosts: (NamedResource & { supports_docker_labels: boolean })[];
    destinations: NamedResource[];
    notificationChannels: NamedResource[];
    timezones: string[];
}>();

const { t, formatDate } = useI18n();

const isTime = (value: unknown): value is string => typeof value === 'string' && /^([01]\d|2[0-3]):[0-5]\d$/.test(value);
const isDayOfWeek = (value: unknown): value is DayOfWeek => ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'].includes(String(value));

const scheduleConfigFor = (scheduleType: ScheduleType, config: ScheduleConfig = {}): ScheduleConfig => {
    if (scheduleType === 'hourly') {
        return { everyHours: Number.isInteger(config.everyHours) && config.everyHours! >= 1 && config.everyHours! <= 24 ? config.everyHours : 1 };
    }

    if (scheduleType === 'daily') {
        return { time: isTime(config.time) ? config.time : '02:00' };
    }

    if (scheduleType === 'weekly') {
        return {
            dayOfWeek: isDayOfWeek(config.dayOfWeek) ? config.dayOfWeek : 'sunday',
            time: isTime(config.time) ? config.time : '03:00',
        };
    }

    return { expression: typeof config.expression === 'string' && config.expression.trim() !== '' ? config.expression : '0 2 * * *' };
};

const formData = (): DockerLabelBackupForm => ({
    docker_host_id: props.settings.docker_host_id,
    enabled: props.settings.enabled,
    backup_destination_id: props.destinations.some((destination) => destination.id === props.settings.backup_destination_id) ? props.settings.backup_destination_id : null,
    schedule_type: props.settings.schedule_type,
    schedule_config: scheduleConfigFor(props.settings.schedule_type, props.settings.schedule_config),
    timezone: props.settings.timezone,
    retention_days: props.settings.retention_days,
    retention_count: props.settings.retention_count,
    backup_filter_mode: props.settings.backup_filter_mode,
    backup_include_paths: props.settings.backup_include_paths,
    backup_exclude_regexp: props.settings.backup_exclude_regexp,
    backup_filename_template: props.settings.backup_filename_template,
    notifications_enabled: props.settings.notifications_enabled,
    notification_channel_ids: [...(props.settings.notification_channel_ids || [])],
    alert_notifications_enabled: props.settings.alert_notifications_enabled,
    stop_containers_before_backup: props.settings.stop_containers_before_backup,
});

const form = useForm<DockerLabelBackupForm>(formData());
const page = usePage();
const canManage = computed(() => Boolean((page.props.can as { manageSensitiveData?: boolean })?.manageSensitiveData));
const selectedHostId = ref(props.settings.docker_host_id);
const switchingHost = ref(false);
const hostLoadFailed = ref(false);
const selectedHost = computed(() => props.hosts.find((host) => host.id === props.settings.docker_host_id));
const localDisabled = computed(() => props.settings.docker_host_id === 1 && !localExecutionEnabled.value);
watch(() => props.settings.docker_host_id, () => {
    form.defaults(formData());
    form.resetAndClearErrors();
    selectedHostId.value = props.settings.docker_host_id;
});
const changeHost = () => {
    if (switchingHost.value || form.processing) return;
    switchingHost.value = true;
    hostLoadFailed.value = false;
    const requestedHostId = selectedHostId.value;
    router.get('/settings/docker-label-backups', { docker_host_id: requestedHostId }, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            hostLoadFailed.value = props.settings.docker_host_id !== requestedHostId;
            selectedHostId.value = props.settings.docker_host_id;
            switchingHost.value = false;
        },
    });
};
const submit = () => {
    if (switchingHost.value || localDisabled.value || !canManage.value || form.processing) return;
    form.put('/settings/docker-label-backups');
};
const changeScheduleType = () => {
    form.schedule_config = scheduleConfigFor(form.schedule_type);
};
const toggleChannel = (id: number) => {
    form.notification_channel_ids = form.notification_channel_ids.includes(id)
        ? form.notification_channel_ids.filter((channelId: number) => channelId !== id)
        : [...form.notification_channel_ids, id];
};

const knownErrorFields = [
    'enabled',
    'backup_destination_id',
    'schedule_type',
    'schedule_config',
    'timezone',
    'retention_days',
    'retention_count',
    'backup_filter_mode',
    'backup_include_paths',
    'backup_exclude_regexp',
    'backup_filename_template',
    'notifications_enabled',
    'notification_channel_ids',
    'alert_notifications_enabled',
    'stop_containers_before_backup',
];
const matchesErrorField = (key: string, field: string) => key === field || key.startsWith(`${field}.`);
const errorsFor = (...fields: string[]): string[] => [...new Set(
    Object.entries(form.errors)
        .filter(([key]) => fields.some((field) => matchesErrorField(key, field)))
        .map(([, message]) => message),
)];
const unexpectedErrors = computed(() => [...new Set(
    Object.entries(form.errors)
        .filter(([key]) => !knownErrorFields.some((field) => matchesErrorField(key, field)))
        .map(([, message]) => message),
)]);
</script>

<template>
    <AppLayout :title="t('Docker label backups')" :subtitle="t('Define trusted defaults for backup jobs declared by running containers.')">
        <Head :title="t('Docker label backups')" />
        <section class="card mb-6 space-y-3 p-5">
            <label class="block space-y-2">
                <span class="label">{{ t('dockerLabels.host') }}</span>
                <select v-model="selectedHostId" data-host-selector class="input" :disabled="switchingHost || form.processing" @change="changeHost">
                    <option v-for="host in hosts" :key="host.id" :value="host.id" :disabled="host.id === 1 && !localExecutionEnabled">{{ host.name }} (#{{ host.id }})</option>
                </select>
            </label>
            <p v-if="switchingHost" role="status" class="animate-pulse text-sm text-slate-400">{{ t('dockerLabels.loading') }}</p>
            <p v-if="hostLoadFailed" role="alert" class="text-sm text-rose-300">{{ t('dockerLabels.loadFailed') }}</p>
            <template v-if="!switchingHost">
                <p class="text-sm text-slate-400">{{ selectedHost?.name }} (#{{ settings.docker_host_id }}) · {{ t('dockerLabels.lastSync') }}: {{ formatDate(settings.last_synced_at) }}</p>
                <p v-if="localDisabled" role="status" class="text-sm text-slate-400">{{ t('dockerHosts.localDisabled') }}</p>
                <p v-else-if="selectedHost && !selectedHost.supports_docker_labels" role="status" class="text-sm text-amber-300">{{ t('dockerLabels.upgradeAgent') }}</p>
                <p v-else-if="settings.docker_host_id !== 1" class="text-sm text-slate-400">{{ t('dockerLabels.nextInventory') }}</p>
                <section v-if="settings.last_sync_error" role="alert" class="rounded-xl border border-rose-300/30 bg-rose-400/10 p-4 text-sm text-rose-100">
                    <h2 class="font-semibold">{{ t('Last synchronization error') }}</h2>
                    <p class="mt-2 whitespace-pre-line">{{ settings.last_sync_error }}</p>
                </section>
            </template>
        </section>
        <form v-if="!switchingHost && !localDisabled" class="space-y-6" @submit.prevent="submit">
            <fieldset class="space-y-6" :disabled="!canManage || form.processing">
            <section class="card space-y-5 p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold">{{ t('Container automation') }}</h2>
                        <p class="mt-1 text-sm text-slate-400">{{ t('Containers must explicitly opt in with the VolumeVault enable label.') }}</p>
                    </div>
                    <button type="button" role="switch" class="inline-flex shrink-0 items-center gap-3 rounded-full border border-white/10 bg-slate-950/60 px-3 py-2 text-sm" :aria-checked="form.enabled" @click="form.enabled = !form.enabled">
                        <span class="relative inline-flex h-6 w-11 items-center rounded-full border p-0.5 transition" :class="form.enabled ? 'border-emerald-300/50 bg-emerald-500/50' : 'border-white/10 bg-slate-800'">
                            <span class="h-5 w-5 rounded-full bg-white transition-transform" :class="form.enabled ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                        </span>
                        <span>{{ form.enabled ? t('Enabled') : t('Disabled') }}</span>
                    </button>
                </div>
                <span v-for="error in errorsFor('enabled')" :key="error" class="block text-sm text-rose-300">{{ error }}</span>

                <label class="block space-y-2">
                    <span class="label">{{ t('Default destination') }}</span>
                    <select v-model="form.backup_destination_id" class="input">
                        <option :value="null">{{ t('Choose a destination') }}</option>
                        <option v-for="destination in destinations" :key="destination.id" :value="destination.id">{{ destination.name }}</option>
                    </select>
                    <span v-for="error in errorsFor('backup_destination_id')" :key="error" class="block text-sm text-rose-300">{{ error }}</span>
                </label>
            </section>

            <section class="card space-y-5 p-5">
                <div>
                    <h2 class="text-lg font-semibold">{{ t('Default schedule') }}</h2>
                    <p class="mt-1 text-sm text-slate-400">{{ t('Container labels can override these values for an individual backup.') }}</p>
                </div>
                <div class="grid gap-4 md:grid-cols-3">
                    <label class="space-y-2">
                        <span class="label">{{ t('Schedule') }}</span>
                        <select v-model="form.schedule_type" class="input" @change="changeScheduleType">
                            <option value="hourly">{{ t('Hourly') }}</option>
                            <option value="daily">{{ t('Daily') }}</option>
                            <option value="weekly">{{ t('Weekly') }}</option>
                            <option value="cron">{{ t('Cron') }}</option>
                        </select>
                        <span v-for="error in errorsFor('schedule_type')" :key="error" class="block text-sm text-rose-300">{{ error }}</span>
                    </label>
                    <label v-if="form.schedule_type === 'hourly'" class="space-y-2"><span class="label">{{ t('Every X hours') }}</span><input v-model.number="form.schedule_config.everyHours" class="input" type="number" min="1" max="24"></label>
                    <label v-if="form.schedule_type === 'daily' || form.schedule_type === 'weekly'" class="space-y-2"><span class="label">{{ t('Time') }}</span><input v-model="form.schedule_config.time" class="input" type="time"></label>
                    <label v-if="form.schedule_type === 'weekly'" class="space-y-2"><span class="label">{{ t('Day') }}</span><select v-model="form.schedule_config.dayOfWeek" class="input"><option v-for="day in ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']" :key="day" :value="day">{{ t(day) }}</option></select></label>
                    <label v-if="form.schedule_type === 'cron'" class="space-y-2"><span class="label">{{ t('Cron expression') }}</span><input v-model="form.schedule_config.expression" class="input font-mono" placeholder="0 2 * * *"></label>
                    <label class="space-y-2"><span class="label">{{ t('Timezone') }}</span><select v-model="form.timezone" class="input"><option :value="null">{{ t('Application default') }}</option><option v-for="timezone in timezones" :key="timezone" :value="timezone">{{ timezone }}</option></select><span v-for="error in errorsFor('timezone')" :key="error" class="block text-sm text-rose-300">{{ error }}</span></label>
                </div>
                <span v-for="error in errorsFor('schedule_config')" :key="error" class="block text-sm text-rose-300">{{ error }}</span>
            </section>

            <section class="card space-y-5 p-5">
                <h2 class="text-lg font-semibold">{{ t('Default backup options') }}</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="space-y-2"><span class="label">{{ t('Retention days') }}</span><input v-model.number="form.retention_days" class="input" type="number" min="1"><span v-for="error in errorsFor('retention_days')" :key="error" class="block text-sm text-rose-300">{{ error }}</span></label>
                    <label class="space-y-2"><span class="label">{{ t('Retention count') }}</span><input v-model.number="form.retention_count" class="input" type="number" min="1"><span v-for="error in errorsFor('retention_count')" :key="error" class="block text-sm text-rose-300">{{ error }}</span></label>
                    <label class="space-y-2"><span class="label">{{ t('Filter mode') }}</span><select v-model="form.backup_filter_mode" class="input"><option value="exclude">{{ t('Exclude matching files') }}</option><option value="include">{{ t('Include only') }}</option></select><span v-for="error in errorsFor('backup_filter_mode')" :key="error" class="block text-sm text-rose-300">{{ error }}</span></label>
                    <label class="space-y-2"><span class="label">{{ t('Backup filename template') }}</span><input v-model="form.backup_filename_template" class="input"><span v-for="error in errorsFor('backup_filename_template')" :key="error" class="block text-sm text-rose-300">{{ error }}</span></label>
                    <label v-if="form.backup_filter_mode === 'include'" class="space-y-2 md:col-span-2"><span class="label">{{ t('Included paths') }}</span><textarea v-model="form.backup_include_paths" class="input min-h-24"></textarea></label>
                    <label v-else class="space-y-2 md:col-span-2"><span class="label">{{ t('Exclude regexp') }}</span><textarea v-model="form.backup_exclude_regexp" class="input min-h-24 font-mono"></textarea></label>
                </div>
                <span v-for="error in errorsFor('backup_include_paths', 'backup_exclude_regexp')" :key="error" class="block text-sm text-rose-300">{{ error }}</span>
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="space-y-2"><label class="flex items-center gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-3"><input v-model="form.notifications_enabled" type="checkbox"><span>{{ t('Backup notifications') }}</span></label><span v-for="error in errorsFor('notifications_enabled')" :key="error" class="block text-sm text-rose-300">{{ error }}</span></div>
                    <div class="space-y-2"><label class="flex items-center gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-3"><input v-model="form.alert_notifications_enabled" type="checkbox"><span>{{ t('Alert notifications') }}</span></label><span v-for="error in errorsFor('alert_notifications_enabled')" :key="error" class="block text-sm text-rose-300">{{ error }}</span></div>
                    <div class="space-y-2"><label class="flex items-center gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-3"><input v-model="form.stop_containers_before_backup" type="checkbox"><span>{{ t('Stop containers before backup') }}</span></label><span v-for="error in errorsFor('stop_containers_before_backup')" :key="error" class="block text-sm text-rose-300">{{ error }}</span></div>
                </div>
                <div v-if="notificationChannels.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <button v-for="channel in notificationChannels" :key="channel.id" type="button" class="rounded-xl border p-3 text-left text-sm" :class="form.notification_channel_ids.includes(channel.id) ? 'border-sky-300/60 bg-sky-400/10' : 'border-white/10 bg-white/[0.03]'" @click="toggleChannel(channel.id)">{{ channel.name }}</button>
                </div>
                <span v-for="error in errorsFor('notification_channel_ids')" :key="error" class="block text-sm text-rose-300">{{ error }}</span>
            </section>

            <section v-if="unexpectedErrors.length" class="rounded-xl border border-rose-300/30 bg-rose-400/10 p-4 text-sm text-rose-100">
                <p v-for="error in unexpectedErrors" :key="error">{{ error }}</p>
            </section>

            <button v-if="canManage" class="btn-primary" :disabled="form.processing">{{ t('Save settings') }}</button>
            </fieldset>
        </form>
    </AppLayout>
</template>
