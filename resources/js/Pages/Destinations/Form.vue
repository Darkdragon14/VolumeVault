<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/i18n';

type ProviderOption = {
    value: string;
    label: string;
    secret_fields: string[];
};

const props = defineProps<{
    destination: any | null;
    providers: ProviderOption[];
}>();

const editing = computed(() => Boolean(props.destination));
const { t } = useI18n();
const settings = props.destination?.settings || {};
const hasSecret = (field: string) => Boolean(props.destination?.has_secrets?.[field]);

const form = useForm({
    name: props.destination?.name || '',
    provider: props.destination?.provider || props.providers[0]?.value || 'aws_s3',
    endpoint: props.destination?.endpoint || '',
    region: props.destination?.region || 'us-east-1',
    bucket: props.destination?.bucket || '',
    path_prefix: props.destination?.path_prefix || '',
    access_key_id: '',
    secret_access_key: '',
    use_path_style_endpoint: props.destination?.use_path_style_endpoint || false,
    is_active: props.destination?.is_active ?? true,
    settings: {
        url: settings.url || '',
        path: settings.path || '',
        insecure: settings.insecure || false,
        host: settings.host || '',
        port: settings.port || 22,
        remote_path: settings.remote_path || '',
        identity_file: settings.identity_file || '',
        account_name: settings.account_name || '',
        container: settings.container || '',
        endpoint: settings.endpoint || '',
        access_tier: settings.access_tier || '',
        concurrency_level: settings.concurrency_level || 6,
        folder_id: settings.folder_id || '',
        impersonate_subject: settings.impersonate_subject || '',
        token_url: settings.token_url || '',
        archive_path: settings.archive_path || '',
        archive_mount_source: settings.archive_mount_source || '',
    },
    secrets: {
        username: '',
        password: '',
        user: '',
        private_key: '',
        private_key_passphrase: '',
        account_key: '',
        connection_string: '',
        app_key: '',
        app_secret: '',
        refresh_token: '',
        credentials_json: '',
    },
});

const selectedProvider = computed(() => props.providers.find((provider) => provider.value === form.provider));
const isS3 = computed(() => ['aws_s3', 'cloudflare_r2', 'custom_s3'].includes(form.provider));
const error = (key: string) => form.errors[key as keyof typeof form.errors];
const secretHint = (field: string) => editing.value && hasSecret(field) ? 'Already saved. Leave blank to keep existing value.' : '';

const submit = () => {
    if (editing.value) {
        form.put(`/destinations/${props.destination.id}`);
        return;
    }

    form.post('/destinations');
};
</script>

<template>
    <Head :title="editing ? t('Edit destination') : t('New destination')" />
    <AppLayout :title="editing ? t('Edit destination') : t('New destination')" :subtitle="t('Store destination settings and credentials encrypted at rest.')">
        <form class="card max-w-4xl space-y-5 p-4 sm:p-6" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2">
                    <span class="label">{{ t('Name') }}</span>
                    <input v-model="form.name" class="input" required>
                    <span v-if="form.errors.name" class="text-sm text-rose-300">{{ form.errors.name }}</span>
                </label>

                <label class="space-y-2">
                    <span class="label">{{ t('Provider') }}</span>
                    <select v-model="form.provider" class="input">
                        <option v-for="provider in providers" :key="provider.value" :value="provider.value">{{ provider.label }}</option>
                    </select>
                    <span class="text-xs text-slate-400">{{ selectedProvider?.value }}</span>
                </label>
            </div>

            <section v-if="isS3" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">Endpoint</span>
                    <input v-model="form.endpoint" class="input" placeholder="https://<account_id>.r2.cloudflarestorage.com">
                    <span class="text-xs text-slate-400">Required for Cloudflare R2 and custom S3. AWS S3 can usually stay empty.</span>
                    <span v-if="form.errors.endpoint" class="block text-sm text-rose-300">{{ form.errors.endpoint }}</span>
                </label>

                <label class="space-y-2">
                    <span class="label">Region</span>
                    <input v-model="form.region" class="input" placeholder="us-east-1">
                </label>

                <label class="space-y-2">
                    <span class="label">Bucket</span>
                    <input v-model="form.bucket" class="input" required>
                    <span v-if="form.errors.bucket" class="text-sm text-rose-300">{{ form.errors.bucket }}</span>
                </label>

                <label class="space-y-2 sm:col-span-2">
                    <span class="label">Path prefix</span>
                    <input v-model="form.path_prefix" class="input" placeholder="volumevault/backups">
                </label>

                <label class="space-y-2">
                    <span class="label">Access key ID</span>
                    <input v-model="form.access_key_id" class="input" :required="!editing" autocomplete="off">
                    <span v-if="editing" class="text-xs text-slate-400">Credentials are already saved. Leave blank to keep existing values.</span>
                </label>

                <label class="space-y-2">
                    <span class="label">Secret access key</span>
                    <PasswordInput v-model="form.secret_access_key" :required="!editing" autocomplete="new-password" />
                    <span v-if="editing" class="text-xs text-slate-400">Leave empty to keep the saved secret.</span>
                </label>
            </section>

            <section v-else-if="form.provider === 'webdav'" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">WebDAV URL</span>
                    <input v-model="form.settings.url" class="input" required placeholder="https://webdav.example.com">
                    <span v-if="error('settings.url')" class="text-sm text-rose-300">{{ error('settings.url') }}</span>
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">Remote path</span>
                    <input v-model="form.settings.path" class="input" placeholder="/backups/volumevault">
                </label>
                <label class="space-y-2">
                    <span class="label">Username</span>
                    <input v-model="form.secrets.username" class="input" autocomplete="off">
                    <span class="text-xs text-slate-400">{{ secretHint('username') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">Password</span>
                    <PasswordInput v-model="form.secrets.password" autocomplete="new-password" />
                    <span class="text-xs text-slate-400">{{ secretHint('password') }}</span>
                </label>
                <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-4 text-sm">
                    <input v-model="form.settings.insecure" type="checkbox" class="rounded border-slate-600 bg-slate-950 text-sky-400">
                    Disable TLS certificate verification
                </label>
            </section>

            <section v-else-if="form.provider === 'ssh'" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2">
                    <span class="label">SSH host</span>
                    <input v-model="form.settings.host" class="input" required placeholder="server.local">
                </label>
                <label class="space-y-2">
                    <span class="label">Port</span>
                    <input v-model="form.settings.port" class="input" type="number" min="1" max="65535">
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">Remote path</span>
                    <input v-model="form.settings.remote_path" class="input" required placeholder="/home/user/backups">
                </label>
                <label class="space-y-2">
                    <span class="label">Username</span>
                    <input v-model="form.secrets.user" class="input" :required="!editing || !hasSecret('user')" autocomplete="off">
                    <span class="text-xs text-slate-400">{{ secretHint('user') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">Password</span>
                    <PasswordInput v-model="form.secrets.password" autocomplete="new-password" />
                    <span class="text-xs text-slate-400">{{ secretHint('password') }}</span>
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">Private key</span>
                    <textarea v-model="form.secrets.private_key" class="input min-h-32" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea>
                    <span class="text-xs text-slate-400">{{ secretHint('private_key') || 'If provided, VolumeVault mounts it into the Offen container for backup runs.' }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">Private key passphrase</span>
                    <PasswordInput v-model="form.secrets.private_key_passphrase" autocomplete="new-password" />
                    <span class="text-xs text-slate-400">{{ secretHint('private_key_passphrase') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">Identity file path</span>
                    <input v-model="form.settings.identity_file" class="input" placeholder="/root/.ssh/id_rsa">
                    <span class="text-xs text-slate-400">Advanced: path already available inside the Offen container.</span>
                </label>
            </section>

            <section v-else-if="form.provider === 'azure_blob'" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2">
                    <span class="label">Account name</span>
                    <input v-model="form.settings.account_name" class="input" placeholder="account-name">
                </label>
                <label class="space-y-2">
                    <span class="label">Container</span>
                    <input v-model="form.settings.container" class="input" required placeholder="container-name">
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">Service endpoint</span>
                    <input v-model="form.settings.endpoint" class="input" placeholder="https://account.blob.core.windows.net">
                </label>
                <label class="space-y-2">
                    <span class="label">Account key</span>
                    <PasswordInput v-model="form.secrets.account_key" autocomplete="new-password" />
                    <span class="text-xs text-slate-400">{{ secretHint('account_key') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">Access tier</span>
                    <input v-model="form.settings.access_tier" class="input" placeholder="Cool">
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">Connection string</span>
                    <textarea v-model="form.secrets.connection_string" class="input min-h-24" autocomplete="off"></textarea>
                    <span class="text-xs text-slate-400">{{ secretHint('connection_string') || 'Alternative to account name/key. Required for SAS-only setups.' }}</span>
                </label>
            </section>

            <section v-else-if="form.provider === 'dropbox'" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">Remote path</span>
                    <input v-model="form.settings.remote_path" class="input" placeholder="/backups/volumevault">
                </label>
                <label class="space-y-2">
                    <span class="label">App key</span>
                    <input v-model="form.secrets.app_key" class="input" :required="!editing || !hasSecret('app_key')" autocomplete="off">
                    <span class="text-xs text-slate-400">{{ secretHint('app_key') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">App secret</span>
                    <PasswordInput v-model="form.secrets.app_secret" :required="!editing || !hasSecret('app_secret')" autocomplete="new-password" />
                    <span class="text-xs text-slate-400">{{ secretHint('app_secret') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">Refresh token</span>
                    <PasswordInput v-model="form.secrets.refresh_token" :required="!editing || !hasSecret('refresh_token')" autocomplete="new-password" />
                    <span class="text-xs text-slate-400">{{ secretHint('refresh_token') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">Concurrency level</span>
                    <input v-model="form.settings.concurrency_level" class="input" type="number" min="1" max="32">
                </label>
            </section>

            <section v-else-if="form.provider === 'google_drive'" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">Folder ID</span>
                    <input v-model="form.settings.folder_id" class="input" required>
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">Service account JSON</span>
                    <textarea v-model="form.secrets.credentials_json" class="input min-h-40" :required="!editing || !hasSecret('credentials_json')" autocomplete="off"></textarea>
                    <span class="text-xs text-slate-400">{{ secretHint('credentials_json') || 'The service account must have access to the folder.' }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">Impersonate subject</span>
                    <input v-model="form.settings.impersonate_subject" class="input" placeholder="user@example.com">
                </label>
                <label class="space-y-2">
                    <span class="label">Token URL</span>
                    <input v-model="form.settings.token_url" class="input" placeholder="https://oauth2.googleapis.com/token">
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">Drive API endpoint</span>
                    <input v-model="form.settings.endpoint" class="input" placeholder="https://www.googleapis.com/drive/v3">
                </label>
            </section>

            <section v-else-if="form.provider === 'local'" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">Archive path</span>
                    <input v-model="form.settings.archive_path" class="input" required placeholder="/archive">
                    <span class="text-xs text-slate-400">Path used inside the Offen backup container and readable by VolumeVault for listing/restores.</span>
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">Docker mount source</span>
                    <input v-model="form.settings.archive_mount_source" class="input" placeholder="/host/backups">
                    <span class="text-xs text-slate-400">Optional host path to mount to the archive path. Leave empty when both paths are identical.</span>
                </label>
                <div class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-100 sm:col-span-2">
                    Local destinations need a path shared between VolumeVault and the temporary Offen container. Test the destination before trusting scheduled backups.
                </div>
            </section>

            <div class="grid gap-3 sm:grid-cols-2">
                <label v-if="isS3" class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-4 text-sm">
                    <input v-model="form.use_path_style_endpoint" type="checkbox" class="rounded border-slate-600 bg-slate-950 text-sky-400">
                    Use path-style endpoint
                </label>
                <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-4 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="rounded border-slate-600 bg-slate-950 text-sky-400">
                    Destination active
                </label>
            </div>

            <div class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-100">
                Losing APP_KEY means encrypted destination secrets can no longer be decrypted. Keep it backed up securely.
            </div>

            <div class="flex flex-wrap gap-3">
                <button class="btn-primary" :disabled="form.processing">{{ editing ? t('Update destination') : t('Create destination') }}</button>
                <Link href="/destinations" class="btn-secondary">{{ t('Cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
