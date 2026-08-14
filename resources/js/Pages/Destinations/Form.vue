<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from '@/i18n';
import { bestSizeUnit, bytesToUnitValue, sizeUnits, type SizeUnit, unitValueToBytes } from '@/Composables/useFormatBytes';

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
const { t, translateError } = useI18n();
const settings = props.destination?.settings || {};
const hasSecret = (field: string) => Boolean(props.destination?.has_secrets?.[field]);
const storageLimitUnitSelections = ref<Record<string, SizeUnit>>({});

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
        host_key: settings.host_key || '',
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
        volume_name: settings.volume_name || '',
        path_prefix: settings.path_prefix || '',
        storage_limit_warning_bytes: settings.storage_limit_warning_bytes ?? null,
        storage_limit_critical_bytes: settings.storage_limit_critical_bytes ?? null,
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
const secretHint = (field: string) => editing.value && hasSecret(field) ? t('Already saved. Leave blank to keep existing value.') : '';
const settingValue = (key: string) => (form.settings as Record<string, any>)[key];
const selectedStorageLimitUnit = (key: string): SizeUnit => storageLimitUnitSelections.value[key] || bestSizeUnit(settingValue(key));
const sizeInputValue = (key: string, unit: SizeUnit) => bytesToUnitValue(settingValue(key), unit);
const inputValue = (event: Event) => (event.target as HTMLInputElement).value;
const selectValue = (event: Event) => (event.target as HTMLSelectElement).value as SizeUnit;
const updateStorageLimitUnit = (key: string, unit: SizeUnit) => {
    const currentUnit = selectedStorageLimitUnit(key);
    const currentValue = sizeInputValue(key, currentUnit);

    storageLimitUnitSelections.value[key] = unit;
    (form.settings as Record<string, any>)[key] = unitValueToBytes(currentValue, unit);
};
const updateStorageLimitThreshold = (key: string, value: string, unit: SizeUnit) => (form.settings as Record<string, any>)[key] = unitValueToBytes(value, unit);

const submit = () => {
    if (editing.value) {
        form.put(`/destinations/${props.destination.id}`);
        return;
    }

    form.post('/destinations');
};

const toggleDestinationActive = () => {
    form.is_active = !form.is_active;
};

const hostKeyProbe = ref<{ loading: boolean; fingerprint: string; error: string }>({
    loading: false,
    fingerprint: '',
    error: '',
});

const fetchHostKey = async () => {
    if (!form.settings.host || hostKeyProbe.value.loading) {
        return;
    }

    hostKeyProbe.value = { loading: true, fingerprint: '', error: '' };

    try {
        const { data } = await window.axios.post('/destinations/host-key', {
            host: form.settings.host,
            port: form.settings.port,
        });
        form.settings.host_key = data.key;
        hostKeyProbe.value = { loading: false, fingerprint: data.fingerprint, error: '' };
    } catch (error: any) {
        hostKeyProbe.value = {
            loading: false,
            fingerprint: '',
            error: error?.response?.data?.message || t('Unable to reach the SSH server.'),
        };
    }
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
                    <span class="label">{{ t('Endpoint') }}</span>
                    <input v-model="form.endpoint" class="input" placeholder="https://<account_id>.r2.cloudflarestorage.com">
                    <span class="text-xs text-slate-400">{{ t('Required for Cloudflare R2 and custom S3. AWS S3 can usually stay empty.') }}</span>
                    <span v-if="form.errors.endpoint" class="block text-sm text-rose-300">{{ form.errors.endpoint }}</span>
                </label>

                <label class="space-y-2">
                    <span class="label">{{ t('Region') }}</span>
                    <input v-model="form.region" class="input" placeholder="us-east-1">
                </label>

                <label class="space-y-2">
                    <span class="label">{{ t('Bucket') }}</span>
                    <input v-model="form.bucket" class="input" required>
                    <span v-if="form.errors.bucket" class="text-sm text-rose-300">{{ form.errors.bucket }}</span>
                </label>

                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Path prefix') }}</span>
                    <input v-model="form.path_prefix" class="input" placeholder="volumevault/backups">
                </label>

                <label class="space-y-2">
                    <span class="label">{{ t('Access key ID') }}</span>
                    <input v-model="form.access_key_id" class="input" :required="!editing" autocomplete="off">
                    <span v-if="editing" class="text-xs text-slate-400">{{ t('Credentials are already saved. Leave blank to keep existing values.') }}</span>
                </label>

                <label class="space-y-2">
                    <span class="label">{{ t('Secret access key') }}</span>
                    <PasswordInput v-model="form.secret_access_key" :required="!editing" autocomplete="new-password" />
                    <span v-if="editing" class="text-xs text-slate-400">{{ t('Leave empty to keep the saved secret.') }}</span>
                </label>
            </section>

            <section v-else-if="form.provider === 'webdav'" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('WebDAV URL') }}</span>
                    <input v-model="form.settings.url" class="input" required placeholder="https://webdav.example.com">
                    <span v-if="error('settings.url')" class="text-sm text-rose-300">{{ error('settings.url') }}</span>
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Remote path') }}</span>
                    <input v-model="form.settings.path" class="input" placeholder="/backups/volumevault">
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Username') }}</span>
                    <input v-model="form.secrets.username" class="input" autocomplete="off">
                    <span class="text-xs text-slate-400">{{ secretHint('username') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Password') }}</span>
                    <PasswordInput v-model="form.secrets.password" autocomplete="new-password" />
                    <span class="text-xs text-slate-400">{{ secretHint('password') }}</span>
                </label>
                <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-4 text-sm">
                    <input v-model="form.settings.insecure" type="checkbox" class="rounded border-slate-600 bg-slate-950 text-sky-400">
                    {{ t('Disable TLS certificate verification') }}
                </label>
            </section>

            <section v-else-if="form.provider === 'ssh'" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2">
                    <span class="label">{{ t('SSH host') }}</span>
                    <input v-model="form.settings.host" class="input" required placeholder="server.local">
                    <span v-if="error('settings.host')" class="text-sm text-rose-300">{{ error('settings.host') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Port') }}</span>
                    <input v-model="form.settings.port" class="input" type="number" min="1" max="65535">
                    <span v-if="error('settings.port')" class="text-sm text-rose-300">{{ error('settings.port') }}</span>
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Remote path') }}</span>
                    <input v-model="form.settings.remote_path" class="input" required placeholder="/home/user/backups">
                    <span v-if="error('settings.remote_path')" class="text-sm text-rose-300">{{ error('settings.remote_path') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Username') }}</span>
                    <input v-model="form.secrets.user" class="input" :required="!editing || !hasSecret('user')" autocomplete="off">
                    <span class="text-xs text-slate-400">{{ secretHint('user') }}</span>
                    <span v-if="error('secrets.user')" class="text-sm text-rose-300">{{ error('secrets.user') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Password') }}</span>
                    <PasswordInput v-model="form.secrets.password" autocomplete="new-password" />
                    <span class="text-xs text-slate-400">{{ secretHint('password') }}</span>
                    <span v-if="error('secrets.password')" class="text-sm text-rose-300">{{ error('secrets.password') }}</span>
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Private key') }}</span>
                    <textarea v-model="form.secrets.private_key" class="input min-h-32" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea>
                    <span class="text-xs text-slate-400">{{ secretHint('private_key') || t('If provided, VolumeVault mounts it into the Offen container for backup runs.') }}</span>
                    <span class="block text-xs text-amber-300">{{ t("Uploaded private keys are not supported when DOCKER_HOST controls a Docker engine on another machine because the daemon cannot access VolumeVault's local key file. Use password authentication instead.") }}</span>
                    <span v-if="error('secrets.private_key')" class="block text-sm text-rose-300">{{ error('secrets.private_key') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Private key passphrase') }}</span>
                    <PasswordInput v-model="form.secrets.private_key_passphrase" autocomplete="new-password" />
                    <span class="text-xs text-slate-400">{{ secretHint('private_key_passphrase') }}</span>
                    <span v-if="error('secrets.private_key_passphrase')" class="text-sm text-rose-300">{{ error('secrets.private_key_passphrase') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Identity file path') }}</span>
                    <input v-model="form.settings.identity_file" class="input" placeholder="/root/.ssh/id_rsa">
                    <span class="text-xs text-slate-400">{{ t('Advanced: path already available inside the Offen container.') }}</span>
                    <span v-if="error('settings.identity_file')" class="text-sm text-rose-300">{{ error('settings.identity_file') }}</span>
                </label>
                <div class="space-y-2 sm:col-span-2">
                    <div class="flex items-center justify-between gap-2">
                        <span class="label">{{ t('Pinned host key') }}</span>
                        <button type="button" class="btn-secondary text-xs" :disabled="!form.settings.host || hostKeyProbe.loading" @click="fetchHostKey">
                            {{ hostKeyProbe.loading ? t('Fetching...') : t('Fetch key from server') }}
                        </button>
                    </div>
                    <textarea v-model="form.settings.host_key" class="input min-h-20" placeholder="ssh-ed25519 AAAAC3Nza... or SHA256:abc123..."></textarea>
                    <span v-if="error('settings.host_key')" class="block text-sm text-rose-300">{{ error('settings.host_key') }}</span>
                    <span v-if="hostKeyProbe.fingerprint" class="block text-xs text-emerald-400">{{ t('Server key trusted (fingerprint {fingerprint}). Compare it with the server before saving to rule out a first-contact attack.', { fingerprint: hostKeyProbe.fingerprint }) }}</span>
                    <span v-if="hostKeyProbe.error" class="block text-xs text-rose-400">{{ hostKeyProbe.error }}</span>
                    <span class="text-xs text-slate-400">{{ t('Optional but recommended. Use the button to trust the current server key, or paste a host key (e.g. from ssh-keyscan) or its SHA256 fingerprint. Blocks man-in-the-middle attacks on the SFTP operations performed by VolumeVault; the backup container cannot verify host keys.') }}</span>
                </div>
            </section>

            <section v-else-if="form.provider === 'azure_blob'" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2">
                    <span class="label">{{ t('Account name') }}</span>
                    <input v-model="form.settings.account_name" class="input" placeholder="account-name">
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Container') }}</span>
                    <input v-model="form.settings.container" class="input" required placeholder="container-name">
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Service endpoint') }}</span>
                    <input v-model="form.settings.endpoint" class="input" placeholder="https://account.blob.core.windows.net">
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Account key') }}</span>
                    <PasswordInput v-model="form.secrets.account_key" autocomplete="new-password" />
                    <span class="text-xs text-slate-400">{{ secretHint('account_key') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Access tier') }}</span>
                    <input v-model="form.settings.access_tier" class="input" placeholder="Cool">
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Connection string') }}</span>
                    <textarea v-model="form.secrets.connection_string" class="input min-h-24" autocomplete="off"></textarea>
                    <span class="text-xs text-slate-400">{{ secretHint('connection_string') || t('Alternative to account name/key. Required for SAS-only setups.') }}</span>
                </label>
            </section>

            <section v-else-if="form.provider === 'dropbox'" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Remote path') }}</span>
                    <input v-model="form.settings.remote_path" class="input" placeholder="/backups/volumevault">
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('App key') }}</span>
                    <input v-model="form.secrets.app_key" class="input" :required="!editing || !hasSecret('app_key')" autocomplete="off">
                    <span class="text-xs text-slate-400">{{ secretHint('app_key') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('App secret') }}</span>
                    <PasswordInput v-model="form.secrets.app_secret" :required="!editing || !hasSecret('app_secret')" autocomplete="new-password" />
                    <span class="text-xs text-slate-400">{{ secretHint('app_secret') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Refresh token') }}</span>
                    <PasswordInput v-model="form.secrets.refresh_token" :required="!editing || !hasSecret('refresh_token')" autocomplete="new-password" />
                    <span class="text-xs text-slate-400">{{ secretHint('refresh_token') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Concurrency level') }}</span>
                    <input v-model="form.settings.concurrency_level" class="input" type="number" min="1" max="32">
                </label>
            </section>

            <section v-else-if="form.provider === 'google_drive'" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Folder ID') }}</span>
                    <input v-model="form.settings.folder_id" class="input" required>
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Service account JSON') }}</span>
                    <textarea v-model="form.secrets.credentials_json" class="input min-h-40" :required="!editing || !hasSecret('credentials_json')" autocomplete="off"></textarea>
                    <span class="text-xs text-slate-400">{{ secretHint('credentials_json') || t('The service account must have access to the folder.') }}</span>
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Impersonate subject') }}</span>
                    <input v-model="form.settings.impersonate_subject" class="input" placeholder="user@example.com">
                </label>
                <label class="space-y-2">
                    <span class="label">{{ t('Token URL') }}</span>
                    <input v-model="form.settings.token_url" class="input" placeholder="https://oauth2.googleapis.com/token">
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Drive API endpoint') }}</span>
                    <input v-model="form.settings.endpoint" class="input" placeholder="https://www.googleapis.com/drive/v3">
                </label>
            </section>

            <section v-else-if="form.provider === 'local'" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Archive path') }}</span>
                    <input v-model="form.settings.archive_path" class="input" required placeholder="/archive">
                    <span class="text-xs text-slate-400">{{ t('Path used inside the Offen backup container and readable by VolumeVault for listing/restores.') }}</span>
                    <span v-if="error('settings.archive_path')" class="block text-sm text-rose-300">{{ translateError(error('settings.archive_path') as string) }}</span>
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Docker mount source') }}</span>
                    <input v-model="form.settings.archive_mount_source" class="input" placeholder="/host/backups">
                    <span class="text-xs text-slate-400">{{ t('Optional host path to mount to the archive path. Leave empty when both paths are identical.') }}</span>
                    <span v-if="error('settings.archive_mount_source')" class="block text-sm text-rose-300">{{ translateError(error('settings.archive_mount_source') as string) }}</span>
                </label>
                <div class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-100 sm:col-span-2">
                    {{ t('Local destinations need a path shared between VolumeVault and the temporary Offen container. Test the destination before trusting scheduled backups.') }}
                </div>
            </section>

            <section v-else-if="form.provider === 'docker_volume'" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Docker volume name') }}</span>
                    <input v-model="form.settings.volume_name" class="input" required placeholder="barril-backups">
                    <span class="text-xs text-slate-400">{{ t('Name of a Docker volume defined in your Compose file (e.g. an NFS volume). VolumeVault mounts it into the temporary Offen container for backups and restores.') }}</span>
                    <span v-if="error('settings.volume_name')" class="block text-sm text-rose-300">{{ translateError(error('settings.volume_name') as string) }}</span>
                </label>
                <label class="space-y-2 sm:col-span-2">
                    <span class="label">{{ t('Subpath (optional)') }}</span>
                    <input v-model="form.settings.path_prefix" class="input" placeholder="volumevault">
                    <span class="text-xs text-slate-400">{{ t('Optional sub-directory inside the volume where archives are stored. Leave empty to use the volume root.') }}</span>
                    <span v-if="error('settings.path_prefix')" class="block text-sm text-rose-300">{{ translateError(error('settings.path_prefix') as string) }}</span>
                </label>
                <div class="rounded-xl border border-sky-300/30 bg-sky-300/10 p-4 text-sm text-sky-100 sm:col-span-2">
                    {{ t('The volume is mounted by name into throwaway containers, so no host path needs to be shared with VolumeVault. Test the destination before trusting scheduled backups.') }}
                </div>
            </section>

            <section class="rounded-2xl border border-white/10 bg-white/5 p-4 sm:p-5">
                <div>
                    <h2 class="text-lg font-semibold text-white">{{ t('Destination storage limits') }}</h2>
                    <p class="mt-1 text-sm text-slate-400">{{ t('Configure absolute usage thresholds for the destination storage limit alert. Leave both empty to skip this destination.') }}</p>
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="space-y-2">
                        <span class="label">{{ t('Warning threshold') }}</span>
                        <span class="flex gap-2">
                            <input
                                class="input min-w-0"
                                type="number"
                                min="0"
                                step="0.01"
                                :value="sizeInputValue('storage_limit_warning_bytes', selectedStorageLimitUnit('storage_limit_warning_bytes'))"
                                @input="updateStorageLimitThreshold('storage_limit_warning_bytes', inputValue($event), selectedStorageLimitUnit('storage_limit_warning_bytes'))"
                            >
                            <select class="input w-24 shrink-0" :value="selectedStorageLimitUnit('storage_limit_warning_bytes')" @change="updateStorageLimitUnit('storage_limit_warning_bytes', selectValue($event))">
                                <option v-for="unit in sizeUnits" :key="unit.label" :value="unit.label">{{ unit.label }}</option>
                            </select>
                        </span>
                    </label>
                    <label class="space-y-2">
                        <span class="label">{{ t('Critical threshold') }}</span>
                        <span class="flex gap-2">
                            <input
                                class="input min-w-0"
                                type="number"
                                min="0"
                                step="0.01"
                                :value="sizeInputValue('storage_limit_critical_bytes', selectedStorageLimitUnit('storage_limit_critical_bytes'))"
                                @input="updateStorageLimitThreshold('storage_limit_critical_bytes', inputValue($event), selectedStorageLimitUnit('storage_limit_critical_bytes'))"
                            >
                            <select class="input w-24 shrink-0" :value="selectedStorageLimitUnit('storage_limit_critical_bytes')" @change="updateStorageLimitUnit('storage_limit_critical_bytes', selectValue($event))">
                                <option v-for="unit in sizeUnits" :key="unit.label" :value="unit.label">{{ unit.label }}</option>
                            </select>
                        </span>
                        <span v-if="error('settings.storage_limit_critical_bytes')" class="text-sm text-rose-300">{{ error('settings.storage_limit_critical_bytes') }}</span>
                    </label>
                </div>
            </section>

            <div class="grid gap-3 sm:grid-cols-2">
                <label v-if="isS3" class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-4 text-sm">
                    <input v-model="form.use_path_style_endpoint" type="checkbox" class="rounded border-slate-600 bg-slate-950 text-sky-400">
                    {{ t('Use path-style endpoint') }}
                </label>
                <div class="flex items-start justify-between gap-4 rounded-xl border border-white/10 bg-white/5 p-4 text-sm">
                    <div>
                        <p class="font-medium text-white">{{ t('Destination active') }}</p>
                        <p class="mt-1 text-slate-400">{{ form.is_active ? t('Enabled') : t('Disabled') }}</p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        class="relative mt-1 inline-flex h-7 w-12 shrink-0 items-center rounded-full border p-1 transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:focus:ring-sky-400/30"
                        :class="form.is_active ? 'border-emerald-700 bg-emerald-600 dark:border-emerald-300/50 dark:bg-emerald-500/50' : 'border-slate-300 bg-slate-200 dark:border-white/10 dark:bg-slate-800'"
                        :aria-checked="form.is_active"
                        :aria-label="t('Destination active')"
                        @click="toggleDestinationActive"
                    >
                        <span class="h-5 w-5 rounded-full bg-white shadow-sm transition-transform" :class="form.is_active ? 'translate-x-5' : 'translate-x-0 bg-slate-400'"></span>
                    </button>
                </div>
            </div>

            <div class="rounded-xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-100">
                {{ t('Losing APP_KEY means encrypted destination secrets can no longer be decrypted. Keep it backed up securely.') }}
            </div>

            <div class="flex flex-wrap gap-3">
                <button class="btn-primary" :disabled="form.processing">{{ editing ? t('Update destination') : t('Create destination') }}</button>
                <Link href="/destinations" class="btn-secondary">{{ t('Cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
