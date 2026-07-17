<script setup lang="ts">
import ActionIcon from '@/Components/ActionIcon.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/i18n';

const props = defineProps<{
    hosts: any[];
    limits: {
        active: number;
        active_limit: number;
        can_create_active_host: boolean;
    };
    enrollmentToken: string | null;
}>();

const { t, formatDate } = useI18n();
const form = useForm({ name: '' });

const activeLabel = computed(() => t('{count} of {limit} active hosts', { count: props.limits.active, limit: props.limits.active_limit }));

const createHost = () => {
    form.post('/hosts', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const activate = (hostId: number) => router.post(`/hosts/${hostId}/activate`, {}, { preserveScroll: true });
const deactivate = (hostId: number) => router.post(`/hosts/${hostId}/deactivate`, {}, { preserveScroll: true });
const regenerateToken = (hostId: number) => router.post(`/hosts/${hostId}/enrollment-token`, {}, { preserveScroll: true });
</script>

<template>
    <Head :title="t('Hosts')" />
    <AppLayout :title="t('Hosts')" :subtitle="t('Manage local and agent Docker hosts.')">
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="card overflow-hidden">
                <div class="border-b border-white/10 p-4 sm:p-5">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <h2 class="text-lg font-semibold text-white">{{ t('Docker hosts') }}</h2>
                        <p class="text-sm text-slate-400">{{ activeLabel }}</p>
                    </div>
                </div>

                <div class="divide-y divide-white/10 md:hidden">
                    <article v-for="host in hosts" :key="host.id" class="space-y-4 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="break-words font-semibold text-white">{{ host.name }}</h3>
                                <p class="mt-1 text-sm text-slate-400">{{ t(host.type) }}</p>
                            </div>
                            <StatusBadge :status="host.status" />
                        </div>
                        <dl class="grid grid-cols-2 gap-3 text-sm">
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Active') }}</dt><dd class="mt-1 text-slate-200">{{ host.is_active ? t('Yes') : t('No') }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Last heartbeat') }}</dt><dd class="mt-1 text-slate-200">{{ formatDate(host.last_seen_at) }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Docker version') }}</dt><dd class="mt-1 text-slate-200">{{ host.docker_version || t('Unknown') }}</dd></div>
                            <div><dt class="text-xs uppercase text-slate-500">{{ t('Agent version') }}</dt><dd class="mt-1 text-slate-200">{{ host.agent_version || t('Unknown') }}</dd></div>
                        </dl>
                        <div v-if="host.type === 'agent'" class="flex flex-wrap gap-2">
                            <ActionIcon v-if="host.is_active" :label="t('Deactivate')" icon="pause" @click="deactivate(host.id)" />
                            <ActionIcon v-else :label="t('Activate')" icon="play" @click="activate(host.id)" />
                            <ActionIcon :label="t('Regenerate enrollment token')" icon="token" @click="regenerateToken(host.id)" />
                        </div>
                    </article>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-white/10 text-sm">
                        <thead class="bg-white/5 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-4 py-3">{{ t('Name') }}</th>
                                <th class="px-4 py-3">{{ t('Type') }}</th>
                                <th class="px-4 py-3">{{ t('Status') }}</th>
                                <th class="px-4 py-3">{{ t('Active') }}</th>
                                <th class="px-4 py-3">{{ t('Last heartbeat') }}</th>
                                <th class="px-4 py-3">{{ t('Docker version') }}</th>
                                <th class="px-4 py-3">{{ t('Agent version') }}</th>
                                <th class="px-4 py-3">{{ t('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            <tr v-for="host in hosts" :key="host.id" class="hover:bg-white/[0.03]">
                                <td class="px-4 py-3 font-medium text-white">{{ host.name }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ t(host.type) }}</td>
                                <td class="px-4 py-3"><StatusBadge :status="host.status" /></td>
                                <td class="px-4 py-3 text-slate-300">{{ host.is_active ? t('Yes') : t('No') }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ formatDate(host.last_seen_at) }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ host.docker_version || t('Unknown') }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ host.agent_version || t('Unknown') }}</td>
                                <td class="px-4 py-3">
                                    <div v-if="host.type === 'agent'" class="flex flex-wrap gap-2">
                                        <ActionIcon v-if="host.is_active" :label="t('Deactivate')" icon="pause" @click="deactivate(host.id)" />
                                        <ActionIcon v-else :label="t('Activate')" icon="play" @click="activate(host.id)" />
                                        <ActionIcon :label="t('Regenerate enrollment token')" icon="token" @click="regenerateToken(host.id)" />
                                    </div>
                                    <span v-else class="text-slate-500">{{ t('None') }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <aside class="space-y-5">
                <form class="card space-y-4 p-4 sm:p-5" @submit.prevent="createHost">
                    <div>
                        <h2 class="font-semibold text-white">{{ t('New agent host') }}</h2>
                        <p class="mt-1 text-sm text-slate-400">{{ activeLabel }}</p>
                    </div>
                    <label class="space-y-2">
                        <span class="label">{{ t('Name') }}</span>
                        <input v-model="form.name" class="input" required placeholder="Lab server">
                        <span v-if="form.errors.name" class="text-sm text-rose-300">{{ form.errors.name }}</span>
                        <span v-if="form.errors.host" class="text-sm text-rose-300">{{ form.errors.host }}</span>
                    </label>
                    <button class="btn-primary w-full justify-center" :disabled="form.processing || !limits.can_create_active_host">{{ t('Create host') }}</button>
                </form>

                <section v-if="enrollmentToken" class="card space-y-3 border-sky-300/30 bg-sky-400/10 p-4 sm:p-5">
                    <h2 class="font-semibold text-sky-50">{{ t('Enrollment token') }}</h2>
                    <p class="text-sm text-sky-100">{{ t('Copy this token now. It will not be shown again.') }}</p>
                    <code class="block break-all rounded-xl border border-sky-200/20 bg-slate-950/80 p-3 text-xs text-sky-50">{{ enrollmentToken }}</code>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
