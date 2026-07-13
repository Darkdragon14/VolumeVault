<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionIcon from '@/Components/ActionIcon.vue';
import InfoTooltip from '@/Components/InfoTooltip.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/i18n';

const props = defineProps<{
    group: any | null;
    notificationChannels: any[];
    timezones: string[];
    appTimezone: string;
}>();

const { t } = useI18n();
const editing = computed(() => Boolean(props.group));
const scheduleTypes = ['hourly', 'daily', 'weekly', 'cron'];
const days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
const failurePolicies = ['continue', 'stop'];

const form = useForm({
    name: props.group?.name || '',
    schedule_type: props.group?.schedule_type || 'daily',
    schedule_config: props.group?.schedule_config || { time: '02:00', everyHours: 6, dayOfWeek: 'sunday', expression: '0 2 * * *' },
    timezone: props.group?.timezone || '',
    failure_policy: props.group?.failure_policy || 'continue',
    notifications_enabled: props.group?.notifications_enabled ?? true,
    notification_channel_ids: (props.group?.notification_channel_ids || []) as number[],
});

const members = computed(() => props.group?.members || []);

const summary = computed(() => {
    if (form.schedule_type === 'hourly') return t('Every {hours} hours', { hours: form.schedule_config.everyHours || 1 });
    if (form.schedule_type === 'daily') return t('Every day at {time}', { time: form.schedule_config.time || '02:00' });
    if (form.schedule_type === 'weekly') return t('Every {day} at {time}', { day: t(form.schedule_config.dayOfWeek || 'sunday'), time: form.schedule_config.time || '03:00' });
    return t('Cron: {expression}', { expression: form.schedule_config.expression || '' });
});

const toggleNotifications = () => {
    form.notifications_enabled = !form.notifications_enabled;
};

const toggleChannel = (id: number) => {
    const ids = form.notification_channel_ids as number[];
    form.notification_channel_ids = ids.includes(id) ? ids.filter((channelId) => channelId !== id) : [...ids, id];
};

const pauseMember = (id: number) => router.post(`/backup-jobs/${id}/pause`);
const resumeMember = (id: number) => router.post(`/backup-jobs/${id}/resume`);

const submit = () => {
    if (editing.value) {
        form.put(`/backup-groups/${props.group.id}`);
        return;
    }

    form.post('/backup-groups');
};
</script>

<template>
    <Head :title="editing ? t('Edit backup group') : t('New backup group')" />
    <AppLayout :title="editing ? t('Edit backup group') : t('New backup group')" :subtitle="t('The group owns the schedule and notifications for its member jobs and reports a single outcome.')">
        <form class="card max-w-4xl space-y-6 p-4 sm:p-6" @submit.prevent="submit">
            <label class="block space-y-2">
                <span class="label">{{ t('Group name') }}</span>
                <input v-model="form.name" class="input" required placeholder="Nightly backups">
                <span v-if="form.errors.name" class="text-sm text-rose-300">{{ form.errors.name }}</span>
            </label>

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
                    <label class="space-y-2 sm:col-span-2">
                        <span class="label">{{ t('Timezone') }}</span>
                        <select v-model="form.timezone" class="input">
                            <option value="">{{ t('Application default ({timezone})', { timezone: appTimezone }) }}</option>
                            <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                        </select>
                    </label>
                </div>
                <p class="mt-4 break-words rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-300/20 dark:bg-sky-400/10 dark:text-sky-100">{{ t('Schedule summary: {summary}', { summary }) }}</p>
                <span v-if="form.errors.schedule_config" class="mt-2 block text-sm text-rose-300">{{ form.errors.schedule_config }}</span>
            </section>

            <section class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-semibold">{{ t('On member failure') }}</h2>
                    <InfoTooltip :text="t('Choose whether the run continues after a volume fails or stops immediately. Either way the group reports failure if any volume fails.')" />
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label v-for="policy in failurePolicies" :key="policy" class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-sm">
                        <input v-model="form.failure_policy" type="radio" :value="policy" class="mt-1 text-sky-400">
                        <span>
                            <span class="block font-semibold text-white">{{ policy === 'stop' ? t('Stop at first failure') : t('Continue, report failure') }}</span>
                            <span class="mt-1 block text-slate-300">{{ policy === 'stop' ? t('Stop the run as soon as one volume fails.') : t('Back up every volume; the run fails if any volume fails.') }}</span>
                        </span>
                    </label>
                </div>
            </section>

            <section class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold">{{ t('Notifications') }}</h2>
                        <InfoTooltip :text="t('The group sends one start notification and one success/fail notification for the whole set of volumes.')" />
                    </div>
                    <button type="button" role="switch" class="inline-flex shrink-0 items-center gap-3 rounded-full border border-white/10 bg-slate-950/60 px-3 py-2 text-sm" :aria-checked="form.notifications_enabled" :aria-label="t('Enable notifications for this group')" @click="toggleNotifications">
                        <span class="relative inline-flex h-6 w-11 items-center rounded-full border p-0.5 transition" :class="form.notifications_enabled ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'">
                            <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="form.notifications_enabled ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                        </span>
                        <span class="font-medium">{{ form.notifications_enabled ? t('Enabled') : t('Disabled') }}</span>
                    </button>
                </div>
                <div v-if="notificationChannels.length" class="mt-4 grid gap-2 sm:grid-cols-2" :class="{ 'opacity-60': !form.notifications_enabled }">
                    <button v-for="channel in notificationChannels" :key="channel.id" type="button" role="switch" class="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-left text-sm" :aria-checked="form.notification_channel_ids.includes(channel.id)" :aria-label="t('Toggle notification channel')" @click="toggleChannel(channel.id)">
                        <span class="min-w-0">
                            <span class="break-words font-medium text-white">{{ channel.name }}</span>
                            <span class="mt-1 block text-slate-400">{{ channel.service }}</span>
                        </span>
                        <span class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full border p-1 transition" :class="form.notification_channel_ids.includes(channel.id) ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'">
                            <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="form.notification_channel_ids.includes(channel.id) ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                        </span>
                    </button>
                </div>
                <p v-else class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-300/20 dark:bg-sky-400/10 dark:text-sky-100">{{ t('Create a notification channel first, or save this group without notifications.') }}</p>
            </section>

            <section v-if="editing" class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <h2 class="mb-1 text-lg font-semibold">{{ t('Member jobs') }}</h2>
                <p class="mb-4 text-sm text-slate-400">{{ t('Attach a job to this group from the backup job form. Disable a member to skip its volume; edit it to detach it.') }}</p>
                <div v-if="members.length" class="divide-y divide-white/10 rounded-xl border border-white/10">
                    <div v-for="member in members" :key="member.id" class="flex items-center justify-between gap-3 p-3 text-sm">
                        <div class="min-w-0">
                            <p class="break-words font-medium text-white">{{ member.name }}</p>
                            <p class="mt-1 break-all text-slate-400">{{ member.source_label }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <StatusBadge :status="member.status" />
                            <ActionIcon v-if="member.status === 'paused' || member.status === 'error'" :label="t('Enable')" icon="play" @click="resumeMember(member.id)" />
                            <ActionIcon v-else :label="t('Disable')" icon="pause" :disabled="member.status === 'running'" @click="pauseMember(member.id)" />
                            <ActionIcon :label="t('Edit')" icon="edit" :href="`/backup-jobs/${member.id}/edit`" />
                        </div>
                    </div>
                </div>
                <p v-else class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-3 text-sm text-amber-100">{{ t('No jobs in this group yet. Create or edit a backup job and attach it to this group.') }}</p>
            </section>

            <div class="flex flex-wrap gap-3">
                <button class="btn-primary" :disabled="form.processing">{{ editing ? t('Update group') : t('Create group') }}</button>
                <Link href="/backup-groups" class="btn-secondary">{{ t('Cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
