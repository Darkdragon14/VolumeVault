<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import InfoTooltip from '@/Components/InfoTooltip.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from '@/i18n';
import { bestSizeUnit, bytesToUnitValue, sizeUnits, type SizeUnit, unitValueToBytes } from '@/Composables/useFormatBytes';

const props = defineProps<{ rules: any[]; notificationChannels: any[] }>();

const { t } = useI18n();
const sizeUnitSelections = ref<Record<string, SizeUnit>>({});
const form = useForm({
    rules: props.rules.map((rule) => ({
        id: rule.id,
        type: rule.type,
        enabled: rule.enabled,
        notification_channel_ids: rule.notification_channel_ids || [],
        config: { ...rule.config },
    })),
});

const alertRuleLabel = (type: string) => ({
    backup_too_old: 'Backup too old',
    job_never_succeeded: 'Job never succeeded',
    job_in_error_too_long: 'Job in error too long',
    backup_size_out_of_range: 'Backup size out of range',
    destination_storage_limit: 'Destination storage limit',
    agent_offline: 'Agent offline',
}[type] || type);
const alertRuleDescription = (type: string) => ({
    backup_too_old: 'Warn when a job has not produced a successful backup recently.',
    job_never_succeeded: 'Detect jobs that keep finishing without any successful backup.',
    job_in_error_too_long: 'Escalate jobs that stay in error longer than expected.',
    backup_size_out_of_range: 'Detect unusually small or large successful backup archives.',
    destination_storage_limit: 'Warn when a backup destination exceeds its configured absolute storage thresholds.',
    agent_offline: 'Alert when an enrolled agent stops sending heartbeats. Agents in maintenance are excluded.',
}[type] || '');
const sizeUnitKey = (id: number, key: string) => `${id}.${key}`;
const selectedSizeUnit = (rule: any, key: string): SizeUnit => sizeUnitSelections.value[sizeUnitKey(rule.id, key)] || bestSizeUnit(rule.config[key]);
const sizeInputValue = (config: any, key: string, unit: SizeUnit) => bytesToUnitValue(config[key], unit);
const inputValue = (event: Event) => (event.target as HTMLInputElement).value;
const selectValue = (event: Event) => (event.target as HTMLSelectElement).value as SizeUnit;
const updateSizeUnit = (rule: any, key: string, unit: SizeUnit) => sizeUnitSelections.value[sizeUnitKey(rule.id, key)] = unit;
const updateSizeThreshold = (config: any, key: string, value: string, unit: SizeUnit) => config[key] = unitValueToBytes(value, unit);
const ruleConfigError = (index: number, key: string) => form.errors[`rules.${index}.config.${key}`];

const sizeKeys = ['backup_size_out_of_range_min_bytes', 'backup_size_out_of_range_max_bytes'];
for (const rule of form.rules) {
    for (const key of sizeKeys) {
        const sizeKey = sizeUnitKey(rule.id, key);
        if (!(sizeKey in sizeUnitSelections.value)) {
            sizeUnitSelections.value[sizeKey] = bestSizeUnit(rule.config[key]);
        }
    }
}

const toggleRuleNotificationChannel = (rule: any, id: number) => {
    const ids = rule.notification_channel_ids as number[];
    rule.notification_channel_ids = ids.includes(id) ? ids.filter((channelId) => channelId !== id) : [...ids, id];
};
const submit = () => form.put('/alerts/settings');
</script>

<template>
    <Head :title="t('Alert settings')" />
    <AppLayout :title="t('Alert settings')" :subtitle="t('Configure global alert rules and reminder behavior.')">
        <template #actions>
            <Link href="/alerts" class="btn-secondary">{{ t('Back to alerts') }}</Link>
        </template>

        <form class="space-y-6" @submit.prevent="submit">
            <section v-for="(rule, index) in form.rules" :key="rule.id" class="card space-y-5 p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold">{{ t(alertRuleLabel(rule.type)) }}</h2>
                        <p class="mt-1 text-sm text-slate-400">{{ t(alertRuleDescription(rule.type)) }}</p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        class="inline-flex shrink-0 items-center gap-3 rounded-full border border-white/10 bg-slate-950/60 px-3 py-2 text-sm transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                        :aria-checked="rule.enabled"
                        :aria-label="t('Enable alert rule')"
                        @click="rule.enabled = !rule.enabled"
                    >
                        <span class="relative inline-flex h-6 w-11 items-center rounded-full border p-0.5 transition" :class="rule.enabled ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'">
                            <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="rule.enabled ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                        </span>
                        <span class="font-medium">{{ rule.enabled ? t('Enabled') : t('Disabled') }}</span>
                    </button>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <label class="space-y-2">
                        <span class="label">{{ t('Check interval') }}</span>
                        <input v-model.number="rule.config.check_interval_minutes" class="input" type="number" min="1">
                        <p class="text-xs text-slate-500">{{ t('Minutes between checks for this alert type.') }}</p>
                        <span v-if="ruleConfigError(index, 'check_interval_minutes')" class="text-sm text-rose-300">{{ ruleConfigError(index, 'check_interval_minutes') }}</span>
                    </label>
                    <label class="space-y-2">
                        <span class="label">{{ t('Cooldown') }}</span>
                        <input v-model.number="rule.config.cooldown_minutes" class="input" type="number" min="0">
                        <p class="text-xs text-slate-500">{{ t('Minutes between reminders.') }}</p>
                        <span v-if="ruleConfigError(index, 'cooldown_minutes')" class="text-sm text-rose-300">{{ ruleConfigError(index, 'cooldown_minutes') }}</span>
                    </label>
                    <button
                        type="button"
                        role="switch"
                        class="inline-flex items-center justify-between gap-4 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-left text-sm transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:hover:bg-white/[0.03] dark:focus:ring-sky-400/30"
                        :aria-checked="rule.config.reminder_enabled"
                        :aria-label="t('Reminder notifications')"
                        @click="rule.config.reminder_enabled = !rule.config.reminder_enabled"
                    >
                        <span class="inline-flex items-center gap-2">
                            <span class="font-medium text-white">{{ t('Reminder notifications') }}</span>
                            <InfoTooltip :text="t('Send repeated notifications while the alert stays active.')" />
                        </span>
                        <span class="inline-flex shrink-0 items-center gap-3">
                            <span class="relative inline-flex h-6 w-11 items-center rounded-full border p-0.5 transition" :class="rule.config.reminder_enabled ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'">
                                <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="rule.config.reminder_enabled ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                            </span>
                            <span class="font-medium text-white">{{ rule.config.reminder_enabled ? t('Enabled') : t('Disabled') }}</span>
                        </span>
                        <span v-if="ruleConfigError(index, 'reminder_enabled')" class="text-sm text-rose-300">{{ ruleConfigError(index, 'reminder_enabled') }}</span>
                    </button>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <label v-if="rule.type === 'agent_offline'" class="space-y-2">
                        <span class="label">{{ t('Minutes offline') }}</span>
                        <input v-model.number="rule.config.agent_offline_minutes" class="input" type="number" min="1">
                        <span v-if="ruleConfigError(index, 'agent_offline_minutes')" class="text-sm text-rose-300">{{ ruleConfigError(index, 'agent_offline_minutes') }}</span>
                    </label>
                    <label v-if="rule.type === 'backup_too_old'" class="space-y-2">
                        <span class="label">{{ t('Days without success') }}</span>
                        <input v-model.number="rule.config.backup_too_old_days" class="input" type="number" min="1">
                        <span v-if="ruleConfigError(index, 'backup_too_old_days')" class="text-sm text-rose-300">{{ ruleConfigError(index, 'backup_too_old_days') }}</span>
                    </label>
                    <label v-if="rule.type === 'job_never_succeeded'" class="space-y-2">
                        <span class="label">{{ t('Minimum finished runs') }}</span>
                        <input v-model.number="rule.config.job_never_succeeded_min_runs" class="input" type="number" min="1">
                        <span v-if="ruleConfigError(index, 'job_never_succeeded_min_runs')" class="text-sm text-rose-300">{{ ruleConfigError(index, 'job_never_succeeded_min_runs') }}</span>
                    </label>
                    <label v-if="rule.type === 'job_in_error_too_long'" class="space-y-2">
                        <span class="label">{{ t('Days in error') }}</span>
                        <input v-model.number="rule.config.job_in_error_days" class="input" type="number" min="1">
                        <span v-if="ruleConfigError(index, 'job_in_error_days')" class="text-sm text-rose-300">{{ ruleConfigError(index, 'job_in_error_days') }}</span>
                    </label>
                    <template v-if="rule.type === 'backup_size_out_of_range'">
                        <label class="space-y-2">
                            <span class="label">{{ t('Minimum backup size') }}</span>
                            <span class="flex gap-2">
                                <input
                                    class="input min-w-0"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    :value="sizeInputValue(rule.config, 'backup_size_out_of_range_min_bytes', selectedSizeUnit(rule, 'backup_size_out_of_range_min_bytes'))"
                                    @input="updateSizeThreshold(rule.config, 'backup_size_out_of_range_min_bytes', inputValue($event), selectedSizeUnit(rule, 'backup_size_out_of_range_min_bytes'))"
                                >
                                <select class="input w-24 shrink-0" :value="selectedSizeUnit(rule, 'backup_size_out_of_range_min_bytes')" @change="updateSizeUnit(rule, 'backup_size_out_of_range_min_bytes', selectValue($event))">
                                    <option v-for="unit in sizeUnits" :key="unit.label" :value="unit.label">{{ unit.label }}</option>
                                </select>
                            </span>
                            <span v-if="ruleConfigError(index, 'backup_size_out_of_range_min_bytes')" class="text-sm text-rose-300">{{ ruleConfigError(index, 'backup_size_out_of_range_min_bytes') }}</span>
                        </label>
                        <label class="space-y-2">
                            <span class="label">{{ t('Maximum backup size') }}</span>
                            <span class="flex gap-2">
                                <input
                                    class="input min-w-0"
                                    type="number"
                                    min="1"
                                    step="0.01"
                                    :value="sizeInputValue(rule.config, 'backup_size_out_of_range_max_bytes', selectedSizeUnit(rule, 'backup_size_out_of_range_max_bytes'))"
                                    @input="updateSizeThreshold(rule.config, 'backup_size_out_of_range_max_bytes', inputValue($event), selectedSizeUnit(rule, 'backup_size_out_of_range_max_bytes'))"
                                >
                                <select class="input w-24 shrink-0" :value="selectedSizeUnit(rule, 'backup_size_out_of_range_max_bytes')" @change="updateSizeUnit(rule, 'backup_size_out_of_range_max_bytes', selectValue($event))">
                                    <option v-for="unit in sizeUnits" :key="unit.label" :value="unit.label">{{ unit.label }}</option>
                                </select>
                            </span>
                            <span v-if="ruleConfigError(index, 'backup_size_out_of_range_max_bytes')" class="text-sm text-rose-300">{{ ruleConfigError(index, 'backup_size_out_of_range_max_bytes') }}</span>
                        </label>
                    </template>
                </div>

                <section v-if="['destination_storage_limit', 'agent_offline'].includes(rule.type)" class="rounded-xl border border-white/10 bg-slate-950/60 p-4">
                    <div class="flex flex-col gap-1">
                        <h3 class="font-semibold text-white">{{ t(rule.type === 'agent_offline' ? 'Agent alert channels' : 'Destination alert channels') }}</h3>
                        <p class="text-sm text-slate-400">{{ t(rule.type === 'agent_offline' ? 'Choose which channels receive agent offline and recovery notifications. If none are selected, alerts stay visible only in VolumeVault.' : 'Choose which channels receive destination storage limit notifications. If none are selected, alerts stay visible only in VolumeVault.') }}</p>
                    </div>
                    <div v-if="notificationChannels.length" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <button
                            v-for="channel in notificationChannels"
                            :key="channel.id"
                            type="button"
                            class="rounded-xl border p-3 text-left text-sm transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                            :class="rule.notification_channel_ids.includes(channel.id) ? 'border-sky-300/60 bg-sky-400/10 text-sky-50' : 'border-white/10 bg-white/[0.03] text-slate-300 hover:bg-white/[0.06]'"
                            :aria-pressed="rule.notification_channel_ids.includes(channel.id)"
                            @click="toggleRuleNotificationChannel(rule, channel.id)"
                        >
                            <span class="block font-medium text-white">{{ channel.name }}</span>
                            <span class="mt-1 block text-xs text-slate-400">{{ channel.service }} - {{ channel.is_active ? t('Enabled') : t('Disabled') }}</span>
                        </button>
                    </div>
                    <p v-else class="mt-4 rounded-xl border border-amber-300/30 bg-amber-300/10 p-3 text-sm text-amber-100">{{ t('Create a notification channel before enabling external notifications for this alert.') }}</p>
                </section>
            </section>

            <div class="flex flex-wrap gap-3">
                <button class="btn-primary" :disabled="form.processing">{{ t('Save alert settings') }}</button>
                <Link href="/alerts" class="btn-secondary">{{ t('Cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
