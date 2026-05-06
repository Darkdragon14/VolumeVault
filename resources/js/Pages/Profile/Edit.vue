<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import { languageNames, useI18n } from '@/i18n';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    profileUser: {
        id: number;
        name: string;
        email: string;
        locale: string;
    };
    locales: string[];
}>();

const { t } = useI18n();
const languageName = (locale: string) => languageNames[locale as keyof typeof languageNames] || locale;
const form = useForm({
    name: props.profileUser.name,
    email: props.profileUser.email,
    locale: props.profileUser.locale,
    password: '',
    password_confirmation: '',
});

const submit = () => form.put('/profile');
</script>

<template>
    <Head :title="t('Edit profile')" />
    <AppLayout :title="t('Edit profile')" :subtitle="t('Update your account information, language, and password.')">
        <form class="card max-w-2xl space-y-5 p-4 sm:p-6" @submit.prevent="submit">
            <div>
                <h2 class="text-lg font-semibold text-white">{{ t('Profile details') }}</h2>
                <p class="mt-1 text-sm text-slate-400">{{ t('Update your account information and preferred language.') }}</p>
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
    </AppLayout>
</template>
