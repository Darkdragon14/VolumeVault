<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import InfoTooltip from '@/Components/InfoTooltip.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from '@/i18n';
import { bestSizeUnit, bytesToUnitValue, sizeUnits, type SizeUnit, unitValueToBytes } from '@/Composables/useFormatBytes';

const props = defineProps<{
    job: any | null;
    volumes: any[];
    containers?: any[];
    destinations: any[];
    notificationChannels: any[];
    defaultNotificationChannelIds: number[];
    alertRules: any[];
    timezones: string[];
    appTimezone: string;
    groups?: any[];
}>();

const page = usePage();
const { t, translateError } = useI18n();
const alertSizeUnitSelections = ref<Record<string, SizeUnit>>({});
const params = new URLSearchParams(page.url.split('?')[1] || '');
const editing = computed(() => Boolean(props.job));
const sourceTypes = ['docker_volume', 'host_path'];
const scheduleTypes = ['hourly', 'daily', 'weekly', 'cron'];
const days = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
const excludeExamples = [
    { label: 'Exclude .log files', value: '\\.log$' },
    { label: 'Exclude cache folders', value: '(^|/)cache(/|$)' },
    { label: 'Exclude node_modules', value: '(^|/)node_modules(/|$)' },
];
const alertConfigOverrides = new Map((props.job?.alert_configs || []).map((config: any) => [config.alert_rule_id, config]));
const jobAlertConfig = (rule: any, override: any) => {
    const config = { ...rule.config, ...(override?.config || {}) };

    delete config.check_interval_minutes;

    return config;
};
const initialAlertConfigs = props.alertRules.map((rule) => {
    const override = alertConfigOverrides.get(rule.id) as any;

    return {
        alert_rule_id: rule.id,
        type: rule.type,
        enabled: override?.enabled ?? rule.enabled,
        config: jobAlertConfig(rule, override),
    };
});

const form = useForm({
    name: props.job?.name || '',
    source_type: props.job?.source_type || 'docker_volume',
    volume_name: props.job?.volume_name || params.get('volume') || '',
    host_path: props.job?.host_path || '',
    backup_destination_id: props.job?.backup_destination_id || props.destinations[0]?.id || '',
    schedule_type: props.job?.schedule_type || 'daily',
    schedule_config: props.job?.schedule_config || { time: '02:00', everyHours: 6, dayOfWeek: 'sunday', expression: '0 2 * * *' },
    timezone: props.job?.timezone || '',
    retention_days: props.job?.retention_days || '',
    retention_count: props.job?.retention_count || '',
    backup_exclude_regexp: props.job?.backup_exclude_regexp || '',
    // New jobs default to the simple include mode; editing keeps the stored mode.
    backup_filter_mode: props.job?.backup_filter_mode || (props.job ? 'exclude' : 'include'),
    backup_include_paths: props.job?.backup_include_paths || '',
    backup_filename_template: props.job?.backup_filename_template || '',
    notifications_enabled: props.job?.notifications_enabled ?? true,
    notification_channel_ids: (props.job?.notification_channel_ids || props.defaultNotificationChannelIds || []) as number[],
    use_custom_alert_settings: props.job?.use_custom_alert_settings ?? false,
    alert_notifications_enabled: props.job?.alert_notifications_enabled ?? true,
    alert_configs: initialAlertConfigs,
    stop_containers_before_backup: props.job?.stop_containers_before_backup || false,
    stop_container_names: (props.job?.stop_container_names || []) as string[],
    // Planning mode: a standalone job keeps its own schedule/notifications; a
    // grouped job delegates them to a backup group (existing or created inline).
    planning_mode: props.job?.backup_job_group_id ? 'group' : 'standalone',
    group_selection: (props.job?.backup_job_group_id || props.groups?.length) ? 'existing' : 'new',
    backup_job_group_id: props.job?.backup_job_group_id || props.groups?.[0]?.id || '',
    new_group: {
        name: '',
        schedule_type: 'daily',
        schedule_config: { time: '02:00', everyHours: 6, dayOfWeek: 'sunday', expression: '0 2 * * *' },
        timezone: '',
        failure_policy: 'continue',
        notifications_enabled: true,
        notification_channel_ids: (props.defaultNotificationChannelIds || []) as number[],
    },
});

const groups = computed(() => props.groups || []);
const isGrouped = computed(() => form.planning_mode === 'group');
const creatingNewGroup = computed(() => form.group_selection === 'new');
const failurePolicies = ['continue', 'stop'];

const newGroupSummary = computed(() => {
    const config = form.new_group.schedule_config;
    if (form.new_group.schedule_type === 'hourly') return t('Every {hours} hours', { hours: config.everyHours || 1 });
    if (form.new_group.schedule_type === 'daily') return t('Every day at {time}', { time: config.time || '02:00' });
    if (form.new_group.schedule_type === 'weekly') return t('Every {day} at {time}', { day: t(config.dayOfWeek || 'sunday'), time: config.time || '03:00' });
    return t('Cron: {expression}', { expression: config.expression || '' });
});

const toggleNewGroupNotifications = () => {
    form.new_group.notifications_enabled = !form.new_group.notifications_enabled;
};

const toggleNewGroupChannel = (id: number) => {
    const ids = form.new_group.notification_channel_ids as number[];
    form.new_group.notification_channel_ids = ids.includes(id) ? ids.filter((channelId) => channelId !== id) : [...ids, id];
};

const volumeSearch = ref(form.volume_name);
const volumeSelectorOpen = ref(false);
const isDockerVolumeSource = computed(() => form.source_type === 'docker_volume');
const canSubmit = computed(() => Boolean(props.destinations.length) && (!isDockerVolumeSource.value || Boolean(props.volumes.length)));

const sourceTypeLabel = (type: string) => type === 'host_path' ? 'Host path' : 'Docker volume';
const sourceTypeDescription = (type: string) => type === 'host_path'
    ? 'Back up an existing directory from the Docker host.'
    : 'Back up a Docker-managed volume discovered by VolumeVault.';
const alertRuleLabel = (type: string) => ({
    backup_too_old: 'Backup too old',
    job_never_succeeded: 'Job never succeeded',
    job_in_error_too_long: 'Job in error too long',
    backup_size_out_of_range: 'Backup size out of range',
}[type] || type);
const alertSizeUnitKey = (alertRuleId: number, key: string) => `${alertRuleId}.${key}`;
const selectedAlertSizeUnit = (alertConfig: any, key: string): SizeUnit => alertSizeUnitSelections.value[alertSizeUnitKey(alertConfig.alert_rule_id, key)] || bestSizeUnit(alertConfig.config[key]);
const sizeInputValue = (config: any, key: string, unit: SizeUnit) => bytesToUnitValue(config[key], unit);
const inputValue = (event: Event) => (event.target as HTMLInputElement).value;
const selectValue = (event: Event) => (event.target as HTMLSelectElement).value as SizeUnit;
const updateAlertSizeUnit = (alertConfig: any, key: string, unit: SizeUnit) => alertSizeUnitSelections.value[alertSizeUnitKey(alertConfig.alert_rule_id, key)] = unit;
const updateSizeThreshold = (config: any, key: string, value: string, unit: SizeUnit) => config[key] = unitValueToBytes(value, unit);
const alertConfigError = (index: number, key: string) => form.errors[`alert_configs.${index}.config.${key}`];

const alertSizeKeys = ['backup_size_out_of_range_min_bytes', 'backup_size_out_of_range_max_bytes'];
for (const alertConfig of form.alert_configs) {
    for (const key of alertSizeKeys) {
        const sizeKey = alertSizeUnitKey(alertConfig.alert_rule_id, key);
        if (!(sizeKey in alertSizeUnitSelections.value)) {
            alertSizeUnitSelections.value[sizeKey] = bestSizeUnit(alertConfig.config[key]);
        }
    }
}

const filteredVolumes = computed(() => {
    if (!isDockerVolumeSource.value) return [];

    const query = volumeSearch.value.trim().toLowerCase();

    if (!query) return props.volumes;

    return props.volumes.filter((volume) => volume.name.toLowerCase().includes(query));
});

const selectedVolume = computed(() => props.volumes.find((volume) => volume.name === form.volume_name));
const safeFilenamePart = (value: string, fallback: string) => value.replace(/[^A-Za-z0-9_.-]+/g, '_') || fallback;
const archiveTemplate = computed(() => (form.backup_filename_template || '').trim());
const archiveTemplateHasRunToken = computed(() => /\{(id|run)\}/.test(archiveTemplate.value));
const archiveTemplateHasDateAndTimeTokens = computed(() => (
    /\{year\}/.test(archiveTemplate.value)
    && /\{month\}/.test(archiveTemplate.value)
    && /\{day\}/.test(archiveTemplate.value)
    && /\{(time|hour|minute|second)\}/.test(archiveTemplate.value)
));
const archiveTemplateOverwriteRisk = computed(() => archiveTemplate.value !== '' && !archiveTemplateHasRunToken.value && !archiveTemplateHasDateAndTimeTokens.value);
const archiveFilenamePreview = computed(() => {
    const now = new Date();
    const source = form.source_type === 'host_path'
        ? form.host_path.replace(/^\/+|\/+$/g, '')
        : form.volume_name;
    const replacements: Record<string, string> = {
        '{name}': safeFilenamePart(form.name || 'backup', 'backup'),
        '{source}': safeFilenamePart(source || 'source', 'source'),
        '{id}': '123',
        '{run}': '123',
        '{year}': String(now.getFullYear()),
        '{month}': String(now.getMonth() + 1).padStart(2, '0'),
        '{day}': String(now.getDate()).padStart(2, '0'),
        '{time}': [now.getHours(), now.getMinutes(), now.getSeconds()].map((part) => String(part).padStart(2, '0')).join('-'),
        '{hour}': String(now.getHours()).padStart(2, '0'),
        '{minute}': String(now.getMinutes()).padStart(2, '0'),
        '{second}': String(now.getSeconds()).padStart(2, '0'),
    };

    return Object.entries(replacements).reduce(
        (filename, [token, value]) => filename.split(token).join(value),
        archiveTemplate.value || 'volumevault-{source}-run-{id}',
    ) + '.tar.gz';
});

const updateVolumeSearch = () => {
    if (!isDockerVolumeSource.value) return;

    const matchingVolume = props.volumes.find((volume) => volume.name === volumeSearch.value);

    form.volume_name = matchingVolume?.name || '';
    volumeSelectorOpen.value = true;
};

const selectVolume = (volume: any) => {
    form.volume_name = volume.name;
    volumeSearch.value = volume.name;
    volumeSelectorOpen.value = false;
};

const toggleNotificationChannel = (id: number) => {
    const ids = form.notification_channel_ids as number[];
    form.notification_channel_ids = ids.includes(id) ? ids.filter((channelId) => channelId !== id) : [...ids, id];
};

const toggleJobNotifications = () => {
    form.notifications_enabled = !form.notifications_enabled;
};

const toggleAlertNotifications = () => {
    form.alert_notifications_enabled = !form.alert_notifications_enabled;
};

const toggleCustomAlertSettings = () => {
    form.use_custom_alert_settings = !form.use_custom_alert_settings;
};

const toggleAlertConfigEnabled = (index: number) => {
    form.alert_configs[index].enabled = !form.alert_configs[index].enabled;
};

const toggleAlertReminder = (index: number) => {
    form.alert_configs[index].config.reminder_enabled = !form.alert_configs[index].config.reminder_enabled;
};

const toggleStopContainersBeforeBackup = () => {
    form.stop_containers_before_backup = !form.stop_containers_before_backup;
};

const containerList = computed(() => props.containers || []);
const containerName = (container: any): string => String(container?.names || '').split(',')[0].replace(/^\/+/, '').trim();

const toggleStopContainerName = (name: string) => {
    const names = form.stop_container_names as string[];
    form.stop_container_names = names.includes(name) ? names.filter((n) => n !== name) : [...names, name];
};

watch(() => form.source_type, (sourceType) => {
    if (sourceType === 'host_path') {
        form.volume_name = '';
        volumeSearch.value = '';
        volumeSelectorOpen.value = false;
    } else {
        form.stop_container_names = [];
    }
});

const summary = computed(() => {
    if (form.schedule_type === 'hourly') return t('Every {hours} hours', { hours: form.schedule_config.everyHours || 1 });
    if (form.schedule_type === 'daily') return t('Every day at {time}', { time: form.schedule_config.time || '02:00' });
    if (form.schedule_type === 'weekly') return t('Every {day} at {time}', { day: t(form.schedule_config.dayOfWeek || 'sunday'), time: form.schedule_config.time || '03:00' });
    return t('Cron: {expression}', { expression: form.schedule_config.expression || '' });
});

const submissionPayload = (data: any) => {
    if (data.use_custom_alert_settings) return data;

    const payload = { ...data };
    delete payload.alert_configs;

    return payload;
};

const submit = () => {
    if (editing.value) {
        form.transform(submissionPayload).put(`/backup-jobs/${props.job.id}`);
        return;
    }

    form.transform(submissionPayload).post('/backup-jobs');
};
</script>

<template>
    <Head :title="editing ? t('Edit backup job') : t('New backup job')" />
    <AppLayout :title="editing ? t('Edit backup job') : t('New backup job')" :subtitle="t('Choose the backup source, destination, schedule, retention, and exclusions.')">
        <form class="card max-w-4xl space-y-6 p-4 sm:p-6" @submit.prevent="submit">
            <div v-if="!destinations.length || (isDockerVolumeSource && !volumes.length)" class="space-y-2 rounded-xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-100">
                <p v-if="!destinations.length">{{ t('You need at least one active backup destination before creating a job.') }}</p>
                <p v-if="isDockerVolumeSource && !volumes.length">{{ t('Sync Docker volumes first, or choose a host path source.') }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Job name') }}</span>
                    <input v-model="form.name" class="input" required placeholder="App data nightly backup">
                    <span v-if="form.errors.name" class="text-sm text-rose-300">{{ form.errors.name }}</span>
                </label>

                <section class="space-y-3 sm:col-span-2">
                    <div>
                        <span class="label">{{ t('Backup source') }}</span>
                        <span v-if="form.errors.source_type" class="mt-2 block text-sm text-rose-300">{{ form.errors.source_type }}</span>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label v-for="type in sourceTypes" :key="type" class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 text-sm">
                            <input v-model="form.source_type" type="radio" :value="type" class="mt-1 text-sky-400">
                            <span>
                                <span class="block font-semibold text-white">{{ t(sourceTypeLabel(type)) }}</span>
                                <span class="mt-1 block text-slate-300">{{ t(sourceTypeDescription(type)) }}</span>
                            </span>
                        </label>
                    </div>
                </section>

                <div v-if="isDockerVolumeSource" class="space-y-2">
                    <span class="label">{{ t('Docker volume') }}</span>
                    <div class="relative">
                        <input
                            v-model="volumeSearch"
                            class="input pr-10"
                            required
                            autocomplete="off"
                            :placeholder="t('Search volumes')"
                            @focus="volumeSelectorOpen = true"
                            @input="updateVolumeSearch"
                            @keydown.escape="volumeSelectorOpen = false"
                        >
                        <button type="button" class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 hover:text-slate-200" :aria-label="t('Select a volume')" @click="volumeSelectorOpen = !volumeSelectorOpen">
                            <span aria-hidden="true">⌄</span>
                        </button>

                        <div v-if="volumeSelectorOpen" class="absolute z-20 mt-2 max-h-60 w-full overflow-auto rounded-xl border border-slate-200 bg-white py-1 text-sm shadow-xl shadow-slate-200/60 dark:border-slate-700 dark:bg-slate-950 dark:shadow-black/30">
                            <button
                                v-for="volume in filteredVolumes"
                                :key="volume.name"
                                type="button"
                                class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-sky-50 hover:text-sky-700 dark:text-slate-200 dark:hover:bg-sky-400/10 dark:hover:text-sky-100"
                                @mousedown.prevent="selectVolume(volume)"
                            >
                                <span class="block break-all">{{ volume.name }}</span>
                            </button>
                            <p v-if="!filteredVolumes.length" class="px-3 py-2 text-slate-500 dark:text-slate-400">{{ t('No matching volumes') }}</p>
                        </div>
                    </div>
                    <p v-if="selectedVolume" class="text-sm text-slate-300">{{ t('Selected volume: {volume}', { volume: selectedVolume.name }) }}</p>
                    <span v-if="form.errors.volume_name" class="text-sm text-rose-300">{{ form.errors.volume_name }}</span>
                </div>

                <label v-else class="space-y-2">
                    <span class="label">{{ t('Host path') }}</span>
                    <input v-model="form.host_path" class="input font-mono" required placeholder="/srv/app-data">
                    <p class="text-sm text-slate-300">{{ t('The path must be an existing directory on the Docker host. If VOLUMEVAULT_HOST_PATH_ALLOWLIST is set, it must match one of the allowed prefixes.') }}</p>
                    <span v-if="form.errors.host_path" class="text-sm text-rose-300">{{ translateError(form.errors.host_path) }}</span>
                </label>

                <label class="space-y-2" :class="{ 'sm:col-span-2': !isDockerVolumeSource }">
                    <span class="label">{{ t('Destination') }}</span>
                    <select v-model="form.backup_destination_id" class="input" required>
                        <option v-for="destination in destinations" :key="destination.id" :value="destination.id">{{ destination.name }} / {{ destination.target_label || destination.bucket }}</option>
                    </select>
                </label>
            </div>

            <section class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-semibold">{{ t('Planning mode') }}</h2>
                    <InfoTooltip :text="t('A standalone job runs on its own schedule with its own notifications. A grouped job is scheduled by its group, which sends a single start/success/fail notification for all its volumes.')" />
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 text-sm">
                        <input v-model="form.planning_mode" type="radio" value="standalone" class="mt-1 text-sky-400">
                        <span>
                            <span class="block font-semibold text-white">{{ t('Scheduled individually') }}</span>
                            <span class="mt-1 block text-slate-300">{{ t('This job runs on its own schedule with its own notifications.') }}</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 text-sm">
                        <input v-model="form.planning_mode" type="radio" value="group" class="mt-1 text-sky-400">
                        <span>
                            <span class="block font-semibold text-white">{{ t('Part of a group') }}</span>
                            <span class="mt-1 block text-slate-300">{{ t('The group owns the schedule and sends one notification for all its volumes.') }}</span>
                        </span>
                    </label>
                </div>

                <div v-if="isGrouped" class="mt-4 space-y-4">
                    <div v-if="groups.length" class="grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-sm">
                            <input v-model="form.group_selection" type="radio" value="existing" class="text-sky-400">
                            {{ t('Use an existing group') }}
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-sm">
                            <input v-model="form.group_selection" type="radio" value="new" class="text-sky-400">
                            {{ t('Create a new group') }}
                        </label>
                    </div>

                    <label v-if="!creatingNewGroup" class="block space-y-2">
                        <span class="label">{{ t('Group') }}</span>
                        <select v-model="form.backup_job_group_id" class="input" required>
                            <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option>
                        </select>
                        <span v-if="form.errors.backup_job_group_id" class="text-sm text-rose-300">{{ form.errors.backup_job_group_id }}</span>
                    </label>

                    <div v-else class="space-y-4 rounded-xl border border-white/10 bg-slate-950/40 p-4">
                        <label class="block space-y-2">
                            <span class="label">{{ t('New group name') }}</span>
                            <input v-model="form.new_group.name" class="input" :placeholder="t('Nightly backups')">
                            <span v-if="form.errors['new_group.name']" class="text-sm text-rose-300">{{ form.errors['new_group.name'] }}</span>
                        </label>

                        <div>
                            <span class="label">{{ t('Schedule') }}</span>
                            <div class="mt-2 grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                                <label v-for="type in scheduleTypes" :key="type" class="flex cursor-pointer items-center gap-2 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-sm capitalize">
                                    <input v-model="form.new_group.schedule_type" type="radio" :value="type" class="text-sky-400">
                                    {{ t(type) }}
                                </label>
                            </div>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                <label v-if="form.new_group.schedule_type === 'hourly'" class="space-y-2">
                                    <span class="label">{{ t('Every X hours') }}</span>
                                    <input v-model="form.new_group.schedule_config.everyHours" class="input" type="number" min="1" max="24">
                                </label>
                                <label v-if="form.new_group.schedule_type === 'daily' || form.new_group.schedule_type === 'weekly'" class="space-y-2">
                                    <span class="label">{{ t('Time') }}</span>
                                    <input v-model="form.new_group.schedule_config.time" class="input" type="time">
                                </label>
                                <label v-if="form.new_group.schedule_type === 'weekly'" class="space-y-2">
                                    <span class="label">{{ t('Day of week') }}</span>
                                    <select v-model="form.new_group.schedule_config.dayOfWeek" class="input">
                                        <option v-for="day in days" :key="day" :value="day">{{ t(day) }}</option>
                                    </select>
                                </label>
                                <label v-if="form.new_group.schedule_type === 'cron'" class="space-y-2 sm:col-span-2">
                                    <span class="label">{{ t('Cron expression') }}</span>
                                    <input v-model="form.new_group.schedule_config.expression" class="input" placeholder="0 2 * * *">
                                </label>
                                <label class="space-y-2 sm:col-span-2">
                                    <span class="label">{{ t('Timezone') }}</span>
                                    <select v-model="form.new_group.timezone" class="input">
                                        <option value="">{{ t('Application default ({timezone})', { timezone: appTimezone }) }}</option>
                                        <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                                    </select>
                                </label>
                            </div>
                            <p class="mt-3 break-words rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-300/20 dark:bg-sky-400/10 dark:text-sky-100">{{ t('Schedule summary: {summary}', { summary: newGroupSummary }) }}</p>
                            <span v-if="form.errors['new_group.schedule_config']" class="mt-2 block text-sm text-rose-300">{{ form.errors['new_group.schedule_config'] }}</span>
                        </div>

                        <div>
                            <span class="label">{{ t('On member failure') }}</span>
                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                <label v-for="policy in failurePolicies" :key="policy" class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-sm">
                                    <input v-model="form.new_group.failure_policy" type="radio" :value="policy" class="mt-1 text-sky-400">
                                    <span>
                                        <span class="block font-semibold text-white">{{ policy === 'stop' ? t('Stop at first failure') : t('Continue, report failure') }}</span>
                                        <span class="mt-1 block text-slate-300">{{ policy === 'stop' ? t('Stop the run as soon as one volume fails.') : t('Back up every volume; the run fails if any volume fails.') }}</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="label">{{ t('Notifications') }}</span>
                                <button type="button" role="switch" class="inline-flex shrink-0 items-center gap-3 rounded-full border border-white/10 bg-slate-950/60 px-3 py-2 text-sm" :aria-checked="form.new_group.notifications_enabled" :aria-label="t('Enable notifications for this group')" @click="toggleNewGroupNotifications">
                                    <span class="relative inline-flex h-6 w-11 items-center rounded-full border p-0.5 transition" :class="form.new_group.notifications_enabled ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'">
                                        <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="form.new_group.notifications_enabled ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                                    </span>
                                    <span class="font-medium">{{ form.new_group.notifications_enabled ? t('Enabled') : t('Disabled') }}</span>
                                </button>
                            </div>
                            <div v-if="notificationChannels.length" class="mt-3 grid gap-2 sm:grid-cols-2" :class="{ 'opacity-60': !form.new_group.notifications_enabled }">
                                <button v-for="channel in notificationChannels" :key="channel.id" type="button" role="switch" class="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-left text-sm" :aria-checked="form.new_group.notification_channel_ids.includes(channel.id)" :aria-label="t('Toggle notification channel')" @click="toggleNewGroupChannel(channel.id)">
                                    <span class="min-w-0">
                                        <span class="break-words font-medium text-white">{{ channel.name }}</span>
                                        <span class="mt-1 block text-slate-400">{{ channel.service }}</span>
                                    </span>
                                    <span class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full border p-1 transition" :class="form.new_group.notification_channel_ids.includes(channel.id) ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'">
                                        <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="form.new_group.notification_channel_ids.includes(channel.id) ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="!isGrouped" class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
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
                    <label class="space-y-2 sm:col-span-2">
                        <span class="label">{{ t('Timezone') }}</span>
                        <select v-model="form.timezone" class="input">
                            <option value="">{{ t('Application default ({timezone})', { timezone: appTimezone }) }}</option>
                            <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                        </select>
                        <span class="block text-xs text-slate-400">{{ t('The schedule above is evaluated in this timezone.') }}</span>
                    </label>
                </div>
                <p class="mt-4 break-words rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-300/20 dark:bg-sky-400/10 dark:text-sky-100">{{ t('Schedule summary: {summary}', { summary }) }}</p>
                <span v-if="form.errors.schedule_config" class="mt-2 block text-sm text-rose-300">{{ form.errors.schedule_config }}</span>
                <span v-if="form.errors.timezone" class="mt-2 block text-sm text-rose-300">{{ form.errors.timezone }}</span>
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
                <div class="flex items-center justify-between gap-4 rounded-xl border border-amber-300/20 bg-amber-300/10 p-3 text-sm sm:mt-7">
                    <div class="flex items-center gap-2">
                        <p class="font-medium text-white">{{ t('Stop containers before backup') }}</p>
                        <InfoTooltip :text="isDockerVolumeSource ? t('May temporarily interrupt containers using this volume.') : t('Select the containers to stop while this path is backed up. They are restarted afterwards.')" />
                    </div>
                    <button
                        type="button"
                        role="switch"
                        class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full border p-1 transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                        :class="form.stop_containers_before_backup ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'"
                        :aria-checked="form.stop_containers_before_backup"
                        :aria-label="t('Stop containers before backup')"
                        @click="toggleStopContainersBeforeBackup"
                    >
                        <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="form.stop_containers_before_backup ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                    </button>
                </div>
            </div>

            <section class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <div class="space-y-2">
                    <label for="backup_filename_template" class="label">{{ t('Archive name template') }}</label>
                    <input id="backup_filename_template" v-model="form.backup_filename_template" class="input font-mono" :placeholder="t('Default: {template}', { template: 'volumevault-{source}-run-{id}' })">
                    <p class="text-sm text-slate-300">{{ t('Optional template without extension. VolumeVault adds .tar.gz automatically.') }}</p>
                    <p class="break-all rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-300/20 dark:bg-sky-400/10 dark:text-sky-100">
                        {{ t('Preview: {filename}', { filename: archiveFilenamePreview }) }}
                    </p>
                    <p class="text-sm text-slate-400">{{ t('Available tokens: {tokens}', { tokens: '{name}, {source}, {id}, {run}, {year}, {month}, {day}, {time}, {hour}, {minute}, {second}' }) }}</p>
                    <p v-if="archiveTemplateOverwriteRisk" class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-3 text-sm text-amber-100">
                        {{ t('This template may produce the same filename for multiple backups. Previous archives can be overwritten by offen/docker-volume-backup.') }}
                    </p>
                    <span v-if="form.errors.backup_filename_template" class="text-sm text-rose-300">{{ form.errors.backup_filename_template }}</span>
                </div>
            </section>

            <section v-if="!isDockerVolumeSource && form.stop_containers_before_backup" class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-semibold">{{ t('Containers to stop') }}</h2>
                    <InfoTooltip :text="t('These containers are stopped before the backup and restarted afterwards. Containers that no longer exist or are already stopped are skipped.')" />
                </div>
                <p class="mt-1 text-sm text-slate-400">{{ t('Selection is stored by container name, so it survives container recreation.') }}</p>

                <div v-if="containerList.length" class="mt-4 grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="container in containerList"
                        :key="container.id"
                        type="button"
                        role="switch"
                        class="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-left text-sm transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                        :aria-checked="form.stop_container_names.includes(containerName(container))"
                        :aria-label="t('Toggle container')"
                        @click="toggleStopContainerName(containerName(container))"
                    >
                        <span class="min-w-0">
                            <span class="block break-all font-medium text-white">{{ containerName(container) }}</span>
                            <span class="mt-1 block break-all text-slate-400">{{ container.image }}<template v-if="container.state"> · {{ container.state }}</template></span>
                        </span>
                        <span class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full border p-1 transition" :class="form.stop_container_names.includes(containerName(container)) ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'">
                            <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="form.stop_container_names.includes(containerName(container)) ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                        </span>
                    </button>
                </div>
                <p v-else class="mt-4 rounded-xl border border-amber-300/30 bg-amber-300/10 p-3 text-sm text-amber-100">{{ t('No Docker containers found. They may be unavailable, or Docker is not reachable.') }}</p>
                <span v-if="form.errors.stop_container_names" class="mt-2 block text-sm text-rose-300">{{ form.errors.stop_container_names }}</span>
            </section>

            <section v-if="!isGrouped" class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold">{{ t('Notifications') }}</h2>
                        <InfoTooltip :text="t('Choose which notification channels this job can use. Inactive channels stay selected but do not send until reactivated.')" />
                    </div>
                    <button
                        type="button"
                        role="switch"
                        class="inline-flex shrink-0 items-center gap-3 rounded-full border border-white/10 bg-slate-950/60 px-3 py-2 text-sm transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                        :aria-checked="form.notifications_enabled"
                        :aria-label="t('Enable notifications for this job')"
                        @click="toggleJobNotifications"
                    >
                        <span class="relative inline-flex h-6 w-11 items-center rounded-full border p-0.5 transition" :class="form.notifications_enabled ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'">
                            <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="form.notifications_enabled ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                        </span>
                        <span class="font-medium">{{ form.notifications_enabled ? t('Enabled') : t('Disabled') }}</span>
                    </button>
                </div>

                <div v-if="notificationChannels.length" class="mt-4 grid gap-2 transition sm:grid-cols-2" :class="{ 'opacity-60': !form.notifications_enabled }">
                    <div v-for="channel in notificationChannels" :key="channel.id" class="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-sm">
                        <span class="min-w-0">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="break-words font-medium text-white">{{ channel.name }}</span>
                                <span v-if="channel.is_default" class="rounded-full border border-sky-300 bg-sky-50 px-2 py-0.5 text-xs text-sky-700 dark:border-sky-300/30 dark:bg-sky-400/10 dark:text-sky-100">{{ t('Default') }}</span>
                                <span v-if="!channel.is_active" class="rounded-full border border-amber-300/30 bg-amber-400/10 px-2 py-0.5 text-xs text-amber-100">{{ t('Inactive') }}</span>
                            </span>
                            <span class="mt-1 block text-slate-400">{{ channel.service }} / {{ channel.notification_level === 'info' ? t('Every backup and restore run') : t('Errors only') }}</span>
                        </span>
                        <button
                            type="button"
                            role="switch"
                            class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full border p-1 transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 disabled:cursor-not-allowed dark:focus:ring-sky-400/30"
                            :class="form.notification_channel_ids.includes(channel.id) ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'"
                            :aria-checked="form.notification_channel_ids.includes(channel.id)"
                            :aria-label="t('Toggle notification channel')"
                            @click="toggleNotificationChannel(channel.id)"
                        >
                            <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="form.notification_channel_ids.includes(channel.id) ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                        </button>
                    </div>
                </div>
                <p v-else class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-300/20 dark:bg-sky-400/10 dark:text-sky-100">{{ t('Create a notification channel first, or save this job without notifications.') }}</p>
                <span v-if="form.errors.notifications_enabled" class="mt-2 block text-sm text-rose-300">{{ form.errors.notifications_enabled }}</span>
                <span v-if="form.errors.notification_channel_ids" class="mt-2 block text-sm text-rose-300">{{ form.errors.notification_channel_ids }}</span>
            </section>

            <section class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <div class="space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold">{{ t('Alert settings') }}</h2>
                        <p class="mt-1 text-sm text-slate-400">{{ t('Control proactive alert notifications and optional per-job thresholds.') }}</p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <button
                            type="button"
                            role="switch"
                            class="inline-flex items-center justify-between gap-4 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-left text-sm transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:hover:bg-white/[0.03] dark:focus:ring-sky-400/30"
                            :aria-checked="form.alert_notifications_enabled"
                            :aria-label="t('Alert notifications')"
                            @click="toggleAlertNotifications"
                        >
                            <span class="inline-flex items-center gap-2">
                                <span class="font-semibold text-white">{{ t('Alert notifications') }}</span>
                                <InfoTooltip :text="t('Send alert notifications to this job\'s selected channels.')" />
                            </span>
                            <span class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border p-0.5 transition" :class="form.alert_notifications_enabled ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'">
                                <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="form.alert_notifications_enabled ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                            </span>
                        </button>

                        <button
                            type="button"
                            role="switch"
                            class="inline-flex items-center justify-between gap-4 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-left text-sm transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:hover:bg-white/[0.03] dark:focus:ring-sky-400/30"
                            :aria-checked="form.use_custom_alert_settings"
                            :aria-label="t('Use custom alert settings')"
                            @click="toggleCustomAlertSettings"
                        >
                            <span class="inline-flex items-center gap-2">
                                <span class="font-semibold text-white">{{ t('Use custom alert settings') }}</span>
                                <InfoTooltip :text="t('Override global alert thresholds for this job.')" />
                            </span>
                            <span class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border p-0.5 transition" :class="form.use_custom_alert_settings ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'">
                                <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="form.use_custom_alert_settings ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                            </span>
                        </button>
                    </div>

                    <p v-if="!form.use_custom_alert_settings" class="rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-300/20 dark:bg-sky-400/10 dark:text-sky-100">{{ t('This job uses the global alert configuration.') }}</p>

                    <div v-else class="space-y-3">
                        <article v-for="(alertConfig, index) in form.alert_configs" :key="alertConfig.alert_rule_id" class="rounded-xl border border-white/10 bg-slate-950/60 p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="font-semibold text-white">{{ t(alertRuleLabel(alertConfig.type)) }}</h3>
                                    <p class="mt-1 text-sm text-slate-400">{{ alertConfig.type }}</p>
                                </div>
                                <button
                                    type="button"
                                    role="switch"
                                    class="inline-flex shrink-0 items-center gap-3 rounded-full border border-white/10 px-3 py-2 text-sm transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                                    :aria-checked="alertConfig.enabled"
                                    :aria-label="t('Enable alert rule')"
                                    @click="toggleAlertConfigEnabled(index)"
                                >
                                    <span class="relative inline-flex h-6 w-11 items-center rounded-full border p-0.5 transition" :class="alertConfig.enabled ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'">
                                        <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="alertConfig.enabled ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                                    </span>
                                    <span>{{ alertConfig.enabled ? t('Enabled') : t('Disabled') }}</span>
                                </button>
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-2">
                                <label class="space-y-2">
                                    <span class="label">{{ t('Cooldown') }}</span>
                                    <input v-model.number="alertConfig.config.cooldown_minutes" class="input" type="number" min="0">
                                    <span v-if="alertConfigError(index, 'cooldown_minutes')" class="text-sm text-rose-300">{{ alertConfigError(index, 'cooldown_minutes') }}</span>
                                </label>
                                <button
                                    type="button"
                                    role="switch"
                                    class="inline-flex items-center justify-between gap-4 rounded-xl border border-white/10 bg-white p-3 text-left text-sm transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:bg-slate-900/80 dark:hover:bg-white/[0.03] dark:focus:ring-sky-400/30"
                                    :aria-checked="alertConfig.config.reminder_enabled"
                                    :aria-label="t('Reminder notifications')"
                                    @click="toggleAlertReminder(index)"
                                >
                                    <span class="inline-flex items-center gap-2">
                                        <span class="font-medium text-white">{{ t('Reminder notifications') }}</span>
                                        <InfoTooltip :text="t('Send repeated notifications while the alert stays active.')" />
                                    </span>
                                    <span class="inline-flex shrink-0 items-center gap-3">
                                        <span class="relative inline-flex h-6 w-11 items-center rounded-full border p-0.5 transition" :class="alertConfig.config.reminder_enabled ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'">
                                            <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="alertConfig.config.reminder_enabled ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                                        </span>
                                        <span class="font-medium text-white">{{ alertConfig.config.reminder_enabled ? t('Enabled') : t('Disabled') }}</span>
                                    </span>
                                    <span v-if="alertConfigError(index, 'reminder_enabled')" class="text-sm text-rose-300">{{ alertConfigError(index, 'reminder_enabled') }}</span>
                                </button>
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-2">
                                <label v-if="alertConfig.type === 'backup_too_old'" class="space-y-2">
                                    <span class="label">{{ t('Days without success') }}</span>
                                    <input v-model.number="alertConfig.config.backup_too_old_days" class="input" type="number" min="1">
                                    <span v-if="alertConfigError(index, 'backup_too_old_days')" class="text-sm text-rose-300">{{ alertConfigError(index, 'backup_too_old_days') }}</span>
                                </label>
                                <label v-if="alertConfig.type === 'job_never_succeeded'" class="space-y-2">
                                    <span class="label">{{ t('Minimum finished runs') }}</span>
                                    <input v-model.number="alertConfig.config.job_never_succeeded_min_runs" class="input" type="number" min="1">
                                    <span v-if="alertConfigError(index, 'job_never_succeeded_min_runs')" class="text-sm text-rose-300">{{ alertConfigError(index, 'job_never_succeeded_min_runs') }}</span>
                                </label>
                                <label v-if="alertConfig.type === 'job_in_error_too_long'" class="space-y-2">
                                    <span class="label">{{ t('Days in error') }}</span>
                                    <input v-model.number="alertConfig.config.job_in_error_days" class="input" type="number" min="1">
                                    <span v-if="alertConfigError(index, 'job_in_error_days')" class="text-sm text-rose-300">{{ alertConfigError(index, 'job_in_error_days') }}</span>
                                </label>
                                <template v-if="alertConfig.type === 'backup_size_out_of_range'">
                                    <label class="space-y-2">
                                        <span class="label">{{ t('Minimum backup size') }}</span>
                                        <span class="flex gap-2">
                                            <input
                                                class="input min-w-0"
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                :value="sizeInputValue(alertConfig.config, 'backup_size_out_of_range_min_bytes', selectedAlertSizeUnit(alertConfig, 'backup_size_out_of_range_min_bytes'))"
                                                @input="updateSizeThreshold(alertConfig.config, 'backup_size_out_of_range_min_bytes', inputValue($event), selectedAlertSizeUnit(alertConfig, 'backup_size_out_of_range_min_bytes'))"
                                            >
                                            <select class="input w-24 shrink-0" :value="selectedAlertSizeUnit(alertConfig, 'backup_size_out_of_range_min_bytes')" @change="updateAlertSizeUnit(alertConfig, 'backup_size_out_of_range_min_bytes', selectValue($event))">
                                                <option v-for="unit in sizeUnits" :key="unit.label" :value="unit.label">{{ unit.label }}</option>
                                            </select>
                                        </span>
                                        <span v-if="alertConfigError(index, 'backup_size_out_of_range_min_bytes')" class="text-sm text-rose-300">{{ alertConfigError(index, 'backup_size_out_of_range_min_bytes') }}</span>
                                    </label>
                                    <label class="space-y-2">
                                        <span class="label">{{ t('Maximum backup size') }}</span>
                                        <span class="flex gap-2">
                                            <input
                                                class="input min-w-0"
                                                type="number"
                                                min="1"
                                                step="0.01"
                                                :value="sizeInputValue(alertConfig.config, 'backup_size_out_of_range_max_bytes', selectedAlertSizeUnit(alertConfig, 'backup_size_out_of_range_max_bytes'))"
                                                @input="updateSizeThreshold(alertConfig.config, 'backup_size_out_of_range_max_bytes', inputValue($event), selectedAlertSizeUnit(alertConfig, 'backup_size_out_of_range_max_bytes'))"
                                            >
                                            <select class="input w-24 shrink-0" :value="selectedAlertSizeUnit(alertConfig, 'backup_size_out_of_range_max_bytes')" @change="updateAlertSizeUnit(alertConfig, 'backup_size_out_of_range_max_bytes', selectValue($event))">
                                                <option v-for="unit in sizeUnits" :key="unit.label" :value="unit.label">{{ unit.label }}</option>
                                            </select>
                                        </span>
                                        <span v-if="alertConfigError(index, 'backup_size_out_of_range_max_bytes')" class="text-sm text-rose-300">{{ alertConfigError(index, 'backup_size_out_of_range_max_bytes') }}</span>
                                    </label>
                                </template>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <div class="space-y-3">
                    <span class="label">{{ t('File filtering') }}</span>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 text-sm">
                            <input v-model="form.backup_filter_mode" type="radio" value="include" class="mt-1 text-sky-400">
                            <span>
                                <span class="block font-semibold text-white">{{ t('Include only (simple)') }}</span>
                                <span class="mt-1 block text-slate-300">{{ t('Back up only the folders/files you list; everything else is skipped.') }}</span>
                            </span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 text-sm">
                            <input v-model="form.backup_filter_mode" type="radio" value="exclude" class="mt-1 text-sky-400">
                            <span>
                                <span class="block font-semibold text-white">{{ t('Exclude with regex (advanced)') }}</span>
                                <span class="mt-1 block text-slate-300">{{ t('Back up everything except paths matching a Go regular expression.') }}</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div v-if="form.backup_filter_mode === 'include'" class="mt-4 space-y-2">
                    <label for="backup_include_paths" class="label">{{ t('Folders or files to include') }}</label>
                    <input id="backup_include_paths" v-model="form.backup_include_paths" type="text" class="input font-mono text-sm" :placeholder="t('For example: {example}', { example: 'Backups, config/app.conf' })">
                    <p class="text-sm text-slate-300">{{ t('Comma-separated folders or files to keep, relative to the volume root. Leave empty to back up everything.') }}</p>
                    <p class="text-sm text-slate-400">{{ t('Paths are relative to the volume root: use "Backups", not "/_data/Backups".') }}</p>
                    <span v-if="form.errors.backup_include_paths" class="text-sm text-rose-300">{{ form.errors.backup_include_paths }}</span>
                </div>

                <div v-else class="mt-4 space-y-2">
                    <label for="backup_exclude_regexp" class="label">{{ t('Exclude files') }}</label>
                    <textarea id="backup_exclude_regexp" v-model="form.backup_exclude_regexp" class="input min-h-24 font-mono text-sm" :placeholder="t('Optional regex, for example: {example}', { example: '\\.log$' })" />
                    <p class="text-sm text-slate-300">{{ t('Files whose full path matches this Go regular expression are excluded from the archive. Leave empty to include everything.') }}</p>
                    <span v-if="form.errors.backup_exclude_regexp" class="text-sm text-rose-300">{{ form.errors.backup_exclude_regexp }}</span>

                    <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-300/20 dark:bg-sky-400/10 dark:text-sky-50">
                        <p class="font-medium">{{ t('Not comfortable with regex? Start with one of these examples:') }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button v-for="example in excludeExamples" :key="example.value" type="button" class="max-w-full break-all rounded-lg border border-sky-200 bg-sky-100 px-3 py-2 text-left font-mono text-xs text-sky-800 hover:bg-sky-200 dark:border-sky-200/30 dark:bg-sky-300/10 dark:text-sky-50 dark:hover:bg-sky-300/20" @click="form.backup_exclude_regexp = example.value">
                                {{ t(example.label) }}: {{ example.value }}
                            </button>
                        </div>
                        <p class="mt-3 text-sky-700 dark:text-sky-100">{{ t('Ask for help if you are unsure: describe what should be ignored, such as log files or cache folders, and VolumeVault can guide the regex.') }}</p>
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button class="btn-primary" :disabled="form.processing || !canSubmit">{{ editing ? t('Update job') : t('Create job') }}</button>
                <Link href="/backup-jobs" class="btn-secondary">{{ t('Cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
