<script setup lang="ts">
import PasswordInput from '@/Components/PasswordInput.vue';
import { useI18n } from '@/i18n';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const { t } = useI18n();

const mode = ref<'choice' | 'new' | 'import'>('choice');

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const importForm = useForm({
    save: null as File | null,
    previous_app_key: '',
});

const githubIssuesUrl = 'https://github.com/Darkdragon14/VolumeVault/issues';
const submit = () => form.post('/onboarding');
const submitImport = () => importForm.post('/onboarding/import', { forceFormData: true });
const selectSave = (event: Event) => {
    importForm.save = (event.target as HTMLInputElement).files?.[0] || null;
};
</script>

<template>
    <Head :title="t('Onboarding')" />
    <main class="auth-shell">
        <section v-if="mode === 'choice'" class="card w-full max-w-3xl flex flex-col gap-6 p-4 sm:p-6">
            <div>
                <img :src="'/logo.png'" alt="VolumeVault" class="mb-4 h-16 w-auto object-contain">
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">{{ t('Set up VolumeVault') }}</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('Start fresh or import a secure save from an existing installation.') }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <button class="rounded-2xl border border-sky-300/40 bg-sky-300/10 p-5 text-left hover:bg-sky-300/15" @click="mode = 'new'">
                    <span class="block text-lg font-semibold text-slate-900 dark:text-white">{{ t('New installation') }}</span>
                    <span class="mt-2 block text-sm text-slate-600 dark:text-slate-300">{{ t('Create the first administrator and configure destinations afterward.') }}</span>
                </button>
                <button class="rounded-2xl border border-emerald-300/40 bg-emerald-300/10 p-5 text-left hover:bg-emerald-300/15" @click="mode = 'import'">
                    <span class="block text-lg font-semibold text-slate-900 dark:text-white">{{ t('Import existing installation') }}</span>
                    <span class="mt-2 block text-sm text-slate-600 dark:text-slate-300">{{ t('Restore a .vvsave with the previous APP_KEY used to unlock it.') }}</span>
                </button>
            </div>

            <div class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-800 dark:text-amber-100">
                {{ t('Secure saves do not contain APP_KEY. Keep the old key available before migrating.') }}
            </div>

            <footer class="border-t border-slate-200 dark:border-white/10 pt-4 text-center text-xs text-slate-500">
                {{ t('Troubleshooting or improvement?') }}
                <a :href="githubIssuesUrl" class="font-medium text-slate-500 dark:text-slate-400 transition hover:text-sky-600 dark:hover:text-sky-300" target="_blank" rel="noopener noreferrer">{{ t('Open an issue') }}</a>
            </footer>
        </section>

        <form v-if="mode === 'new'" class="card w-full max-w-xl flex flex-col gap-5 p-4 sm:p-6" @submit.prevent="submit">
            <div>
                <button class="mb-4 text-sm text-sky-700 dark:text-sky-300 hover:text-sky-600 dark:hover:text-sky-200" type="button" @click="mode = 'choice'">{{ t('Back') }}</button>
                <img :src="'/logo.png'" alt="VolumeVault" class="mb-4 h-16 w-auto object-contain">
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">{{ t('Create the first administrator') }}</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('The first account is automatically admin and can manage users, encrypted destinations, notifications, restores, and Docker actions.') }}</p>
            </div>

            <label class="flex flex-col gap-2">
                <span class="label">{{ t('Name') }}</span>
                <input v-model="form.name" class="input" required autofocus autocomplete="name">
                <span v-if="form.errors.name" class="text-sm text-rose-700 dark:text-rose-300">{{ form.errors.name }}</span>
            </label>

            <label class="flex flex-col gap-2">
                <span class="label">{{ t('Email') }}</span>
                <input v-model="form.email" class="input" type="email" required autocomplete="email">
                <span v-if="form.errors.email" class="text-sm text-rose-700 dark:text-rose-300">{{ form.errors.email }}</span>
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="flex flex-col gap-2">
                    <span class="label">{{ t('Password') }}</span>
                    <PasswordInput v-model="form.password" required autocomplete="new-password" />
                    <span v-if="form.errors.password" class="text-sm text-rose-700 dark:text-rose-300">{{ form.errors.password }}</span>
                </label>
                <label class="flex flex-col gap-2">
                    <span class="label">{{ t('Confirm password') }}</span>
                    <PasswordInput v-model="form.password_confirmation" required autocomplete="new-password" />
                </label>
            </div>

            <div class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-800 dark:text-amber-100">
                {{ t('Admin access controls credentials and Docker operations. Use a strong password and keep APP_KEY backed up.') }}
            </div>

            <button class="btn-primary w-full" :disabled="form.processing">{{ t('Create admin account') }}</button>

            <footer class="border-t border-slate-200 dark:border-white/10 pt-4 text-center text-xs text-slate-500">
                {{ t('Troubleshooting or improvement?') }}
                <a :href="githubIssuesUrl" class="font-medium text-slate-500 dark:text-slate-400 transition hover:text-sky-600 dark:hover:text-sky-300" target="_blank" rel="noopener noreferrer">{{ t('Open an issue') }}</a>
            </footer>
        </form>

        <form v-if="mode === 'import'" class="card w-full max-w-xl flex flex-col gap-5 p-4 sm:p-6" @submit.prevent="submitImport">
            <div>
                <button class="mb-4 text-sm text-sky-700 dark:text-sky-300 hover:text-sky-600 dark:hover:text-sky-200" type="button" @click="mode = 'choice'">{{ t('Back') }}</button>
                <img :src="'/logo.png'" alt="VolumeVault" class="mb-4 h-16 w-auto object-contain">
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">{{ t('Import an existing installation') }}</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('Upload a secure .vvsave and provide the APP_KEY from the previous installation.') }}</p>
            </div>

            <label class="flex flex-col gap-2">
                <span class="label">{{ t('Secure save file') }}</span>
                <input class="input" type="file" accept=".vvsave,application/octet-stream" required @change="selectSave">
                <span v-if="importForm.errors.save" class="text-sm text-rose-700 dark:text-rose-300">{{ importForm.errors.save }}</span>
            </label>

            <label class="flex flex-col gap-2">
                <span class="label">{{ t('Previous APP_KEY') }}</span>
                <input v-model="importForm.previous_app_key" class="input" required autocomplete="off" placeholder="base64:...">
                <span v-if="importForm.errors.previous_app_key" class="text-sm text-rose-700 dark:text-rose-300">{{ importForm.errors.previous_app_key }}</span>
            </label>

            <div class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-800 dark:text-amber-100">
                {{ t('Import replaces this instance storage. Continue only on a fresh installation before creating users.') }}
            </div>

            <button class="btn-primary w-full" :disabled="importForm.processing || !importForm.save">{{ t('Import installation') }}</button>

            <footer class="border-t border-slate-200 dark:border-white/10 pt-4 text-center text-xs text-slate-500">
                {{ t('Troubleshooting or improvement?') }}
                <a :href="githubIssuesUrl" class="font-medium text-slate-500 dark:text-slate-400 transition hover:text-sky-600 dark:hover:text-sky-300" target="_blank" rel="noopener noreferrer">{{ t('Open an issue') }}</a>
            </footer>
        </form>
    </main>
</template>
