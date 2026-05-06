<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { languageNames, useI18n } from '@/i18n';

const props = defineProps<{
    managedUser: any | null;
    roles: string[];
    locales: string[];
}>();

const { t } = useI18n();
const editing = computed(() => Boolean(props.managedUser));
const languageName = (locale: string) => languageNames[locale as keyof typeof languageNames] || locale;
const form = useForm({
    name: props.managedUser?.name || '',
    email: props.managedUser?.email || '',
    role: props.managedUser?.role || 'user',
    locale: props.managedUser?.locale || 'en',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    if (editing.value) {
        form.put(`/users/${props.managedUser.id}`);
        return;
    }

    form.post('/users');
};
</script>

<template>
    <Head :title="editing ? t('Edit user') : t('New user')" />
    <AppLayout :title="editing ? t('Edit user') : t('New user')" :subtitle="t('Set account access, language, and login credentials.')">
        <form class="card max-w-2xl space-y-5 p-4 sm:p-6" @submit.prevent="submit">
            <label class="space-y-2">
                <span class="label">{{ t('Name') }}</span>
                <input v-model="form.name" class="input" required>
                <span v-if="form.errors.name" class="text-sm text-rose-300">{{ form.errors.name }}</span>
            </label>

            <label class="space-y-2">
                <span class="label">{{ t('Email') }}</span>
                <input v-model="form.email" class="input" type="email" required>
                <span v-if="form.errors.email" class="text-sm text-rose-300">{{ form.errors.email }}</span>
            </label>

            <label class="space-y-2">
                <span class="label">{{ t('Role') }}</span>
                <select v-model="form.role" class="input">
                    <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                </select>
                <span v-if="form.errors.role" class="text-sm text-rose-300">{{ form.errors.role }}</span>
            </label>

            <label class="space-y-2">
                <span class="label">{{ t('Language') }}</span>
                <select v-model="form.locale" class="input">
                    <option v-for="locale in locales" :key="locale" :value="locale">{{ languageName(locale) }}</option>
                </select>
                <span v-if="form.errors.locale" class="text-sm text-rose-300">{{ form.errors.locale }}</span>
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2">
                    <span class="label">{{ t('Password') }}</span>
                    <PasswordInput v-model="form.password" :required="!editing" autocomplete="new-password" />
                    <span v-if="editing" class="text-xs text-slate-400">{{ t('Leave empty to keep the current password.') }}</span>
                    <span v-if="form.errors.password" class="block text-sm text-rose-300">{{ form.errors.password }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Confirm password') }}</span>
                    <PasswordInput v-model="form.password_confirmation" :required="!editing" autocomplete="new-password" />
                </label>
            </div>

            <div class="flex flex-wrap gap-3">
                <button class="btn-primary" :disabled="form.processing">{{ editing ? t('Update user') : t('Create user') }}</button>
                <Link href="/users" class="btn-secondary">{{ t('Cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
