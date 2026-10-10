<script setup lang="ts">
import PasswordInput from '@/Components/PasswordInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

const { t } = useI18n();

defineProps<{
    mailResetEnabled?: boolean;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const githubIssuesUrl = 'https://github.com/Darkdragon14/VolumeVault/issues';
const submit = () => form.post('/login');
</script>

<template>
    <Head :title="t('Login')" />
    <main class="auth-shell">
        <form class="card w-full max-w-md flex flex-col gap-5 p-4 sm:p-6" @submit.prevent="submit">
            <div>
                <img :src="'/logo.png'" alt="VolumeVault" class="mb-4 h-16 w-auto object-contain">
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">{{ t('Sign in to VolumeVault') }}</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('Access is required because this app can control Docker and encrypted backup settings.') }}</p>
            </div>

            <label class="flex flex-col gap-2">
                <span class="label">{{ t('Email') }}</span>
                <input v-model="form.email" class="input" type="email" required autofocus autocomplete="email">
                <span v-if="form.errors.email" class="text-sm text-rose-700 dark:text-rose-300">{{ form.errors.email }}</span>
            </label>

            <label class="flex flex-col gap-2">
                <span class="label">{{ t('Password') }}</span>
                <PasswordInput v-model="form.password" required autocomplete="current-password" />
                <span v-if="form.errors.password" class="text-sm text-rose-700 dark:text-rose-300">{{ form.errors.password }}</span>
            </label>

            <div v-if="mailResetEnabled" class="text-right text-sm">
                <Link href="/forgot-password" class="font-medium text-sky-700 dark:text-sky-300 transition hover:text-sky-600 dark:hover:text-sky-200">{{ t('Forgot password?') }}</Link>
            </div>

            <label class="flex items-center gap-3 text-sm text-slate-600 dark:text-slate-300">
                <input v-model="form.remember" type="checkbox" class="rounded-sm border-slate-200 dark:border-slate-600 bg-white/90 dark:bg-slate-950 text-sky-400">
                {{ t('Remember this browser') }}
            </label>

            <button class="btn-primary w-full" :disabled="form.processing">{{ t('Sign in') }}</button>

            <footer class="border-t border-slate-200 dark:border-white/10 pt-4 text-center text-xs text-slate-500">
                {{ t('Troubleshooting or improvement?') }}
                <a :href="githubIssuesUrl" class="font-medium text-slate-500 dark:text-slate-400 transition hover:text-sky-600 dark:hover:text-sky-300" target="_blank" rel="noopener noreferrer">{{ t('Open an issue') }}</a>
            </footer>
        </form>
    </main>
</template>
