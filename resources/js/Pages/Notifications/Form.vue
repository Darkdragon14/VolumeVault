<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from '@/i18n';

const props = defineProps<{
    channel: any | null;
    services: string[];
    jobs: any[];
}>();

const editing = computed(() => Boolean(props.channel));
const { t } = useI18n();
const useCustomMessage = ref(Boolean(props.channel?.title_template || props.channel?.body_template));
const templateTokens = '{{ job }}, {{ volume }}, {{ destination }}, {{ status }}, {{ trigger }}, {{ duration }}, {{ error }}';
const titleTemplatePlaceholder = 'VolumeVault: {{ status }} backup for {{ job }}';
const bodyTemplatePlaceholder = 'Job: {{ job }}\nVolume: {{ volume }}\nDestination: {{ destination }}\nStatus: {{ status }}\nDuration: {{ duration }}';
const form = useForm({
    name: props.channel?.name || '',
    service: props.channel?.service || 'discord',
    notification_level: props.channel?.notification_level || 'error',
    scope: props.channel?.scope || 'all',
    title_template: props.channel?.title_template || '',
    body_template: props.channel?.body_template || '',
    is_active: props.channel?.is_active ?? true,
    backup_job_ids: props.channel?.backup_job_ids || [],
    config: {} as Record<string, any>,
});

const serviceHelp = computed(() => {
    if (form.service === 'discord') return 'Paste the Discord webhook URL from Channel settings > Integrations > Webhooks.';
    if (form.service === 'telegram') return 'Create a bot with BotFather, then add the bot to your chat or channel.';
    if (form.service === 'ntfy') return 'Pick a private topic name. Public ntfy.sh topics can be guessed by others.';
    if (form.service === 'gotify') return 'Create an application token in Gotify and paste your Gotify host.';
    if (form.service === 'smtp') return 'Use your SMTP server details. The password is encrypted after saving.';
    return 'Paste a complete Shoutrrr URL for any supported service.';
});

watch(() => form.service, () => {
    form.config = {};
});

const toggleJob = (id: number) => {
    const ids = form.backup_job_ids as number[];
    form.backup_job_ids = ids.includes(id) ? ids.filter((jobId) => jobId !== id) : [...ids, id];
};

const submit = () => {
    if (!useCustomMessage.value) {
        form.title_template = '';
        form.body_template = '';
    }

    if (editing.value) {
        form.put(`/notifications/${props.channel.id}`);
        return;
    }

    form.post('/notifications');
};
</script>

<template>
    <Head :title="editing ? t('Edit notification') : t('New notification')" />
    <AppLayout :title="editing ? t('Edit notification') : t('New notification')" :subtitle="t('Configure one alert channel and decide which backup results it receives.')">
        <form class="card max-w-4xl space-y-6 p-4 sm:p-6" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2">
                    <span class="label">{{ t('Channel name') }}</span>
                    <input v-model="form.name" class="input" required placeholder="Discord homelab alerts">
                    <span v-if="form.errors.name" class="text-sm text-rose-300">{{ form.errors.name }}</span>
                </label>

                <label class="space-y-2">
                    <span class="label">{{ t('Notification service') }}</span>
                    <select v-model="form.service" class="input">
                        <option v-for="service in services" :key="service" :value="service">{{ service }}</option>
                    </select>
                </label>
            </div>

            <section class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold">Guided setup</h2>
                    <p class="mt-1 text-sm text-slate-400">{{ serviceHelp }}</p>
                    <p v-if="editing" class="mt-2 text-xs text-slate-400">Leave setup fields empty to keep the saved encrypted Shoutrrr URL.</p>
                </div>

                <div v-if="form.service === 'discord'" class="grid gap-4 sm:grid-cols-2">
                    <label class="space-y-2 sm:col-span-2">
                        <span class="label">Discord webhook URL</span>
                        <input v-model="form.config.webhook_url" class="input" :required="!editing" placeholder="https://discord.com/api/webhooks/...">
                    </label>
                    <label class="space-y-2">
                        <span class="label">Bot username</span>
                        <input v-model="form.config.username" class="input" placeholder="VolumeVault">
                    </label>
                </div>

                <div v-else-if="form.service === 'telegram'" class="grid gap-4 sm:grid-cols-2">
                    <label class="space-y-2">
                        <span class="label">Bot token</span>
                        <input v-model="form.config.token" class="input" :required="!editing" autocomplete="off" placeholder="123456:ABC...">
                    </label>
                    <label class="space-y-2">
                        <span class="label">Chats or channels</span>
                        <input v-model="form.config.chats" class="input" :required="!editing" placeholder="@mychannel or -1001234567890">
                    </label>
                </div>

                <div v-else-if="form.service === 'ntfy'" class="grid gap-4 sm:grid-cols-2">
                    <label class="space-y-2">
                        <span class="label">Ntfy host</span>
                        <input v-model="form.config.host" class="input" placeholder="ntfy.sh">
                    </label>
                    <label class="space-y-2">
                        <span class="label">Topic</span>
                        <input v-model="form.config.topic" class="input" :required="!editing" placeholder="volumevault-private-topic">
                    </label>
                    <label class="space-y-2">
                        <span class="label">Username</span>
                        <input v-model="form.config.username" class="input" autocomplete="off" placeholder="Optional">
                    </label>
                    <label class="space-y-2">
                        <span class="label">Password</span>
                        <PasswordInput v-model="form.config.password" autocomplete="new-password" placeholder="Optional" />
                    </label>
                </div>

                <div v-else-if="form.service === 'gotify'" class="grid gap-4 sm:grid-cols-2">
                    <label class="space-y-2">
                        <span class="label">Gotify host</span>
                        <input v-model="form.config.host" class="input" :required="!editing" placeholder="gotify.example.com:443">
                    </label>
                    <label class="space-y-2">
                        <span class="label">Application token</span>
                        <input v-model="form.config.token" class="input" :required="!editing" autocomplete="off">
                    </label>
                </div>

                <div v-else-if="form.service === 'smtp'" class="grid gap-4 sm:grid-cols-2">
                    <label class="space-y-2">
                        <span class="label">SMTP host</span>
                        <input v-model="form.config.host" class="input" :required="!editing" placeholder="smtp.example.com">
                    </label>
                    <label class="space-y-2">
                        <span class="label">Port</span>
                        <input v-model="form.config.port" class="input" type="number" placeholder="587">
                    </label>
                    <label class="space-y-2">
                        <span class="label">Username</span>
                        <input v-model="form.config.username" class="input" autocomplete="off">
                    </label>
                    <label class="space-y-2">
                        <span class="label">Password</span>
                        <PasswordInput v-model="form.config.password" autocomplete="new-password" />
                    </label>
                    <label class="space-y-2">
                        <span class="label">From address</span>
                        <input v-model="form.config.from" class="input" :required="!editing" placeholder="volumevault@example.com">
                    </label>
                    <label class="space-y-2">
                        <span class="label">To address</span>
                        <input v-model="form.config.to" class="input" :required="!editing" placeholder="you@example.com">
                    </label>
                </div>

                <label v-else class="block space-y-2">
                    <span class="label">Shoutrrr URL</span>
                    <input v-model="form.config.url" class="input" :required="!editing" placeholder="discord://token@webhookid">
                </label>

                <span v-if="form.errors.config" class="mt-3 block text-sm text-rose-300">{{ form.errors.config }}</span>
            </section>

            <section class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <h2 class="mb-4 text-lg font-semibold">{{ t('When to notify') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 text-sm">
                        <input v-model="form.notification_level" type="radio" value="error" class="text-sky-400">
                        {{ t('Errors only') }}
                    </label>
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 text-sm">
                        <input v-model="form.notification_level" type="radio" value="info" class="text-sky-400">
                        {{ t('Every backup run') }}
                    </label>
                </div>
            </section>

            <section class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold">{{ t('Message format') }}</h2>
                        <p class="mt-1 text-sm text-slate-400">{{ t('Keep the default message, or override the title and body sent after backup runs.') }}</p>
                    </div>
                    <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-slate-950/60 px-4 py-3 text-sm">
                        <input v-model="useCustomMessage" type="checkbox" class="rounded border-slate-600 bg-slate-950 text-sky-400">
                        {{ t('Use custom message') }}
                    </label>
                </div>

                <div v-if="useCustomMessage" class="mt-4 grid gap-4">
                    <label class="space-y-2">
                        <span class="label">{{ t('Title template') }}</span>
                        <input v-model="form.title_template" class="input" maxlength="255" :placeholder="titleTemplatePlaceholder">
                        <span v-if="form.errors.title_template" class="text-sm text-rose-300">{{ form.errors.title_template }}</span>
                    </label>

                    <label class="space-y-2">
                        <span class="label">{{ t('Body template') }}</span>
                        <textarea v-model="form.body_template" class="input min-h-36" maxlength="4000" :placeholder="bodyTemplatePlaceholder"></textarea>
                        <span v-if="form.errors.body_template" class="text-sm text-rose-300">{{ form.errors.body_template }}</span>
                    </label>

                    <p class="rounded-xl border border-sky-300/20 bg-sky-400/10 p-3 text-sm text-sky-100">
                        {{ t('Available tokens: {tokens}', { tokens: templateTokens }) }}
                    </p>
                </div>
            </section>

            <section class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <h2 class="mb-4 text-lg font-semibold">{{ t('Backup scope') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 text-sm">
                        <input v-model="form.scope" type="radio" value="all" class="text-sky-400">
                        {{ t('All backups') }}
                    </label>
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 text-sm">
                        <input v-model="form.scope" type="radio" value="specific" class="text-sky-400">
                        {{ t('Specific backups') }}
                    </label>
                </div>

                <div v-if="form.scope === 'specific'" class="mt-4 grid gap-2 sm:grid-cols-2">
                    <label v-for="job in jobs" :key="job.id" class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-3 text-sm">
                        <input type="checkbox" :checked="form.backup_job_ids.includes(job.id)" class="rounded border-slate-600 bg-slate-950 text-sky-400" @change="toggleJob(job.id)">
                        <span class="min-w-0 break-words">{{ job.name }} <span class="break-all text-slate-500">/ {{ job.volume_name }}</span></span>
                    </label>
                    <p v-if="!jobs.length" class="text-sm text-slate-400">Create a backup job first, or use all backups.</p>
                </div>
            </section>

            <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-4 text-sm">
                <input v-model="form.is_active" type="checkbox" class="rounded border-slate-600 bg-slate-950 text-sky-400">
                {{ t('Channel active') }}
            </label>

            <div class="flex flex-wrap gap-3">
                <button class="btn-primary" :disabled="form.processing">{{ editing ? t('Update channel') : t('Create channel') }}</button>
                <Link href="/notifications" class="btn-secondary">{{ t('Cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
