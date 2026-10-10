<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';

type ChangelogItem = {
    type: string;
    title: string;
    description: string;
};

type ChangelogSection = {
    version: string;
    date?: string | null;
    url?: string | null;
    is_unreleased: boolean;
    items: ChangelogItem[];
};

type AvailableUpdate = {
    version: string;
    url: string;
    published_at?: string | null;
    body_excerpt?: string | null;
};

defineProps<{
    changelog: {
        current_version: string;
        current_changelog_id?: string | null;
        sections: ChangelogSection[];
    };
    availableUpdate?: AvailableUpdate | null;
}>();

const { t, formatDate } = useI18n();
const changelogTypeLabel = (type: string) => t(({ feature: 'Feature', change: 'Changed', migration: 'Migration', breaking: 'Breaking' } as Record<string, string>)[type] || type);
const changelogTypeClass = (type: string) => ({
    feature: 'border-sky-300/30 bg-sky-400/10 text-sky-700 dark:text-sky-100',
    change: 'border-violet-300/30 bg-violet-400/10 text-violet-700 dark:text-violet-200',
    migration: 'border-amber-300/40 bg-amber-300/10 text-amber-800 dark:text-amber-100',
    breaking: 'border-rose-300/40 bg-rose-400/10 text-rose-700 dark:text-rose-100',
}[type] || 'border-slate-200 dark:border-white/10 bg-white/80 dark:bg-white/5 text-slate-800 dark:text-slate-200');
const sectionTitle = (section: ChangelogSection) => section.is_unreleased ? t('Unreleased') : t('Release {version}', { version: section.version });
const dismissAvailableUpdate = () => router.patch('/updates/available/dismiss', {}, { preserveScroll: true });
</script>

<template>
    <Head :title="t('Changelog')" />
    <AppLayout :title="t('Changelog')" :subtitle="t('Review visible product changes, migrations, and release notes for this installation.')">
        <section v-if="availableUpdate" class="card mb-6 border-sky-300/20 bg-sky-400/10 p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-300">{{ t('New version available') }}</p>
                    <h2 class="mt-2 text-xl font-bold text-slate-900 dark:text-white">{{ t('Version {version} is available', { version: availableUpdate.version }) }}</h2>
                    <p v-if="availableUpdate.published_at" class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('Published at: {date}', { date: formatDate(availableUpdate.published_at) }) }}</p>
                    <p v-if="availableUpdate.body_excerpt" class="mt-3 text-sm text-slate-600 dark:text-slate-300">{{ availableUpdate.body_excerpt }}</p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-3">
                    <a :href="availableUpdate.url" class="btn-primary" target="_blank" rel="noopener noreferrer">{{ t('Open GitHub release') }}</a>
                    <button type="button" class="btn-secondary" @click="dismissAvailableUpdate">{{ t('Dismiss this version') }}</button>
                </div>
            </div>
        </section>

        <section class="card mb-6 p-5">
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('Current version: {version}', { version: changelog.current_version }) }}</p>
            <h2 class="mt-2 text-lg font-semibold text-slate-900 dark:text-white">{{ t('Application changelog') }}</h2>
        </section>

        <div v-if="changelog.sections.length" class="flex flex-col gap-6">
            <section v-for="section in changelog.sections" :key="section.version" class="card overflow-hidden">
                <div class="border-b border-slate-200 dark:border-white/10 bg-white/80 dark:bg-white/[0.03] p-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-300">{{ section.is_unreleased ? t('Latest updates') : section.version }}</p>
                            <h2 class="mt-1 text-xl font-bold text-slate-900 dark:text-white">{{ sectionTitle(section) }}</h2>
                            <p v-if="section.date" class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('Release date: {date}', { date: section.date }) }}</p>
                        </div>
                        <a v-if="section.url" :href="section.url" class="btn-secondary" target="_blank" rel="noopener noreferrer">{{ t('Open GitHub release') }}</a>
                    </div>
                </div>

                <div class="divide-y divide-slate-200 dark:divide-white/10">
                    <article v-for="item in section.items" :key="`${section.version}-${item.title}`" class="p-5">
                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold" :class="changelogTypeClass(item.type)">{{ changelogTypeLabel(item.type) }}</span>
                        <h3 class="mt-3 text-lg font-semibold text-slate-900 dark:text-white">{{ item.title }}</h3>
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ item.description }}</p>
                    </article>
                </div>
            </section>
        </div>

        <section v-else class="card p-10 text-center">
            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ t('No changelog entries yet.') }}</p>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ t('No update summary is available for this version.') }}</p>
        </section>
    </AppLayout>
</template>
