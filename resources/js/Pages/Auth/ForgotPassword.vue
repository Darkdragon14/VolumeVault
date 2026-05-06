<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    mailResetEnabled?: boolean;
}>();

const page = usePage();
const success = computed(() => (page.props.flash as any)?.success);
const form = useForm({
    email: '',
});

const submit = () => form.post('/forgot-password');
</script>

<template>
    <Head title="Forgot password" />
    <main class="auth-shell">
        <form class="card w-full max-w-md space-y-5 p-4 sm:p-6" @submit.prevent="submit">
            <div>
                <img :src="'/logo.png'" alt="VolumeVault" class="mb-4 h-16 w-auto object-contain">
                <h1 class="text-2xl font-bold text-white">Reset your password</h1>
                <p class="mt-1 text-sm text-slate-400">Enter your account email and VolumeVault will send a reset link if mail is configured.</p>
            </div>

            <div v-if="!mailResetEnabled" class="rounded-2xl border border-amber-400/30 bg-amber-400/10 p-3 text-sm text-amber-100">
                Password reset by email is not configured. Use the CLI reset command from the container instead.
            </div>

            <div v-if="success" class="rounded-2xl border border-emerald-400/30 bg-emerald-400/10 p-3 text-sm text-emerald-100">{{ success }}</div>

            <label class="space-y-2">
                <span class="label">Email</span>
                <input v-model="form.email" class="input" type="email" required autofocus autocomplete="email">
                <span v-if="form.errors.email" class="text-sm text-rose-300">{{ form.errors.email }}</span>
            </label>

            <button class="btn-primary w-full" :disabled="form.processing || !mailResetEnabled">Send reset link</button>

            <footer class="border-t border-white/10 pt-4 text-center text-sm text-slate-400">
                <Link href="/login" class="font-medium text-sky-300 transition hover:text-sky-200">Back to sign in</Link>
            </footer>
        </form>
    </main>
</template>
