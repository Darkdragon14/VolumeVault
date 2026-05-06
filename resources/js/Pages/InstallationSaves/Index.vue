<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

const props = defineProps<{
    destinations: any[];
}>();
const { t } = useI18n();

const uploadForm = useForm({
    backup_destination_id: props.destinations[0]?.id || '',
});

const upload = () => uploadForm.post('/installation-save/upload');
</script>

<template>
    <Head :title="t('Installation save')" />
    <AppLayout :title="t('Installation save')" :subtitle="t('Export or upload an encrypted snapshot of this VolumeVault installation.')">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
            <section class="card space-y-5 p-4 sm:p-6">
                <div>
                    <h2 class="text-xl font-semibold text-white">{{ t('Secure save') }}</h2>
                    <p class="mt-2 text-sm text-slate-400">
                        Creates an encrypted .vvsave from the Laravel storage volume. The archive is locked with this instance APP_KEY and does not include the key.
                    </p>
                </div>

                <div class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-100">
                    Keep APP_KEY outside the save. It is required to unlock this file during onboarding import, and losing it makes encrypted destination credentials unrecoverable.
                </div>

                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                        <dt class="text-xs uppercase tracking-wide text-slate-400">{{ t('Included') }}</dt>
                        <dd class="mt-2 text-slate-200">SQLite database and useful files from storage.</dd>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                        <dt class="text-xs uppercase tracking-wide text-slate-400">{{ t('Excluded') }}</dt>
                        <dd class="mt-2 text-slate-200">APP_KEY, sessions, cache, queued jobs, temporary restore files, and logs.</dd>
                    </div>
                </dl>

                <a href="/installation-save/download" class="btn-primary inline-flex">{{ t('Download secure save') }}</a>
            </section>

            <form class="card space-y-5 p-4 sm:p-6" @submit.prevent="upload">
                <div>
                    <h2 class="text-xl font-semibold text-white">{{ t('Upload to destination') }}</h2>
                    <p class="mt-2 text-sm text-slate-400">Pushes the encrypted .vvsave to an active backup destination under installation-saves/ when the provider supports paths.</p>
                </div>

                <div v-if="!destinations.length" class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-100">
                    Create and activate a backup destination before uploading installation saves.
                </div>

                <label v-else class="space-y-2">
                    <span class="label">{{ t('Destination') }}</span>
                    <select v-model="uploadForm.backup_destination_id" class="input" required>
                        <option v-for="destination in destinations" :key="destination.id" :value="destination.id">
                            {{ destination.name }} / {{ destination.target_label || destination.bucket }}
                        </option>
                    </select>
                    <span v-if="uploadForm.errors.backup_destination_id" class="text-sm text-rose-300">{{ uploadForm.errors.backup_destination_id }}</span>
                </label>

                <button class="btn-primary" :disabled="uploadForm.processing || !destinations.length">{{ t('Upload secure save') }}</button>
            </form>
        </div>
    </AppLayout>
</template>
