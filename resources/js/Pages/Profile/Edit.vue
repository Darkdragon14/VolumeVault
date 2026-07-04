<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import { languageNames, useI18n } from '@/i18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    profileUser: {
        id: number;
        name: string;
        email: string;
        locale: string;
        date_locale: string | null;
        default_per_page: number;
    };
    locales: string[];
    dateLocales: string[];
    perPageOptions: number[];
    twoFactorEnabled: boolean;
    twoFactorPending: boolean;
    twoFactorQrSvg?: string;
    twoFactorSecret?: string;
    twoFactorRecoveryCodes?: string[];
    twoFactorDevices?: Array<{
        id: number;
        user_agent: string | null;
        last_used_at: string | null;
        expires_at: string | null;
        is_current: boolean;
    }>;
}>();

const { t, formatDate, timezone } = useI18n();
const languageName = (locale: string) => languageNames[locale as keyof typeof languageNames] || locale;
const dateLocaleNames: Record<string, string> = {
    'en-US': 'English (United States)',
    'en-AU': 'English (Australia)',
    'en-GB': 'English (United Kingdom)',
    'en-CA': 'English (Canada)',
    'fr-FR': 'French (France)',
    'de-DE': 'German (Germany)',
    'es-ES': 'Spanish (Spain)',
    'it-IT': 'Italian (Italy)',
    'nl-NL': 'Dutch (Netherlands)',
    'cs-CZ': 'Czech (Czechia)',
    'hu-HU': 'Hungarian (Hungary)',
    'ru-RU': 'Russian (Russia)',
};
const dateLocaleName = (dateLocale: string) => t(dateLocaleNames[dateLocale] || dateLocale);
const perPageLabel = (value: number) => value === 0 ? t('All') : String(value);
const form = useForm({
    name: props.profileUser.name,
    email: props.profileUser.email,
    locale: props.profileUser.locale,
    date_locale: props.profileUser.date_locale || '',
    default_per_page: props.profileUser.default_per_page,
    password: '',
    password_confirmation: '',
});
const dateLocaleExample = computed(() => new Date('2026-07-04T06:37:15Z').toLocaleString(form.date_locale || form.locale, {
    day: '2-digit',
    hour: '2-digit',
    hour12: false,
    minute: '2-digit',
    month: '2-digit',
    second: '2-digit',
    timeZone: timezone.value,
    timeZoneName: 'short',
    year: 'numeric',
}));

const submit = () => form.put('/profile');

const enableForm = useForm({});
const confirmForm = useForm({ code: '' });
const disableForm = useForm({ password: '' });
const recoveryForm = useForm({});

const enableTwoFactor = () => enableForm.post('/profile/two-factor', { preserveScroll: true });
const confirmTwoFactor = () => confirmForm.post('/profile/two-factor/confirm', {
    preserveScroll: true,
    onSuccess: () => confirmForm.reset(),
});
const cancelSetup = () => disableForm.delete('/profile/two-factor', { preserveScroll: true });
const disableTwoFactor = () => disableForm.delete('/profile/two-factor', {
    preserveScroll: true,
    onSuccess: () => disableForm.reset(),
});
const regenerateCodes = () => recoveryForm.post('/profile/two-factor/recovery-codes', { preserveScroll: true });
const revokeDevice = (id: number) => {
    if (confirm(t('Remove this trusted device?'))) router.delete(`/profile/two-factor/devices/${id}`, { preserveScroll: true });
};
const revokeAllDevices = () => {
    if (confirm(t('Remove all trusted devices?'))) router.delete('/profile/two-factor/devices', { preserveScroll: true });
};
</script>

<template>
    <Head :title="t('Edit profile')" />
    <AppLayout :title="t('Edit profile')" :subtitle="t('Update your account information, preferences, and password.')">
        <form class="card max-w-2xl space-y-5 p-4 sm:p-6" @submit.prevent="submit">
            <div>
                <h2 class="text-lg font-semibold text-white">{{ t('Profile details') }}</h2>
                <p class="mt-1 text-sm text-slate-400">{{ t('Update your account information and display preferences.') }}</p>
            </div>

            <label class="space-y-2">
                <span class="label">{{ t('Name') }}</span>
                <input v-model="form.name" class="input" required autocomplete="name">
                <span v-if="form.errors.name" class="text-sm text-rose-300">{{ form.errors.name }}</span>
            </label>

            <label class="space-y-2">
                <span class="label">{{ t('Email') }}</span>
                <input v-model="form.email" class="input" type="email" required autocomplete="email">
                <span v-if="form.errors.email" class="text-sm text-rose-300">{{ form.errors.email }}</span>
            </label>

            <label class="space-y-2">
                <span class="label">{{ t('Language') }}</span>
                <select v-model="form.locale" class="input">
                    <option v-for="availableLocale in locales" :key="availableLocale" :value="availableLocale">
                        {{ languageName(availableLocale) }}
                    </option>
                </select>
                <span v-if="form.errors.locale" class="text-sm text-rose-300">{{ form.errors.locale }}</span>
            </label>

            <label class="space-y-2">
                <span class="label">{{ t('Date format') }}</span>
                <select v-model="form.date_locale" class="input">
                    <option value="">{{ t('Use language default') }}</option>
                    <option v-for="dateLocale in dateLocales" :key="dateLocale" :value="dateLocale">
                        {{ dateLocaleName(dateLocale) }}
                    </option>
                </select>
                <span class="block text-xs text-slate-400">{{ t('Dates use this regional format without changing the interface language.') }} {{ t('Example: {date}', { date: dateLocaleExample }) }}</span>
                <span v-if="form.errors.date_locale" class="text-sm text-rose-300">{{ form.errors.date_locale }}</span>
            </label>

            <label class="space-y-2">
                <span class="label">{{ t('Default items per page') }}</span>
                <select v-model="form.default_per_page" class="input">
                    <option v-for="option in perPageOptions" :key="option" :value="option">
                        {{ perPageLabel(option) }}
                    </option>
                </select>
                <span v-if="form.errors.default_per_page" class="text-sm text-rose-300">{{ form.errors.default_per_page }}</span>
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2">
                    <span class="label">{{ t('New password') }}</span>
                    <PasswordInput v-model="form.password" autocomplete="new-password" />
                    <span class="text-xs text-slate-400">{{ t('Leave empty to keep the current password.') }}</span>
                    <span v-if="form.errors.password" class="block text-sm text-rose-300">{{ form.errors.password }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Confirm password') }}</span>
                    <PasswordInput v-model="form.password_confirmation" autocomplete="new-password" />
                </label>
            </div>

            <div class="flex flex-wrap gap-3">
                <button class="btn-primary" :disabled="form.processing">{{ t('Update profile') }}</button>
            </div>
        </form>

        <section class="card mt-6 max-w-2xl space-y-5 p-4 sm:p-6">
            <div>
                <h2 class="text-lg font-semibold text-white">{{ t('Two-factor authentication') }}</h2>
                <p class="mt-1 text-sm text-slate-400">{{ t('Add an extra layer of security by requiring a one-time code from an authenticator app when signing in.') }}</p>
            </div>

            <!-- Disabled: offer to start enrolment -->
            <div v-if="!twoFactorEnabled && !twoFactorPending">
                <div class="mb-4 inline-flex items-center gap-2 rounded-full bg-slate-700/40 px-3 py-1 text-xs font-medium text-slate-300">
                    <span class="h-2 w-2 rounded-full bg-slate-400"></span>{{ t('Disabled') }}
                </div>
                <div>
                    <button class="btn-primary" :disabled="enableForm.processing" @click="enableTwoFactor">{{ t('Enable two-factor authentication') }}</button>
                </div>
            </div>

            <!-- Pending: show QR, secret and a code field to confirm -->
            <div v-else-if="twoFactorPending" class="space-y-4">
                <p class="text-sm text-slate-300">{{ t('Scan the QR code below with your authenticator app, then enter the generated code to finish enabling two-factor authentication.') }}</p>

                <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                    <div class="inline-block rounded-lg bg-white p-3" v-html="twoFactorQrSvg"></div>
                    <div class="space-y-2">
                        <span class="label">{{ t('Or enter this setup key manually') }}</span>
                        <code class="block break-all rounded-lg bg-slate-950 px-3 py-2 text-sm text-sky-200">{{ twoFactorSecret }}</code>
                    </div>
                </div>

                <div v-if="twoFactorRecoveryCodes" class="space-y-2">
                    <span class="label">{{ t('Recovery codes') }}</span>
                    <p class="text-xs text-slate-400">{{ t('Store these codes in a safe place. Each can be used once to sign in if you lose access to your authenticator app.') }}</p>
                    <ul class="grid grid-cols-2 gap-2 rounded-lg bg-slate-950 p-3 font-mono text-sm text-slate-200">
                        <li v-for="code in twoFactorRecoveryCodes" :key="code">{{ code }}</li>
                    </ul>
                </div>

                <form class="space-y-3" @submit.prevent="confirmTwoFactor">
                    <label class="space-y-2">
                        <span class="label">{{ t('Authentication code') }}</span>
                        <input v-model="confirmForm.code" class="input" type="text" inputmode="numeric"
                            autocomplete="one-time-code" maxlength="6" placeholder="000000">
                        <span v-if="confirmForm.errors.code" class="text-sm text-rose-300">{{ confirmForm.errors.code }}</span>
                    </label>
                    <div class="flex flex-wrap gap-3">
                        <button class="btn-primary" :disabled="confirmForm.processing">{{ t('Confirm') }}</button>
                        <button type="button" class="btn-secondary" :disabled="disableForm.processing" @click="cancelSetup">{{ t('Cancel') }}</button>
                    </div>
                </form>
            </div>

            <!-- Enabled: status, regenerate codes, disable -->
            <div v-else class="space-y-4">
                <div class="inline-flex items-center gap-2 rounded-full bg-emerald-400/10 px-3 py-1 text-xs font-medium text-emerald-200">
                    <span class="h-2 w-2 rounded-full bg-emerald-400"></span>{{ t('Enabled') }}
                </div>

                <div v-if="twoFactorRecoveryCodes" class="space-y-2">
                    <span class="label">{{ t('Recovery codes') }}</span>
                    <p class="text-xs text-slate-400">{{ t('Store these codes in a safe place. Each can be used once to sign in if you lose access to your authenticator app.') }}</p>
                    <ul class="grid grid-cols-2 gap-2 rounded-lg bg-slate-950 p-3 font-mono text-sm text-slate-200">
                        <li v-for="code in twoFactorRecoveryCodes" :key="code">{{ code }}</li>
                    </ul>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button class="btn-secondary" :disabled="recoveryForm.processing" @click="regenerateCodes">{{ t('Regenerate recovery codes') }}</button>
                </div>

                <div class="space-y-3 border-t border-white/10 pt-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-white">{{ t('Trusted devices') }}</h3>
                            <p class="mt-1 text-xs text-slate-400">{{ t('Browsers you trust to skip the code for 30 days.') }}</p>
                        </div>
                        <button v-if="twoFactorDevices && twoFactorDevices.length" type="button" class="btn-secondary" @click="revokeAllDevices">{{ t('Remove all trusted devices') }}</button>
                    </div>

                    <p v-if="!twoFactorDevices || !twoFactorDevices.length" class="text-sm text-slate-400">{{ t('No trusted devices.') }}</p>

                    <ul v-else class="divide-y divide-white/10 rounded-lg bg-slate-950">
                        <li v-for="device in twoFactorDevices" :key="device.id" class="flex items-center justify-between gap-3 px-3 py-3">
                            <div class="min-w-0">
                                <p class="flex items-center gap-2 truncate text-sm text-slate-200">
                                    <span class="truncate">{{ device.user_agent || t('Unknown device') }}</span>
                                    <span v-if="device.is_current" class="shrink-0 rounded-full bg-emerald-400/10 px-2 py-0.5 text-xs font-medium text-emerald-200">{{ t('This device') }}</span>
                                </p>
                                <p class="mt-1 text-xs text-slate-500">{{ t('Last used') }}: {{ formatDate(device.last_used_at) }} · {{ t('Expires') }}: {{ formatDate(device.expires_at) }}</p>
                            </div>
                            <button type="button" class="btn-danger shrink-0" @click="revokeDevice(device.id)">{{ t('Remove') }}</button>
                        </li>
                    </ul>
                </div>

                <form class="space-y-3 border-t border-white/10 pt-4" @submit.prevent="disableTwoFactor">
                    <label class="space-y-2">
                        <span class="label">{{ t('Confirm your password to disable') }}</span>
                        <PasswordInput v-model="disableForm.password" autocomplete="current-password" />
                        <span v-if="disableForm.errors.password" class="block text-sm text-rose-300">{{ disableForm.errors.password }}</span>
                    </label>
                    <button class="btn-danger" :disabled="disableForm.processing">{{ t('Disable two-factor authentication') }}</button>
                </form>
            </div>
        </section>
    </AppLayout>
</template>
