<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from '@/i18n';

const { t } = useI18n();

const useRecovery = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
    trust_device: false,
});

const submit = () => form.post('/two-factor-challenge');

const toggleRecovery = () => {
    useRecovery.value = !useRecovery.value;
    form.clearErrors();
    form.code = '';
    form.recovery_code = '';
};
</script>

<template>
    <Head :title="t('Two-factor authentication')" />
    <main class="auth-shell">
        <form class="card w-full max-w-md space-y-5 p-4 sm:p-6" @submit.prevent="submit">
            <div>
                <img :src="'/logo.png'" alt="VolumeVault" class="mb-4 h-16 w-auto object-contain">
                <h1 class="text-2xl font-bold text-white">{{ t('Two-factor authentication') }}</h1>
                <p class="mt-1 text-sm text-slate-400">
                    {{ useRecovery
                        ? t('Enter one of your recovery codes to continue.')
                        : t('Enter the code from your authenticator app to continue.') }}
                </p>
            </div>

            <label v-if="!useRecovery" class="space-y-2">
                <span class="label">{{ t('Authentication code') }}</span>
                <input id="otp" v-model="form.code" name="otp" class="input" type="text" inputmode="numeric"
                    autocomplete="one-time-code" autofocus required maxlength="6" placeholder="000000">
                <span v-if="form.errors.code" class="text-sm text-rose-300">{{ form.errors.code }}</span>
            </label>

            <label v-else class="space-y-2">
                <span class="label">{{ t('Recovery code') }}</span>
                <input id="recovery-code" v-model="form.recovery_code" name="recovery_code" class="input" type="text"
                    autocomplete="off" autofocus required>
                <span v-if="form.errors.code" class="text-sm text-rose-300">{{ form.errors.code }}</span>
            </label>

            <label class="flex items-center gap-3 text-sm text-slate-300">
                <input v-model="form.trust_device" type="checkbox" class="rounded border-slate-600 bg-slate-950 text-sky-400">
                {{ t('Trust this device for 30 days') }}
            </label>

            <button class="btn-primary w-full" :disabled="form.processing">{{ t('Verify') }}</button>

            <div class="text-center text-sm">
                <button type="button" class="font-medium text-sky-300 transition hover:text-sky-200" @click="toggleRecovery">
                    {{ useRecovery ? t('Use an authenticator code') : t('Use a recovery code') }}
                </button>
            </div>

            <footer class="border-t border-white/10 pt-4 text-center text-xs text-slate-500">
                <Link href="/login" method="get" class="font-medium text-slate-400 transition hover:text-sky-300">{{ t('Back to sign in') }}</Link>
            </footer>
        </form>
    </main>
</template>
