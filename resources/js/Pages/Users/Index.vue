<script setup lang="ts">
import ActionIcon from '@/Components/ActionIcon.vue';
import Pagination from '@/Components/Pagination.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { languageNames, useI18n } from '@/i18n';

interface PaginatedData<T> {
    data: T[];
    meta: { current_page: number; per_page: number; total: number; last_page: number };
}

defineProps<{ users: PaginatedData<any> }>();

const page = usePage();
const { t, formatDate } = useI18n();
const auth = page.props.auth as { user?: { id: number } | null };
const languageName = (locale: string) => languageNames[locale as keyof typeof languageNames] || locale;
const destroyUser = (id: number) => {
    if (confirm(t('Delete this user?'))) router.delete(`/users/${id}`);
};
const resetTwoFactor = (id: number) => {
    if (confirm(t('Reset two-factor authentication for this user?'))) router.delete(`/users/${id}/two-factor`, { preserveScroll: true });
};
</script>

<template>
    <Head :title="t('Users')" />
    <AppLayout :title="t('Users')" :subtitle="t('Manage team access, roles, and preferred languages.')">
        <template #title-actions>
            <ActionIcon :label="t('New user')" icon="add" href="/users/create" />
        </template>

        <template #actions>
            <Link href="/users/create" class="btn-primary hidden sm:inline-flex">{{ t('New user') }}</Link>
        </template>

        <div class="card overflow-hidden">
            <div class="md:hidden">
                <article v-for="user in users.data" :key="user.id" class="space-y-4 border-b border-white/10 p-4 last:border-b-0">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="break-words font-semibold text-white">{{ user.name }}</h2>
                            <p class="mt-1 break-all text-sm text-slate-400">{{ user.email }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <ActionIcon :label="t('Edit')" icon="edit" :href="`/users/${user.id}/edit`" />
                            <ActionIcon v-if="user.two_factor_confirmed_at && auth.user?.id !== user.id" :label="t('Reset two-factor authentication')" icon="refresh" variant="danger" @click="resetTwoFactor(user.id)" />
                            <ActionIcon :label="t('Delete')" icon="delete" variant="danger" :disabled="auth.user?.id === user.id" @click="destroyUser(user.id)" />
                        </div>
                    </div>
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-xs uppercase text-slate-500">{{ t('Role') }}</dt><dd class="mt-1 text-slate-200">{{ user.role }}</dd></div>
                        <div><dt class="text-xs uppercase text-slate-500">{{ t('Language') }}</dt><dd class="mt-1 text-slate-200">{{ languageName(user.locale) }}</dd></div>
                        <div class="col-span-2"><dt class="text-xs uppercase text-slate-500">{{ t('Created') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(user.created_at) }}</dd></div>
                    </dl>
                </article>
            </div>
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-white/10 text-sm">
                    <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-3">{{ t('Name') }}</th>
                            <th class="px-4 py-3">{{ t('Email') }}</th>
                            <th class="px-4 py-3">{{ t('Role') }}</th>
                            <th class="px-4 py-3">{{ t('Language') }}</th>
                            <th class="px-4 py-3">{{ t('Created') }}</th>
                            <th class="px-4 py-3">{{ t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <tr v-for="user in users.data" :key="user.id" class="hover:bg-slate-100 dark:hover:bg-white/[0.03]">
                            <td class="px-4 py-3 font-medium text-white">{{ user.name }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ user.email }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ user.role }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ languageName(user.locale) }}</td>
                            <td class="px-4 py-3 text-slate-300">{{ formatDate(user.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-2">
                                    <ActionIcon :label="t('Edit')" icon="edit" :href="`/users/${user.id}/edit`" />
                                    <ActionIcon v-if="user.two_factor_confirmed_at && auth.user?.id !== user.id" :label="t('Reset two-factor authentication')" icon="refresh" variant="danger" @click="resetTwoFactor(user.id)" />
                                    <ActionIcon :label="t('Delete')" icon="delete" variant="danger" :disabled="auth.user?.id === user.id" @click="destroyUser(user.id)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :data="users" base-url="/users" />
        </div>
    </AppLayout>
</template>
