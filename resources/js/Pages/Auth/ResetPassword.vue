<script setup lang="ts">
import PasswordInput from '@/Components/PasswordInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    email?: string;
    token: string;
}>();

const form = useForm({
    token: props.token,
    email: props.email || '',
    password: '',
    password_confirmation: '',
});

const submit = () => form.post('/reset-password');
</script>

<template>
    <Head title="Reset password" />
    <main class="auth-shell">
        <form class="card w-full max-w-md space-y-5 p-4 sm:p-6" @submit.prevent="submit">
            <div>
                <img :src="'/logo.png'" alt="VolumeVault" class="mb-4 h-16 w-auto object-contain">
                <h1 class="text-2xl font-bold text-white">Choose a new password</h1>
                <p class="mt-1 text-sm text-slate-400">This will invalidate existing browser sessions for the account.</p>
            </div>

            <label class="space-y-2">
                <span class="label">Email</span>
                <input v-model="form.email" class="input" type="email" required autocomplete="email">
                <span v-if="form.errors.email" class="text-sm text-rose-300">{{ form.errors.email }}</span>
            </label>

            <label class="space-y-2">
                <span class="label">New password</span>
                <PasswordInput v-model="form.password" required autocomplete="new-password" />
                <span v-if="form.errors.password" class="text-sm text-rose-300">{{ form.errors.password }}</span>
            </label>

            <label class="space-y-2">
                <span class="label">Confirm password</span>
                <PasswordInput v-model="form.password_confirmation" required autocomplete="new-password" />
            </label>

            <button class="btn-primary w-full" :disabled="form.processing">Reset password</button>

            <footer class="border-t border-white/10 pt-4 text-center text-sm text-slate-400">
                <Link href="/login" class="font-medium text-sky-300 transition hover:text-sky-200">Back to sign in</Link>
            </footer>
        </form>
    </main>
</template>
