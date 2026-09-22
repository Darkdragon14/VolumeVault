<script setup lang="ts">
import { useI18n } from '@/i18n';
defineProps<{ host?: any; inventory?: boolean; reason?: string | null }>();
const { t, formatDate } = useI18n();
const reasons: Record<string, string> = {
    local_execution_disabled: 'hostScope.localDisabled',
    volume_missing: 'Missing',
    no_volumes: 'No Docker volumes found.',
    maintenance: 'hostScope.maintenance',
    backup_unsupported: 'hostScope.unsupported',
    agent_offline: 'hostScope.offline',
    permission_denied: 'hostScope.permission',
    remote_stack_backup_unsupported: 'hostScope.remoteStack',
};
</script>

<template>
    <div class="break-words text-xs font-normal text-slate-400">
        <span>{{ host?.name ?? t('Unknown') }}<template v-if="host?.id"> · #{{ host.id }}</template></span>
        <template v-if="inventory">
            <span> · {{ host?.status ? t(`dockerHosts.status.${host.status}`) : t('Unknown') }}</span>
            <p>{{ t('hostScope.inventory') }}: {{ host?.last_inventory_at ? formatDate(host.last_inventory_at) : t('Unknown') }}</p>
        </template>
        <p v-if="reason || (inventory && host?.backup_unavailable_reason)">{{ t(reasons[reason || host?.backup_unavailable_reason] ?? 'hostWorkflow.unavailable') }}</p>
    </div>
</template>
