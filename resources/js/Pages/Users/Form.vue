<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { languageNames, useI18n } from '@/i18n';

const props = defineProps<{
    managedUser: any | null;
    roles: string[];
    hostAccessModes: string[];
    hosts: any[];
    locales: string[];
}>();

const { t } = useI18n();
const editing = computed(() => Boolean(props.managedUser));
const languageName = (locale: string) => languageNames[locale as keyof typeof languageNames] || locale;
const form = useForm({
    name: props.managedUser?.name || '',
    email: props.managedUser?.email || '',
    role: props.managedUser?.role || 'user',
    host_access_mode: props.managedUser?.host_access_mode || 'all',
    host_ids: props.managedUser?.hosts?.map((host: any) => host.id) || [],
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

const toggleHost = (hostId: number) => {
    if (form.host_ids.includes(hostId)) {
        form.host_ids = form.host_ids.filter((id: number) => id !== hostId);
        return;
    }

    form.host_ids = [...form.host_ids, hostId];
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

            <section v-if="form.role !== 'admin'" class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="font-semibold text-white">{{ t('Host access') }}</h2>
                        <p class="mt-1 text-sm text-slate-400">{{ t('Choose whether this user can see all hosts or only selected hosts.') }}</p>
                    </div>
                    <label class="flex shrink-0 items-center gap-3 rounded-xl border border-white/10 bg-slate-950/60 px-3 py-2 text-sm">
                        <input v-model="form.host_access_mode" type="checkbox" true-value="all" false-value="selected" class="rounded border-slate-600 bg-slate-950 text-sky-400">
                        {{ t('Access all hosts') }}
                    </label>
                </div>

                <div v-if="form.host_access_mode === 'selected'" class="mt-4 grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="host in hosts"
                        :key="host.id"
                        type="button"
                        class="flex items-start gap-3 rounded-xl border p-3 text-left text-sm transition"
                        :class="form.host_ids.includes(host.id) ? 'border-sky-300/60 bg-sky-400/10 text-sky-50' : 'border-white/10 bg-slate-950/50 text-slate-300 hover:bg-white/10'"
                        @click="toggleHost(host.id)"
                    >
                        <input :checked="form.host_ids.includes(host.id)" type="checkbox" class="mt-1 rounded border-slate-600 bg-slate-950 text-sky-400" tabindex="-1" readonly>
                        <span class="min-w-0">
                            <span class="block break-words font-medium">{{ host.name }}</span>
                            <span class="mt-1 block text-xs text-slate-400">{{ t(host.type) }} / {{ t(host.status) }}</span>
                        </span>
                    </button>
                </div>
                <span v-if="form.errors.host_ids" class="mt-2 block text-sm text-rose-300">{{ form.errors.host_ids }}</span>
            </section>

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
