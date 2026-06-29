<script setup lang="ts">
import AppLayout from "@/Layouts/AppLayout.vue";
import PasswordInput from "@/Components/PasswordInput.vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import { useI18n } from "@/i18n";

const props = defineProps<{
    channel: any | null;
    services: string[];
}>();

const editing = computed(() => Boolean(props.channel));
const { t } = useI18n();
const useCustomMessage = ref(
    Boolean(props.channel?.title_template || props.channel?.body_template),
);
const useCustomRestoreMessage = ref(
    Boolean(
        props.channel?.restore_title_template ||
        props.channel?.restore_body_template,
    ),
);
const templateTokens =
    "{{ job }}, {{ source }}, {{ volume }}, {{ destination }}, {{ status }}, {{ trigger }}, {{ user }}, {{ duration }}, {{ backup_size }}, {{ error }}";
const titleTemplatePlaceholder =
    "VolumeVault: {{ status }} backup for {{ job }}";
const bodyTemplatePlaceholder =
    "Job: {{ job }}\nSource: {{ source }}\nDestination: {{ destination }}\nStatus: {{ status }}\nDuration: {{ duration }}";
const restoreTemplateTokens =
    "{{ job }}, {{ source }}, {{ target }}, {{ mode }}, {{ status }}, {{ user }}, {{ duration }}, {{ error }}";
const restoreTitleTemplatePlaceholder =
    "VolumeVault: {{ status }} restore for {{ job }}";
const restoreBodyTemplatePlaceholder =
    "Job: {{ job }}\nSource: {{ source }}\nTarget: {{ target }}\nMode: {{ mode }}\nStatus: {{ status }}";
const form = useForm({
    name: props.channel?.name || "",
    service: props.channel?.service || "discord",
    notification_level: props.channel?.notification_level || "error",
    title_template: props.channel?.title_template || "",
    body_template: props.channel?.body_template || "",
    restore_title_template: props.channel?.restore_title_template || "",
    restore_body_template: props.channel?.restore_body_template || "",
    is_active: props.channel?.is_active ?? true,
    is_default: props.channel?.is_default ?? false,
    config: {} as Record<string, any>,
});

const serviceHelp = computed(() => {
    if (form.service === "discord")
        return t(
            "Paste the Discord webhook URL from Channel settings > Integrations > Webhooks.",
        );
    if (form.service === "telegram")
        return t(
            "Create a bot with BotFather, then add the bot to your chat or channel.",
        );
    if (form.service === "ntfy")
        return t(
            "Pick a private topic name. Public ntfy.sh topics can be guessed by others.",
        );
    if (form.service === "gotify")
        return t(
            "Create an application token in Gotify and paste your Gotify host.",
        );
    if (form.service === "smtp")
        return t(
            "Use your SMTP server details. The password is encrypted after saving.",
        );
    if (form.service === "webhook")
        return t(
            "Set a webhook URL for each action — start, success and failure. VolumeVault calls the matching URL on the corresponding backup or restore event.",
        );
    return t("Paste a complete Shoutrrr URL for any supported service.");
});

watch(
    () => form.service,
    () => {
        form.config = {};
    },
);

const toggleCustomMessage = () => {
    useCustomMessage.value = !useCustomMessage.value;
};

const toggleCustomRestoreMessage = () => {
    useCustomRestoreMessage.value = !useCustomRestoreMessage.value;
};

const toggleChannelActive = () => {
    form.is_active = !form.is_active;
};

const toggleDefaultChannel = () => {
    form.is_default = !form.is_default;
};

const submit = () => {
    if (!useCustomMessage.value) {
        form.title_template = "";
        form.body_template = "";
    }

    if (!useCustomRestoreMessage.value) {
        form.restore_title_template = "";
        form.restore_body_template = "";
    }

    if (editing.value) {
        form.put(`/notifications/${props.channel.id}`);
        return;
    }

    form.post("/notifications");
};
</script>

<template>
    <Head :title="editing ? t('Edit notification') : t('New notification')" />
    <AppLayout
        :title="editing ? t('Edit notification') : t('New notification')"
        :subtitle="t('Configure one alert channel and its delivery behavior.')"
    >
        <form
            class="card max-w-4xl space-y-6 p-4 sm:p-6"
            @submit.prevent="submit"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2">
                    <span class="label">{{ t("Channel name") }}</span>
                    <input
                        v-model="form.name"
                        class="input"
                        required
                        placeholder="Discord homelab alerts"
                    />
                    <span
                        v-if="form.errors.name"
                        class="text-sm text-rose-300"
                        >{{ form.errors.name }}</span
                    >
                </label>

                <label class="space-y-2">
                    <span class="label">{{ t("Notification service") }}</span>
                    <select v-model="form.service" class="input">
                        <option
                            v-for="service in services"
                            :key="service"
                            :value="service"
                        >
                            {{ service }}
                        </option>
                    </select>
                </label>
            </div>

            <section
                class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5"
            >
                <div class="mb-4">
                    <h2 class="text-lg font-semibold">
                        {{ t("Guided setup") }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-400">{{ serviceHelp }}</p>
                    <p v-if="editing" class="mt-2 text-xs text-slate-400">
                        {{
                            t(
                                "Leave setup fields empty to keep the saved encrypted Shoutrrr URL.",
                            )
                        }}
                    </p>
                </div>

                <div
                    v-if="form.service === 'discord'"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <label class="space-y-2 sm:col-span-2">
                        <span class="label">{{
                            t("Discord webhook URL")
                        }}</span>
                        <input
                            v-model="form.config.webhook_url"
                            class="input"
                            :required="!editing"
                            placeholder="https://discord.com/api/webhooks/..."
                        />
                    </label>
                    <label class="space-y-2">
                        <span class="label">{{ t("Bot username") }}</span>
                        <input
                            v-model="form.config.username"
                            class="input"
                            placeholder="VolumeVault"
                        />
                    </label>
                </div>

                <div
                    v-else-if="form.service === 'telegram'"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <label class="space-y-2">
                        <span class="label">{{ t("Bot token") }}</span>
                        <input
                            v-model="form.config.token"
                            class="input"
                            :required="!editing"
                            autocomplete="off"
                            placeholder="123456:ABC..."
                        />
                    </label>
                    <label class="space-y-2">
                        <span class="label">{{ t("Chats or channels") }}</span>
                        <input
                            v-model="form.config.chats"
                            class="input"
                            :required="!editing"
                            placeholder="@mychannel or -1001234567890"
                        />
                    </label>
                </div>

                <div
                    v-else-if="form.service === 'ntfy'"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <label class="space-y-2">
                        <span class="label">{{ t("Ntfy host") }}</span>
                        <input
                            v-model="form.config.host"
                            class="input"
                            placeholder="ntfy.sh"
                        />
                    </label>
                    <label class="space-y-2">
                        <span class="label">{{ t("Topic") }}</span>
                        <input
                            v-model="form.config.topic"
                            class="input"
                            :required="!editing"
                            placeholder="volumevault-private-topic"
                        />
                    </label>
                    <label class="space-y-2">
                        <span class="label">Username</span>
                        <input
                            v-model="form.config.username"
                            class="input"
                            autocomplete="off"
                            placeholder="Optional"
                        />
                    </label>
                    <label class="space-y-2">
                        <span class="label">Password</span>
                        <PasswordInput
                            v-model="form.config.password"
                            autocomplete="new-password"
                            placeholder="Optional"
                        />
                    </label>
                </div>

                <div
                    v-else-if="form.service === 'gotify'"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <label class="space-y-2">
                        <span class="label">{{ t("Gotify host") }}</span>
                        <input
                            v-model="form.config.host"
                            class="input"
                            :required="!editing"
                            placeholder="gotify.example.com:443"
                        />
                    </label>
                    <label class="space-y-2">
                        <span class="label">{{ t("Application token") }}</span>
                        <input
                            v-model="form.config.token"
                            class="input"
                            :required="!editing"
                            autocomplete="off"
                        />
                    </label>
                </div>

                <div
                    v-else-if="form.service === 'smtp'"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <label class="space-y-2">
                        <span class="label">{{ t("SMTP host") }}</span>
                        <input
                            v-model="form.config.host"
                            class="input"
                            :required="!editing"
                            placeholder="smtp.example.com"
                        />
                    </label>
                    <label class="space-y-2">
                        <span class="label">{{ t("Port") }}</span>
                        <input
                            v-model="form.config.port"
                            class="input"
                            type="number"
                            placeholder="587"
                        />
                    </label>
                    <label class="space-y-2">
                        <span class="label">Username</span>
                        <input
                            v-model="form.config.username"
                            class="input"
                            autocomplete="off"
                        />
                    </label>
                    <label class="space-y-2">
                        <span class="label">Password</span>
                        <PasswordInput
                            v-model="form.config.password"
                            autocomplete="new-password"
                        />
                    </label>
                    <label class="space-y-2">
                        <span class="label">{{ t("From address") }}</span>
                        <input
                            v-model="form.config.from"
                            class="input"
                            :required="!editing"
                            placeholder="volumevault@example.com"
                        />
                    </label>
                    <label class="space-y-2">
                        <span class="label">To address</span>
                        <input
                            v-model="form.config.to"
                            class="input"
                            :required="!editing"
                            placeholder="you@example.com"
                        />
                    </label>
                    <div class="space-y-2 sm:col-span-2">
                        <label
                            class="flex cursor-pointer items-center gap-3 text-sm"
                        >
                            <input
                                v-model="form.config.unencrypted"
                                type="checkbox"
                                class="text-sky-400"
                            />
                            <span class="label">{{
                                t("SMTP server is unencrypted")
                            }}</span>
                        </label>
                        <p
                            v-if="form.config.unencrypted"
                            class="rounded-xl border border-amber-300/20 bg-amber-400/10 p-3 text-sm text-amber-100"
                        >
                            ⚠️
                            {{
                                t(
                                    "Unencrypted connection: credentials and emails are sent in plain text. Only use this for a trusted local SMTP server.",
                                )
                            }}
                        </p>
                    </div>
                </div>

                <div
                    v-else-if="form.service === 'webhook'"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <label class="space-y-2 sm:col-span-2">
                        <span class="label">{{ t("Success URL") }}</span>
                        <input
                            v-model="form.config.success_url"
                            class="input"
                            type="url"
                            placeholder="https://example.com/job-succeeded"
                        />
                    </label>
                    <label class="space-y-2">
                        <span class="label">{{ t("Start URL") }}</span>
                        <input
                            v-model="form.config.start_url"
                            class="input"
                            type="url"
                            placeholder="https://example.com/job-started"
                        />
                    </label>
                    <label class="space-y-2">
                        <span class="label">{{ t("Failure URL") }}</span>
                        <input
                            v-model="form.config.fail_url"
                            class="input"
                            type="url"
                            placeholder="https://example.com/job-failed"
                        />
                    </label>
                    <p class="text-xs text-slate-400 sm:col-span-2">
                        {{
                            t(
                                "Fill any subset. Each URL is called on its action, for both backups and restores. Set the level below to “Every backup and restore run” to also send the start and success URLs.",
                            )
                        }}
                    </p>
                </div>

                <label v-else class="block space-y-2">
                    <span class="label">{{ t("Shoutrrr URL") }}</span>
                    <input
                        v-model="form.config.url"
                        class="input"
                        :required="!editing"
                        placeholder="discord://token@webhookid"
                    />
                </label>

                <span
                    v-if="form.errors.config"
                    class="mt-3 block text-sm text-rose-300"
                    >{{ form.errors.config }}</span
                >
            </section>

            <section
                class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5"
            >
                <h2 class="mb-4 text-lg font-semibold">
                    {{ t("When to notify") }}
                </h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label
                        class="flex cursor-pointer items-center gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 text-sm"
                    >
                        <input
                            v-model="form.notification_level"
                            type="radio"
                            value="error"
                            class="text-sky-400"
                        />
                        {{ t("Errors only") }}
                    </label>
                    <label
                        class="flex cursor-pointer items-center gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-4 text-sm"
                    >
                        <input
                            v-model="form.notification_level"
                            type="radio"
                            value="info"
                            class="text-sky-400"
                        />
                        {{ t("Every backup and restore run") }}
                    </label>
                </div>
            </section>

            <section
                class="space-y-4 rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5"
            >
                <div>
                    <h2 class="text-lg font-semibold">
                        {{ t("Message format") }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-400">
                        {{
                            t(
                                "Keep the default message, or override the title and body sent after backup and restore runs.",
                            )
                        }}
                    </p>
                </div>

                <div
                    class="rounded-xl border border-white/10 bg-slate-950/40 p-4"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <h3 class="font-medium text-white">
                            {{ t("Backup messages") }}
                        </h3>
                        <button
                            type="button"
                            role="switch"
                            class="inline-flex shrink-0 items-center gap-3 rounded-full border border-white/10 bg-slate-950/60 px-3 py-2 text-sm transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                            :aria-checked="useCustomMessage"
                            :aria-label="t('Use custom message')"
                            @click="toggleCustomMessage"
                        >
                            <span
                                class="relative inline-flex h-6 w-11 items-center rounded-full border p-0.5 transition"
                                :class="
                                    useCustomMessage
                                        ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50'
                                        : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'
                                "
                            >
                                <span
                                    class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform"
                                    :class="
                                        useCustomMessage
                                            ? 'translate-x-5'
                                            : 'translate-x-0 bg-slate-400'
                                    "
                                ></span>
                            </span>
                            <span class="font-medium">{{
                                t("Use custom message")
                            }}</span>
                        </button>
                    </div>

                    <div v-if="useCustomMessage" class="mt-4 grid gap-4">
                        <label class="space-y-2">
                            <span class="label">{{ t("Title template") }}</span>
                            <input
                                v-model="form.title_template"
                                class="input"
                                maxlength="255"
                                :placeholder="titleTemplatePlaceholder"
                            />
                            <span
                                v-if="form.errors.title_template"
                                class="text-sm text-rose-300"
                                >{{ form.errors.title_template }}</span
                            >
                        </label>

                        <label class="space-y-2">
                            <span class="label">{{ t("Body template") }}</span>
                            <textarea
                                v-model="form.body_template"
                                class="input min-h-36"
                                maxlength="4000"
                                :placeholder="bodyTemplatePlaceholder"
                            ></textarea>
                            <span
                                v-if="form.errors.body_template"
                                class="text-sm text-rose-300"
                                >{{ form.errors.body_template }}</span
                            >
                        </label>

                        <p
                            class="rounded-xl border border-sky-300/20 bg-sky-400/10 p-3 text-sm text-sky-100"
                        >
                            {{
                                t("Available tokens: {tokens}", {
                                    tokens: templateTokens,
                                })
                            }}
                        </p>
                    </div>
                </div>

                <div
                    class="rounded-xl border border-white/10 bg-slate-950/40 p-4"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <h3 class="font-medium text-white">
                            {{ t("Restore messages") }}
                        </h3>
                        <button
                            type="button"
                            role="switch"
                            class="inline-flex shrink-0 items-center gap-3 rounded-full border border-white/10 bg-slate-950/60 px-3 py-2 text-sm transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                            :aria-checked="useCustomRestoreMessage"
                            :aria-label="t('Use custom restore message')"
                            @click="toggleCustomRestoreMessage"
                        >
                            <span
                                class="relative inline-flex h-6 w-11 items-center rounded-full border p-0.5 transition"
                                :class="
                                    useCustomRestoreMessage
                                        ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50'
                                        : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'
                                "
                            >
                                <span
                                    class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform"
                                    :class="
                                        useCustomRestoreMessage
                                            ? 'translate-x-5'
                                            : 'translate-x-0 bg-slate-400'
                                    "
                                ></span>
                            </span>
                            <span class="font-medium">{{
                                t("Use custom message")
                            }}</span>
                        </button>
                    </div>

                    <div v-if="useCustomRestoreMessage" class="mt-4 grid gap-4">
                        <label class="space-y-2">
                            <span class="label">{{ t("Title template") }}</span>
                            <input
                                v-model="form.restore_title_template"
                                class="input"
                                maxlength="255"
                                :placeholder="restoreTitleTemplatePlaceholder"
                            />
                            <span
                                v-if="form.errors.restore_title_template"
                                class="text-sm text-rose-300"
                                >{{ form.errors.restore_title_template }}</span
                            >
                        </label>

                        <label class="space-y-2">
                            <span class="label">{{ t("Body template") }}</span>
                            <textarea
                                v-model="form.restore_body_template"
                                class="input min-h-36"
                                maxlength="4000"
                                :placeholder="restoreBodyTemplatePlaceholder"
                            ></textarea>
                            <span
                                v-if="form.errors.restore_body_template"
                                class="text-sm text-rose-300"
                                >{{ form.errors.restore_body_template }}</span
                            >
                        </label>

                        <p
                            class="rounded-xl border border-sky-300/20 bg-sky-400/10 p-3 text-sm text-sky-100"
                        >
                            {{
                                t("Available tokens: {tokens}", {
                                    tokens: restoreTemplateTokens,
                                })
                            }}
                        </p>
                    </div>
                </div>
            </section>

            <section class="grid gap-3 sm:grid-cols-2">
                <div
                    class="flex items-start justify-between gap-4 rounded-xl border border-white/10 bg-white/5 p-4 text-sm"
                >
                    <div>
                        <p class="font-medium text-white">
                            {{ t("Channel active") }}
                        </p>
                        <p class="mt-1 text-slate-400">
                            {{ form.is_active ? t("Enabled") : t("Disabled") }}
                        </p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        class="relative mt-1 inline-flex h-7 w-12 shrink-0 items-center rounded-full border p-1 transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                        :class="
                            form.is_active
                                ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50'
                                : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'
                        "
                        :aria-checked="form.is_active"
                        :aria-label="
                            form.is_active
                                ? t('Deactivate channel')
                                : t('Activate channel')
                        "
                        @click="toggleChannelActive"
                    >
                        <span
                            class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform"
                            :class="
                                form.is_active
                                    ? 'translate-x-5'
                                    : 'translate-x-0 bg-slate-400'
                            "
                        ></span>
                    </button>
                </div>

                <div
                    class="flex items-start justify-between gap-4 rounded-xl border border-white/10 bg-white/5 p-4 text-sm"
                >
                    <div>
                        <p class="font-medium text-white">
                            {{ t("Default channel for new backup jobs") }}
                        </p>
                        <p class="mt-1 text-slate-400">
                            {{
                                t(
                                    "Preselect this channel when creating backup jobs. It does not limit which channels a job can use.",
                                )
                            }}
                        </p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        class="relative mt-1 inline-flex h-7 w-12 shrink-0 items-center rounded-full border p-1 transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                        :class="
                            form.is_default
                                ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50'
                                : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'
                        "
                        :aria-checked="form.is_default"
                        :aria-label="t('Default channel for new backup jobs')"
                        @click="toggleDefaultChannel"
                    >
                        <span
                            class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform"
                            :class="
                                form.is_default
                                    ? 'translate-x-5'
                                    : 'translate-x-0 bg-slate-400'
                            "
                        ></span>
                    </button>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button class="btn-primary" :disabled="form.processing">
                    {{ editing ? t("Update channel") : t("Create channel") }}
                </button>
                <Link href="/notifications" class="btn-secondary">{{
                    t("Cancel")
                }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
