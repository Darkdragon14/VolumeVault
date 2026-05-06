<script setup lang="ts">
import ActionIcon from '@/Components/ActionIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

defineProps<{
    users: any[];
    tokens: any[];
}>();

const page = usePage();
const flash = page.props.flash as { api_token?: string };
const { t, formatDate } = useI18n();
const form = useForm({
    user_id: '',
    name: '',
    abilities: ['read'],
    expires_at: '',
});

const submit = () => form.post('/api-tokens', { preserveScroll: true });
const revoke = (id: number) => confirm('Revoke this API token?') && router.delete(`/api-tokens/${id}`, { preserveScroll: true });
</script>

<template>
    <Head :title="t('API tokens')" />
    <AppLayout :title="t('API tokens')" :subtitle="t('Issue scoped Bearer tokens for external automation.')">
        <div v-if="flash.api_token" class="mb-6 rounded-2xl border border-amber-300/40 bg-amber-300/10 p-4 text-sm text-amber-50">
            <p class="font-semibold">Copy this token now. It will not be shown again.</p>
            <code class="mt-3 block overflow-x-auto rounded-xl bg-slate-950/80 p-3 text-xs text-amber-100">{{ flash.api_token }}</code>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]">
            <form class="card space-y-5 p-4 sm:p-6" @submit.prevent="submit">
                <div>
                    <h2 class="text-lg font-semibold text-white">Create token</h2>
                    <p class="mt-1 text-sm text-slate-400">Use Bearer tokens for external API calls. Prefer read-only tokens unless writes are required.</p>
                </div>

                <label class="space-y-2">
                    <span class="label">{{ t('Users') }}</span>
                    <select v-model="form.user_id" class="input" required>
                        <option value="" disabled>Select a user</option>
                        <option v-for="user in users" :key="user.id" :value="user.id">
                            {{ user.name }} ({{ user.email }})
                        </option>
                    </select>
                    <span v-if="form.errors.user_id" class="text-sm text-rose-300">{{ form.errors.user_id }}</span>
                </label>

                <label class="space-y-2">
                    <span class="label">{{ t('Name') }}</span>
                    <input v-model="form.name" class="input" placeholder="openclaw-prod" required>
                    <span v-if="form.errors.name" class="text-sm text-rose-300">{{ form.errors.name }}</span>
                </label>

                <div class="space-y-2">
                    <span class="label">Abilities</span>
                    <label class="flex items-center gap-3 text-sm text-slate-300">
                        <input v-model="form.abilities" type="checkbox" value="read" class="rounded border-white/20 bg-slate-950">
                        read
                    </label>
                    <label class="flex items-center gap-3 text-sm text-slate-300">
                        <input v-model="form.abilities" type="checkbox" value="write" class="rounded border-white/20 bg-slate-950">
                        write
                    </label>
                    <span v-if="form.errors.abilities" class="text-sm text-rose-300">{{ form.errors.abilities }}</span>
                </div>

                <label class="space-y-2">
                    <span class="label">Expires at</span>
                    <input v-model="form.expires_at" class="input" type="datetime-local">
                    <span class="text-xs text-slate-400">Leave empty for no expiration.</span>
                    <span v-if="form.errors.expires_at" class="block text-sm text-rose-300">{{ form.errors.expires_at }}</span>
                </label>

                <button class="btn-primary" :disabled="form.processing">Create token</button>
            </form>

            <div class="card overflow-hidden">
                <div class="border-b border-white/10 p-5">
                    <h2 class="text-lg font-semibold text-white">Existing tokens</h2>
                    <p class="mt-1 text-sm text-slate-400">Secrets are stored hashed and cannot be recovered.</p>
                </div>
                <div class="md:hidden">
                    <div v-if="tokens.length" class="divide-y divide-white/10">
                        <article v-for="token in tokens" :key="token.id" class="space-y-4 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="break-words font-semibold text-white">{{ token.name }}</h3>
                                    <p class="mt-1 break-all text-sm text-slate-400">{{ token.user?.email || t('Deleted user') }}</p>
                                </div>
                                <ActionIcon label="Revoke" icon="token" variant="danger" @click="revoke(token.id)" />
                            </div>
                            <dl class="grid gap-3 text-sm">
                                <div><dt class="text-xs uppercase text-slate-500">Abilities</dt><dd class="mt-1 break-words text-slate-200">{{ token.abilities.join(', ') }}</dd></div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div><dt class="text-xs uppercase text-slate-500">Last used</dt><dd class="mt-1 text-slate-200">{{ formatDate(token.last_used_at) }}</dd></div>
                                    <div><dt class="text-xs uppercase text-slate-500">Expires</dt><dd class="mt-1 text-slate-200">{{ formatDate(token.expires_at) }}</dd></div>
                                </div>
                            </dl>
                        </article>
                    </div>
                    <p v-else class="p-8 text-center text-sm text-slate-400">No API tokens yet.</p>
                </div>
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-white/10 text-sm">
                        <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-4 py-3">{{ t('Name') }}</th>
                                <th class="px-4 py-3">{{ t('Users') }}</th>
                                <th class="px-4 py-3">Abilities</th>
                                <th class="px-4 py-3">Last used</th>
                                <th class="px-4 py-3">Expires</th>
                                <th class="px-4 py-3">{{ t('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            <tr v-for="token in tokens" :key="token.id" class="hover:bg-white/[0.03]">
                                <td class="px-4 py-3 font-medium text-white">{{ token.name }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ token.user?.email || t('Deleted user') }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ token.abilities.join(', ') }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ formatDate(token.last_used_at) }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ formatDate(token.expires_at) }}</td>
                                <td class="px-4 py-3">
                                    <ActionIcon label="Revoke" icon="token" variant="danger" @click="revoke(token.id)" />
                                </td>
                            </tr>
                            <tr v-if="tokens.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-slate-400">No API tokens yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
