<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionIcon from '@/Components/ActionIcon.vue';
import StatCard from '@/Components/Dashboard/StatCard.vue';
import DashboardSection from '@/Components/Dashboard/DashboardSection.vue';
import VisibilityToggleIcon from '@/Components/Dashboard/VisibilityToggleIcon.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import draggable from 'vuedraggable';
import { useI18n } from '@/i18n';
import { formatBytes } from '@/Composables/useFormatBytes';

type WidgetPref = { key: string; visible: boolean };

const props = defineProps<{
    stats: Record<string, any>;
    recentBackupRuns: any[];
    recentGroupRuns: any[];
    recentRestoreRuns: any[];
    jobsWithErrors: any[];
    groupsWithErrors: any[];
    dashboardPreferences: { stats: WidgetPref[]; sections: WidgetPref[] };
}>();

const { t, formatDate } = useI18n();

// Canonical defaults, mirroring App\Support\DashboardWidgets (used by "Reset").
const DEFAULT_STAT_KEYS = [
    'total_volumes', 'existing_volumes', 'missing_volumes', 'backed_up_volumes',
    'configured_volumes', 'unprotected_volumes', 'total_jobs', 'active_jobs',
    'paused_jobs', 'error_jobs', 'total_groups', 'active_groups', 'paused_groups',
    'error_groups', 'last_backup_run_status', 'last_successful_backup_size',
    'last_successful_group_backup_size', 'next_scheduled_backup',
];
const DEFAULT_SECTION_KEYS = ['recent_backups', 'recent_group_runs', 'recent_restores', 'jobs_with_errors', 'groups_with_errors'];
const HIDDEN_BY_DEFAULT = ['last_successful_backup_size', 'last_successful_group_backup_size'];

const statLabels: Record<string, string> = {
    total_volumes: 'Total volumes',
    existing_volumes: 'Existing volumes',
    missing_volumes: 'Missing volumes',
    backed_up_volumes: 'Backed up volumes',
    configured_volumes: 'Pending backup volumes',
    unprotected_volumes: 'Unprotected volumes',
    total_jobs: 'Total jobs',
    active_jobs: 'Active jobs',
    paused_jobs: 'Paused jobs',
    error_jobs: 'Error jobs',
    total_groups: 'Total groups',
    active_groups: 'Active groups',
    paused_groups: 'Paused groups',
    error_groups: 'Error groups',
    last_backup_run_status: 'Last backup run status',
    last_successful_backup_size: 'Last successful backup size',
    last_successful_group_backup_size: 'Last successful group backup size',
    next_scheduled_backup: 'Next scheduled backup',
};

const sectionLabels: Record<string, string> = {
    recent_backups: 'Recent backup runs',
    recent_group_runs: 'Recent group runs',
    recent_restores: 'Recent restore runs',
    jobs_with_errors: 'Jobs with errors',
    groups_with_errors: 'Groups with errors',
};

const statLabel = (key: string) => t(statLabels[key] || key.replaceAll('_', ' '));
const sectionLabel = (key: string) => t(sectionLabels[key] || key.replaceAll('_', ' '));

const statValue = (key: string, value: any) => {
    if (key.includes('scheduled')) return formatDate(value);
    if (key.includes('size')) return formatBytes(value, t('Unknown'));
    if (key.includes('status')) return value ? t(String(value)) : t('None');

    return value ?? t('None');
};
const statValueFor = (key: string) => statValue(key, props.stats[key]);

// --- Display (read from server-provided, normalized preferences) ---
const visibleStats = computed(() => props.dashboardPreferences.stats.filter((w) => w.visible));
const visibleSections = computed(() => props.dashboardPreferences.sections.filter((w) => w.visible));

// Keep the 2-column grid balanced whatever the visible set is: a lone trailing
// section (odd count) spans both columns instead of leaving an empty gap.
const sectionSpanClass = (index: number) =>
    visibleSections.value.length % 2 === 1 && index === visibleSections.value.length - 1 ? 'lg:col-span-2' : '';

// --- Edit mode ---
const editing = ref(false);
const editStats = ref<WidgetPref[]>([]);
const editSections = ref<WidgetPref[]>([]);
const saving = ref(false);

const startEdit = () => {
    editStats.value = props.dashboardPreferences.stats.map((w) => ({ ...w }));
    editSections.value = props.dashboardPreferences.sections.map((w) => ({ ...w }));
    editing.value = true;
};

const cancelEdit = () => {
    editing.value = false;
};

const resetToDefaults = () => {
    editStats.value = DEFAULT_STAT_KEYS.map((key) => ({ key, visible: !HIDDEN_BY_DEFAULT.includes(key) }));
    editSections.value = DEFAULT_SECTION_KEYS.map((key) => ({ key, visible: true }));
};

const save = () => {
    saving.value = true;
    router.patch('/user/dashboard-preferences', {
        stats: editStats.value.map(({ key, visible }) => ({ key, visible })),
        sections: editSections.value.map(({ key, visible }) => ({ key, visible })),
    }, {
        preserveScroll: true,
        onSuccess: () => { editing.value = false; },
        onFinish: () => { saving.value = false; },
    });
};
</script>

<template>
    <Head :title="t('Dashboard')" />
    <AppLayout :title="t('Dashboard')">
        <template #title-actions>
            <ActionIcon v-if="!editing" :label="t('Customize')" icon="edit" @click="startEdit" />
        </template>

        <template #actions>
            <button v-if="!editing" type="button" class="btn-secondary hidden sm:inline-flex" @click="startEdit">
                {{ t('Customize') }}
            </button>
            <div v-else class="flex flex-wrap gap-2">
                <button type="button" class="btn-secondary" @click="resetToDefaults">{{ t('Reset to defaults') }}</button>
                <button type="button" class="btn-secondary" @click="cancelEdit">{{ t('Cancel') }}</button>
                <button type="button" class="btn-primary" :disabled="saving" @click="save">{{ t('Done') }}</button>
            </div>
        </template>

        <!-- Display mode -->
        <template v-if="!editing">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard v-for="w in visibleStats" :key="w.key" :label="statLabel(w.key)" :value="String(statValueFor(w.key))" />
            </div>

            <div class="mt-8 grid gap-6 lg:grid-cols-2">
                <div v-for="(w, i) in visibleSections" :key="w.key" :class="sectionSpanClass(i)">
                    <DashboardSection
                        :section-key="w.key"
                        :recent-backup-runs="recentBackupRuns"
                        :recent-group-runs="recentGroupRuns"
                        :recent-restore-runs="recentRestoreRuns"
                        :jobs-with-errors="jobsWithErrors"
                        :groups-with-errors="groupsWithErrors"
                    />
                </div>
            </div>
        </template>

        <!-- Edit mode -->
        <template v-else>
            <p class="mb-4 text-sm text-slate-400">{{ t('Drag widgets to reorder, toggle the eye to show or hide. Click Done to save.') }}</p>

            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ t('Statistics') }}</h2>
            <draggable v-model="editStats" item-key="key" handle=".drag-handle" :animation="150" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <template #item="{ element }">
                    <div class="card relative h-full p-5 transition-opacity" :class="{ 'opacity-40': !element.visible }">
                        <div class="absolute right-2 top-2 flex items-center gap-1">
                            <button type="button" class="drag-handle cursor-move rounded p-1 text-slate-400 hover:bg-white/10" :title="t('Drag to reorder')">⠿</button>
                            <button type="button" class="rounded p-1 transition hover:bg-white/10" :class="element.visible ? 'text-sky-300' : 'text-slate-500'" :title="element.visible ? t('Hide') : t('Show')" @click="element.visible = !element.visible">
                                <VisibilityToggleIcon :open="element.visible" />
                            </button>
                        </div>
                        <p class="pr-14 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ statLabel(element.key) }}</p>
                        <p class="mt-3 break-words text-2xl font-bold text-white">{{ statValueFor(element.key) }}</p>
                    </div>
                </template>
            </draggable>

            <h2 class="mb-3 mt-8 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ t('Sections') }}</h2>
            <draggable v-model="editSections" item-key="key" handle=".drag-handle" :animation="150" class="grid gap-4 sm:grid-cols-2">
                <template #item="{ element }">
                    <div class="card relative h-full p-5 transition-opacity" :class="{ 'opacity-40': !element.visible }">
                        <div class="absolute right-2 top-2 flex items-center gap-1">
                            <button type="button" class="drag-handle cursor-move rounded p-1 text-slate-400 hover:bg-white/10" :title="t('Drag to reorder')">⠿</button>
                            <button type="button" class="rounded p-1 transition hover:bg-white/10" :class="element.visible ? 'text-sky-300' : 'text-slate-500'" :title="element.visible ? t('Hide') : t('Show')" @click="element.visible = !element.visible">
                                <VisibilityToggleIcon :open="element.visible" />
                            </button>
                        </div>
                        <p class="pr-14 text-lg font-semibold">{{ sectionLabel(element.key) }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ t('Section') }}</p>
                    </div>
                </template>
            </draggable>
        </template>
    </AppLayout>
</template>
