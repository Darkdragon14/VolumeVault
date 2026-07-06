<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from '@/i18n';
import { useTheme } from '@/theme';

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

type UpdateSummary = {
    has_unread: boolean;
    current_version: string;
    last_seen_version?: string | null;
    changelog_id: string;
    item_count: number;
    sections: ChangelogSection[];
};

type AvailableUpdate = {
    version: string;
    url: string;
    published_at?: string | null;
    body_excerpt?: string | null;
};

type QuickNavItem = {
    label: string;
    href: string;
    group: string;
    shortcutKey: string;
    shortcutLabel: string;
    shortcutSearchAliases: string[];
    description?: string;
    badge?: number;
};

withDefaults(defineProps<{
    title: string;
    subtitle?: string;
}>(), {
    subtitle: 'Safe, explicit orchestration for Docker volume backups and restores.',
});

const page = usePage();
const { t, locale, locales, languageNames } = useI18n();
const { isDark, toggleTheme } = useTheme();
const flash = computed(() => (page.props.flash || {}) as { success?: string; error?: string });
const auth = computed(() => (page.props.auth || {}) as { user?: { id: number; name: string; email: string; role: string; locale: string; theme: string } | null });
const can = computed(() => (page.props.can || {}) as { manageSensitiveData?: boolean; runDockerActions?: boolean; manageUsers?: boolean });
const app = computed(() => (page.props.app || {}) as { version?: string });
const updateSummary = computed(() => (page.props.updateSummary || null) as UpdateSummary | null);
const availableUpdate = computed(() => (page.props.availableUpdate || null) as AvailableUpdate | null);
const activeAlertCount = computed(() => Number(page.props.activeAlertCount || 0));
const shouldShowUpdateSummary = computed(() => Boolean(updateSummary.value?.has_unread) && !page.url.startsWith('/changelog'));
const updateLocale = (event: Event) => router.patch('/user/locale', { locale: (event.target as HTMLSelectElement).value }, { preserveScroll: true });
const themeToggleLabel = computed(() => isDark.value ? t('Switch to light theme') : t('Switch to dark theme'));
const themeName = computed(() => isDark.value ? t('Dark theme') : t('Light theme'));
const currentYear = new Date().getFullYear();
const githubProfileUrl = 'https://github.com/Darkdragon14';
const githubRepoUrl = 'https://github.com/Darkdragon14/VolumeVault';
const githubIssuesUrl = 'https://github.com/Darkdragon14/VolumeVault/issues';
const snoozedUpdateSummaryStorageKey = 'volumevault.snoozed_update_summary_id';

const openMenu = ref<'settings' | 'user' | null>(null);
const isMobileNavOpen = ref(false);
const showUpdateSummary = ref(false);
const isQuickNavOpen = ref(false);
const quickNavQuery = ref('');
const selectedQuickNavIndex = ref(0);
const headerRef = ref<HTMLElement | null>(null);
const quickNavSearchRef = ref<HTMLInputElement | null>(null);
const pendingGoShortcut = ref(false);
const quickNavModifierLabel = ref('Ctrl');
const areKeyboardShortcutsEnabled = ref(false);
let pendingGoShortcutTimeout: number | null = null;
let keyboardShortcutMediaQuery: MediaQueryList | null = null;

const shortcutLabel = (key: string) => t('g then {key}', { key });
const shortcutSearchAliases = (key: string) => [`g ${key}`, `g${key}`, key];

const primaryNav = computed(() => [
    { label: t('Dashboard'), href: '/dashboard', shortcutKey: 'h' },
    { label: t('Volumes'), href: '/volumes', shortcutKey: 'v' },
    { label: t('Stacks'), href: '/stacks', shortcutKey: 's' },
    { label: t('Backup jobs'), href: '/backup-jobs', shortcutKey: 'j' },
    { label: t('Backup groups'), href: '/backup-groups', shortcutKey: 'k' },
    { label: t('Alerts'), href: '/alerts', shortcutKey: 'a', badge: activeAlertCount.value },
]);

const settingsNav = computed(() => [
    ...(can.value.manageSensitiveData ? [
        { label: t('Destinations'), description: t('Storage targets'), href: '/destinations', shortcutKey: 'd' },
        { label: t('Notifications'), description: t('Alert channels'), href: '/notifications', shortcutKey: 'n' },
        { label: t('Installation save'), description: t('Export and import setup'), href: '/installation-save', shortcutKey: 'i' },
    ] : []),
    ...(can.value.manageUsers ? [
        { label: t('Users'), description: t('Team access'), href: '/users', shortcutKey: 'u' },
    ] : []),
]);

const accountNav = computed(() => [
    { label: t('Edit profile'), href: '/profile', shortcutKey: 'p' },
    ...(can.value.manageUsers ? [
        { label: t('API tokens'), href: '/api-tokens', shortcutKey: 't' },
    ] : []),
    { label: t('Changelog'), href: '/changelog', shortcutKey: 'c' },
]);

const quickNavItems = computed<QuickNavItem[]>(() => [
    ...primaryNav.value.map((item) => ({
        ...item,
        group: t('Navigation'),
        shortcutLabel: shortcutLabel(item.shortcutKey),
        shortcutSearchAliases: shortcutSearchAliases(item.shortcutKey),
    })),
    ...settingsNav.value.map((item) => ({
        ...item,
        group: t('Settings'),
        shortcutLabel: shortcutLabel(item.shortcutKey),
        shortcutSearchAliases: shortcutSearchAliases(item.shortcutKey),
    })),
    ...accountNav.value.map((item) => ({
        ...item,
        group: t('Account'),
        shortcutLabel: shortcutLabel(item.shortcutKey),
        shortcutSearchAliases: shortcutSearchAliases(item.shortcutKey),
    })),
]);

const filteredQuickNavItems = computed(() => {
    const query = quickNavQuery.value.trim().toLowerCase();

    if (!query) {
        return quickNavItems.value;
    }

    return quickNavItems.value.filter((item) => [item.label, item.description, item.group, item.href, item.shortcutLabel, ...item.shortcutSearchAliases]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
        .includes(query));
});

const quickNavShortcutMap = computed(() => new Map(quickNavItems.value.map((item) => [item.shortcutKey, item])));

const hasActiveItem = (items: { href: string }[]) => items.some((item) => page.url.startsWith(item.href));
const toggleMenu = (menu: 'settings' | 'user') => {
    isMobileNavOpen.value = false;
    openMenu.value = openMenu.value === menu ? null : menu;
};
const closeMenu = () => openMenu.value = null;
const closeMobileNav = () => {
    isMobileNavOpen.value = false;
    closeMenu();
};
const toggleMobileNav = () => {
    closeMenu();
    isMobileNavOpen.value = !isMobileNavOpen.value;
};
const openQuickNav = () => {
    if (!auth.value.user || showUpdateSummary.value || !areKeyboardShortcutsEnabled.value) {
        return;
    }

    closeMobileNav();
    isQuickNavOpen.value = true;
};
const closeQuickNav = () => isQuickNavOpen.value = false;
const updateKeyboardShortcutAvailability = () => {
    areKeyboardShortcutsEnabled.value = Boolean(keyboardShortcutMediaQuery?.matches);

    if (!areKeyboardShortcutsEnabled.value) {
        clearPendingGoShortcut();
        closeQuickNav();
    }
};
const detectQuickNavModifier = () => {
    if (typeof window === 'undefined') {
        return;
    }

    quickNavModifierLabel.value = /Mac|iPhone|iPad|iPod/.test(window.navigator.platform) ? 'Cmd' : 'Ctrl';
};
const visitQuickNavItem = (item: QuickNavItem) => {
    closeQuickNav();

    if (page.url !== item.href) {
        router.visit(item.href);
    }
};
const selectQuickNavItem = (offset: number) => {
    const itemCount = filteredQuickNavItems.value.length;

    if (!itemCount) {
        return;
    }

    selectedQuickNavIndex.value = (selectedQuickNavIndex.value + offset + itemCount) % itemCount;
};
const onQuickNavKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        event.preventDefault();
        closeQuickNav();

        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        selectQuickNavItem(1);

        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        selectQuickNavItem(-1);

        return;
    }

    if (event.key === 'Home') {
        event.preventDefault();
        selectedQuickNavIndex.value = 0;

        return;
    }

    if (event.key === 'End') {
        event.preventDefault();
        selectedQuickNavIndex.value = Math.max(filteredQuickNavItems.value.length - 1, 0);

        return;
    }

    if (event.key === 'Enter') {
        const item = filteredQuickNavItems.value[selectedQuickNavIndex.value];

        if (item) {
            event.preventDefault();
            visitQuickNavItem(item);
        }
    }
};
const clearPendingGoShortcut = () => {
    pendingGoShortcut.value = false;

    if (pendingGoShortcutTimeout !== null) {
        window.clearTimeout(pendingGoShortcutTimeout);
        pendingGoShortcutTimeout = null;
    }
};
const startPendingGoShortcut = () => {
    clearPendingGoShortcut();
    pendingGoShortcut.value = true;
    pendingGoShortcutTimeout = window.setTimeout(clearPendingGoShortcut, 1200);
};
const isEditableTarget = (target: EventTarget | null) => {
    const element = target instanceof HTMLElement ? target : null;

    return Boolean(element && (
        element.isContentEditable
        || ['INPUT', 'SELECT', 'TEXTAREA'].includes(element.tagName)
        || element.closest('[contenteditable="true"]')
    ));
};
const focusPageSearch = () => {
    const searchInput = document.querySelector<HTMLInputElement>('[data-list-search]');

    if (!searchInput) {
        return false;
    }

    searchInput.focus();
    searchInput.select();

    return true;
};
const onGlobalKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        clearPendingGoShortcut();

        if (isQuickNavOpen.value) {
            event.preventDefault();
            closeQuickNav();

            return;
        }

        if (isMobileNavOpen.value || openMenu.value) {
            event.preventDefault();
            closeMobileNav();

            return;
        }

        if (showUpdateSummary.value) {
            event.preventDefault();
            snoozeUpdateSummary();
        }

        return;
    }

    if (!areKeyboardShortcutsEnabled.value) {
        return;
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        if (!auth.value.user || showUpdateSummary.value) {
            return;
        }

        event.preventDefault();
        isQuickNavOpen.value ? closeQuickNav() : openQuickNav();

        return;
    }

    if (isQuickNavOpen.value || showUpdateSummary.value || !auth.value.user) {
        return;
    }

    if (event.key === '/' && !isEditableTarget(event.target)) {
        if (focusPageSearch()) {
            event.preventDefault();
        }

        return;
    }

    if (isEditableTarget(event.target) || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) {
        return;
    }

    const key = event.key.toLowerCase();

    if (pendingGoShortcut.value) {
        clearPendingGoShortcut();

        const item = quickNavShortcutMap.value.get(key);

        if (item) {
            event.preventDefault();
            visitQuickNavItem(item);
        }

        return;
    }

    if (key === 'g') {
        event.preventDefault();
        startPendingGoShortcut();
    }
};
const changelogTypeLabel = (type: string) => t(({ feature: 'Feature', change: 'Changed', migration: 'Migration', breaking: 'Breaking' } as Record<string, string>)[type] || type);
const changelogTypeClass = (type: string) => ({
    feature: 'border-sky-300/30 bg-sky-400/10 text-sky-100',
    change: 'border-violet-300/30 bg-violet-400/10 text-violet-200',
    migration: 'border-amber-300/40 bg-amber-300/10 text-amber-100',
    breaking: 'border-rose-300/40 bg-rose-400/10 text-rose-100',
}[type] || 'border-white/10 bg-white/5 text-slate-200');
const sectionTitle = (section: ChangelogSection) => section.is_unreleased ? t('Unreleased') : t('Release {version}', { version: section.version });
const readSnoozedUpdateSummaryId = (): string | null => {
    try {
        return typeof window === 'undefined' ? null : window.sessionStorage.getItem(snoozedUpdateSummaryStorageKey);
    } catch {
        return null;
    }
};
const writeSnoozedUpdateSummaryId = (changelogId: string): void => {
    try {
        window.sessionStorage.setItem(snoozedUpdateSummaryStorageKey, changelogId);
    } catch {
        return;
    }
};
const clearSnoozedUpdateSummaryId = (): void => {
    try {
        window.sessionStorage.removeItem(snoozedUpdateSummaryStorageKey);
    } catch {
        return;
    }
};
const isUpdateSummarySnoozed = (summary: UpdateSummary | null) => summary
    ? readSnoozedUpdateSummaryId() === summary.changelog_id
    : false;
const snoozeUpdateSummary = () => {
    if (updateSummary.value) {
        writeSnoozedUpdateSummaryId(updateSummary.value.changelog_id);
    }

    showUpdateSummary.value = false;
};
const markUpdateSummarySeen = () => router.patch('/changelog/seen', {}, {
    preserveScroll: true,
    onSuccess: () => {
        clearSnoozedUpdateSummaryId();
        showUpdateSummary.value = false;
    },
});

const closeOnOutsideClick = (event: MouseEvent) => {
    if (headerRef.value && !headerRef.value.contains(event.target as Node)) {
        closeMobileNav();
    }
};

onMounted(() => {
    detectQuickNavModifier();
    keyboardShortcutMediaQuery = window.matchMedia('(min-width: 640px) and (hover: hover) and (pointer: fine)');
    keyboardShortcutMediaQuery.addEventListener('change', updateKeyboardShortcutAvailability);
    updateKeyboardShortcutAvailability();
    document.addEventListener('click', closeOnOutsideClick);
    document.addEventListener('keydown', onGlobalKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeOnOutsideClick);
    document.removeEventListener('keydown', onGlobalKeydown);
    keyboardShortcutMediaQuery?.removeEventListener('change', updateKeyboardShortcutAvailability);
    clearPendingGoShortcut();
});

watch(quickNavQuery, () => {
    selectedQuickNavIndex.value = 0;
});

watch(filteredQuickNavItems, (items) => {
    selectedQuickNavIndex.value = Math.min(selectedQuickNavIndex.value, Math.max(items.length - 1, 0));
});

watch(isQuickNavOpen, async (isOpen) => {
    if (!isOpen) {
        quickNavQuery.value = '';
        selectedQuickNavIndex.value = 0;

        return;
    }

    await nextTick();
    quickNavSearchRef.value?.focus();
});

watch(shouldShowUpdateSummary, (shouldShow) => {
    showUpdateSummary.value = shouldShow && !isUpdateSummarySnoozed(updateSummary.value);
}, { immediate: true });
</script>

<template>
    <div class="app-shell">
        <header ref="headerRef" class="relative z-50 border-b border-white/10 bg-slate-950/70 backdrop-blur">
            <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between gap-3">
                    <Link href="/dashboard" class="flex min-w-0 items-center gap-3" @click="closeMobileNav">
                        <img :src="'/logo.png'" alt="" class="h-10 w-auto shrink-0 object-contain">
                        <div class="min-w-0">
                            <p class="truncate text-lg font-bold tracking-tight">VolumeVault</p>
                            <p class="hidden text-xs text-slate-400 sm:block">{{ t('Back up and restore Docker volumes') }}</p>
                        </div>
                    </Link>

                    <button
                        class="group inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-slate-200 transition hover:bg-slate-100 hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-sky-400/30 dark:hover:bg-white/10 dark:hover:text-white lg:hidden"
                        type="button"
                        :aria-label="isMobileNavOpen ? t('Close') : t('Navigation')"
                        aria-controls="mobile-navigation"
                        :aria-expanded="isMobileNavOpen"
                        @click.stop="toggleMobileNav"
                    >
                        <span class="relative h-5 w-5" aria-hidden="true">
                            <span class="absolute left-0 top-1 h-0.5 w-5 rounded-full bg-current transition" :class="{ 'translate-y-2 rotate-45': isMobileNavOpen }"></span>
                            <span class="absolute left-0 top-2.5 h-0.5 w-5 rounded-full bg-current transition" :class="{ 'opacity-0': isMobileNavOpen }"></span>
                            <span class="absolute left-0 top-4 h-0.5 w-5 rounded-full bg-current transition" :class="{ '-translate-y-2 -rotate-45': isMobileNavOpen }"></span>
                        </span>
                    </button>

                    <nav class="hidden min-w-0 flex-col gap-3 lg:flex lg:flex-row lg:items-center" :aria-label="t('Navigation')">
                        <div class="flex flex-wrap gap-2">
                            <Link
                                v-for="item in primaryNav"
                                :key="item.href"
                                :href="item.href"
                                class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium text-slate-300 transition hover:bg-slate-100 hover:text-slate-950 dark:hover:bg-white/10 dark:hover:text-white"
                                :class="{ 'bg-white/10 text-white': page.url.startsWith(item.href) }"
                                @click="closeMenu"
                            >
                                <span>{{ item.label }}</span>
                                <span v-if="item.badge" class="rounded-full bg-rose-400/20 px-2 py-0.5 text-xs text-rose-100">{{ item.badge }}</span>
                            </Link>

                            <div v-if="settingsNav.length" class="relative">
                                <button
                                    class="group inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium text-slate-300 transition hover:bg-slate-100 hover:text-slate-950 dark:hover:bg-white/10 dark:hover:text-white"
                                    :class="{ 'bg-white/10 text-white': openMenu === 'settings' || hasActiveItem(settingsNav) }"
                                    type="button"
                                    aria-haspopup="menu"
                                    :aria-expanded="openMenu === 'settings'"
                                    @click.stop="toggleMenu('settings')"
                                >
                                    {{ t('Settings') }}
                                    <span class="h-2 w-2 rotate-45 border-b-2 border-r-2 border-slate-500 transition group-hover:border-slate-300" aria-hidden="true"></span>
                                </button>

                                <div v-if="openMenu === 'settings'" class="fixed left-4 right-4 z-30 mt-2 overflow-hidden rounded-2xl border border-white/10 bg-slate-950 p-2 shadow-2xl shadow-black/40 sm:absolute sm:left-auto sm:right-0 sm:w-72" role="menu">
                                    <Link
                                        v-for="item in settingsNav"
                                        :key="item.href"
                                        :href="item.href"
                                        class="block rounded-xl px-3 py-3 text-sm transition hover:bg-slate-100 dark:hover:bg-white/10"
                                        :class="page.url.startsWith(item.href) ? 'bg-sky-400/10 text-sky-100' : 'text-slate-200'"
                                        role="menuitem"
                                        @click="closeMenu"
                                    >
                                        <span class="block font-semibold">{{ item.label }}</span>
                                        <span class="mt-0.5 block text-xs text-slate-400">{{ item.description }}</span>
                                    </Link>
                                </div>
                            </div>
                        </div>

                        <div v-if="auth.user" class="relative">
                            <button
                                class="group flex w-full items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-3 py-2 text-left text-sm text-slate-200 transition hover:bg-slate-100 dark:hover:bg-white/10 lg:w-auto"
                                :class="{ 'bg-white/10 text-white': openMenu === 'user' || page.url.startsWith('/profile') || page.url.startsWith('/api-tokens') || page.url.startsWith('/changelog') }"
                                type="button"
                                aria-haspopup="menu"
                                :aria-expanded="openMenu === 'user'"
                                @click.stop="toggleMenu('user')"
                            >
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-sky-400/15 font-bold uppercase text-sky-200">
                                    {{ auth.user.name.slice(0, 1) }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold text-white">{{ auth.user.name }}</span>
                                    <span class="block truncate text-xs text-slate-400">{{ auth.user.role }}</span>
                                </span>
                                <span class="h-2 w-2 rotate-45 border-b-2 border-r-2 border-slate-500 transition group-hover:border-slate-300" aria-hidden="true"></span>
                            </button>

                            <div v-if="openMenu === 'user'" class="fixed left-4 right-4 z-30 mt-2 overflow-hidden rounded-2xl border border-white/10 bg-slate-950 p-2 shadow-2xl shadow-black/40 sm:absolute sm:left-auto sm:right-0 sm:w-80" role="menu">
                                <div class="border-b border-white/10 px-3 py-3">
                                    <p class="truncate text-sm font-semibold text-white">{{ auth.user.name }}</p>
                                    <p class="truncate text-xs text-slate-400">{{ auth.user.email }}</p>
                                </div>

                                <Link href="/profile" class="mt-2 block rounded-xl px-3 py-3 text-sm font-medium text-slate-200 transition hover:bg-slate-100 dark:hover:bg-white/10" role="menuitem" @click="closeMenu">
                                    {{ t('Edit profile') }}
                                </Link>

                                <Link v-if="can.manageUsers" href="/api-tokens" class="block rounded-xl px-3 py-3 text-sm font-medium text-slate-200 transition hover:bg-slate-100 dark:hover:bg-white/10" role="menuitem" @click="closeMenu">
                                    {{ t('API tokens') }}
                                </Link>

                                <Link href="/changelog" class="block rounded-xl px-3 py-3 text-sm font-medium text-slate-200 transition hover:bg-slate-100 dark:hover:bg-white/10" role="menuitem" @click="closeMenu">
                                    {{ t('Changelog') }}
                                </Link>

                                <div class="mt-2 rounded-xl border border-white/10 bg-white/[0.03] p-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('Theme') }}</span>
                                        <button
                                            type="button"
                                            role="switch"
                                            class="relative inline-flex h-9 w-20 items-center rounded-full border border-white/10 bg-white/10 p-1 text-slate-400 transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:bg-slate-950/70 dark:focus:ring-sky-400/30"
                                            :aria-checked="isDark"
                                            :aria-label="themeToggleLabel"
                                            :title="themeName"
                                            @click="toggleTheme"
                                        >
                                            <span class="absolute left-1 top-1 h-7 w-7 rounded-full bg-white shadow-sm shadow-slate-300 transition-transform dark:translate-x-11 dark:bg-slate-800 dark:shadow-black/30" aria-hidden="true"></span>
                                            <span class="relative z-10 flex h-7 w-7 items-center justify-center text-amber-500 transition dark:text-slate-500" aria-hidden="true">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="4" />
                                                    <path d="M12 2v2" />
                                                    <path d="M12 20v2" />
                                                    <path d="m4.93 4.93 1.41 1.41" />
                                                    <path d="m17.66 17.66 1.41 1.41" />
                                                    <path d="M2 12h2" />
                                                    <path d="M20 12h2" />
                                                    <path d="m6.34 17.66-1.41 1.41" />
                                                    <path d="m19.07 4.93-1.41 1.41" />
                                                </svg>
                                            </span>
                                            <span class="relative z-10 ml-auto flex h-7 w-7 items-center justify-center text-slate-400 transition dark:text-sky-200" aria-hidden="true">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M12 3a6 6 0 0 0 9 7.8A9 9 0 1 1 12 3Z" />
                                                </svg>
                                            </span>
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-2 rounded-xl border border-white/10 bg-white/[0.03] p-3">
                                    <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-400" for="locale-select">{{ t('Language') }}</label>
                                    <select id="locale-select" class="input" :value="locale" @change="updateLocale">
                                        <option v-for="availableLocale in locales" :key="availableLocale" :value="availableLocale">
                                            {{ languageNames[availableLocale] }}
                                        </option>
                                    </select>
                                </div>

                                <Link href="/logout" method="post" as="button" class="mt-2 flex w-full rounded-xl px-3 py-3 text-left text-sm font-semibold text-rose-200 transition hover:bg-rose-500/10 hover:text-rose-100" role="menuitem">
                                    {{ t('Logout') }}
                                </Link>
                            </div>
                        </div>
                    </nav>
                </div>

                <div v-if="isMobileNavOpen" id="mobile-navigation" class="mt-4 overflow-hidden rounded-3xl border border-white/10 bg-slate-950 p-3 shadow-2xl shadow-black/30 lg:hidden">
                    <nav class="space-y-4" :aria-label="t('Navigation')">
                        <section>
                            <p class="px-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('Navigation') }}</p>
                            <div class="mt-2 grid gap-1">
                                <Link
                                    v-for="item in primaryNav"
                                    :key="item.href"
                                    :href="item.href"
                                    class="flex items-center justify-between gap-3 rounded-2xl px-3 py-3 text-sm font-semibold transition hover:bg-slate-100 dark:hover:bg-white/10"
                                    :class="page.url.startsWith(item.href) ? 'bg-sky-400/10 text-sky-100' : 'text-slate-200'"
                                    @click="closeMobileNav"
                                >
                                    <span>{{ item.label }}</span>
                                    <span v-if="item.badge" class="rounded-full bg-rose-400/20 px-2 py-0.5 text-xs text-rose-100">{{ item.badge }}</span>
                                </Link>
                            </div>
                        </section>

                        <section v-if="settingsNav.length">
                            <p class="px-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('Settings') }}</p>
                            <div class="mt-2 grid gap-1">
                                <Link
                                    v-for="item in settingsNav"
                                    :key="item.href"
                                    :href="item.href"
                                    class="rounded-2xl px-3 py-3 text-sm transition hover:bg-slate-100 dark:hover:bg-white/10"
                                    :class="page.url.startsWith(item.href) ? 'bg-sky-400/10 text-sky-100' : 'text-slate-200'"
                                    @click="closeMobileNav"
                                >
                                    <span class="block font-semibold">{{ item.label }}</span>
                                    <span class="mt-0.5 block text-xs text-slate-400">{{ item.description }}</span>
                                </Link>
                            </div>
                        </section>

                        <section v-if="auth.user" class="rounded-2xl border border-white/10 bg-white/[0.03] p-3">
                            <div class="flex items-center gap-3">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-sky-400/15 font-bold uppercase text-sky-200">
                                    {{ auth.user.name.slice(0, 1) }}
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate font-semibold text-white">{{ auth.user.name }}</p>
                                    <p class="truncate text-xs text-slate-400">{{ auth.user.email }}</p>
                                </div>
                            </div>

                            <div class="mt-3 grid gap-1">
                                <Link
                                    v-for="item in accountNav"
                                    :key="item.href"
                                    :href="item.href"
                                    class="rounded-xl px-3 py-3 text-sm font-medium transition hover:bg-slate-100 dark:hover:bg-white/10"
                                    :class="page.url.startsWith(item.href) ? 'bg-sky-400/10 text-sky-100' : 'text-slate-200'"
                                    @click="closeMobileNav"
                                >
                                    {{ item.label }}
                                </Link>
                            </div>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div class="rounded-xl border border-white/10 bg-white/[0.03] p-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('Theme') }}</span>
                                        <button
                                            type="button"
                                            role="switch"
                                            class="relative inline-flex h-9 w-20 items-center rounded-full border border-white/10 bg-white/10 p-1 text-slate-400 transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:bg-slate-950/70 dark:focus:ring-sky-400/30"
                                            :aria-checked="isDark"
                                            :aria-label="themeToggleLabel"
                                            :title="themeName"
                                            @click="toggleTheme"
                                        >
                                            <span class="absolute left-1 top-1 h-7 w-7 rounded-full bg-white shadow-sm shadow-slate-300 transition-transform dark:translate-x-11 dark:bg-slate-800 dark:shadow-black/30" aria-hidden="true"></span>
                                            <span class="relative z-10 flex h-7 w-7 items-center justify-center text-amber-500 transition dark:text-slate-500" aria-hidden="true">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="4" />
                                                    <path d="M12 2v2" />
                                                    <path d="M12 20v2" />
                                                    <path d="m4.93 4.93 1.41 1.41" />
                                                    <path d="m17.66 17.66 1.41 1.41" />
                                                    <path d="M2 12h2" />
                                                    <path d="M20 12h2" />
                                                    <path d="m6.34 17.66-1.41 1.41" />
                                                    <path d="m19.07 4.93-1.41 1.41" />
                                                </svg>
                                            </span>
                                            <span class="relative z-10 ml-auto flex h-7 w-7 items-center justify-center text-slate-400 transition dark:text-sky-200" aria-hidden="true">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M12 3a6 6 0 0 0 9 7.8A9 9 0 1 1 12 3Z" />
                                                </svg>
                                            </span>
                                        </button>
                                    </div>
                                </div>

                                <div class="rounded-xl border border-white/10 bg-white/[0.03] p-3">
                                    <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-400" for="mobile-locale-select">{{ t('Language') }}</label>
                                    <select id="mobile-locale-select" class="input" :value="locale" @change="updateLocale">
                                        <option v-for="availableLocale in locales" :key="availableLocale" :value="availableLocale">
                                            {{ languageNames[availableLocale] }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <Link href="/logout" method="post" as="button" class="mt-3 flex w-full rounded-xl px-3 py-3 text-left text-sm font-semibold text-rose-200 transition hover:bg-rose-500/10 hover:text-rose-100" @click="closeMobileNav">
                                {{ t('Logout') }}
                            </Link>
                        </section>
                    </nav>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <h1 class="break-words text-2xl font-bold tracking-tight text-white sm:text-3xl">{{ title }}</h1>
                    <p class="mt-1 text-sm text-slate-400">{{ t(subtitle) }}</p>
                </div>
                <slot name="actions" />
            </div>

            <div v-if="flash.success" class="mb-5 rounded-2xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-100">
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="mb-5 rounded-2xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-100">
                {{ flash.error }}
            </div>

            <slot />
        </main>

        <div v-if="isQuickNavOpen" class="fixed inset-0 z-[80] flex items-start justify-center bg-slate-950/70 px-4 py-16 backdrop-blur-sm sm:py-24" role="dialog" aria-modal="true" :aria-label="t('Quick navigation')" @click.self="closeQuickNav">
            <section class="w-full max-w-2xl overflow-hidden rounded-3xl border border-white/10 bg-slate-950 shadow-2xl shadow-black/40" @keydown.stop="onQuickNavKeydown">
                <div class="border-b border-white/10 bg-white/[0.03] px-4 py-4">
                    <label class="sr-only" for="quick-nav-search">{{ t('Search views') }}</label>
                    <input id="quick-nav-search" ref="quickNavSearchRef" v-model="quickNavQuery" class="w-full bg-transparent text-base font-semibold text-white outline-none placeholder:text-slate-500" :placeholder="t('Search views')" autocomplete="off">
                </div>

                <div class="max-h-[55vh] overflow-y-auto p-2">
                    <button
                        v-for="(item, index) in filteredQuickNavItems"
                        :key="item.href"
                        type="button"
                        class="flex w-full items-center justify-between gap-3 rounded-2xl px-3 py-3 text-left transition"
                        :class="index === selectedQuickNavIndex ? 'bg-sky-400/10 text-sky-100' : 'text-slate-200 hover:bg-slate-100 dark:hover:bg-white/10'"
                        @mouseenter="selectedQuickNavIndex = index"
                        @click="visitQuickNavItem(item)"
                    >
                        <span class="min-w-0">
                            <span class="block truncate font-semibold">{{ item.label }}</span>
                            <span class="mt-0.5 block truncate text-xs text-slate-400">
                                {{ item.group }}
                                <template v-if="item.description"> / {{ item.description }}</template>
                            </span>
                        </span>
                        <kbd class="shrink-0 rounded-lg border border-white/10 bg-slate-950/60 px-2 py-1 text-[0.65rem] font-semibold tracking-wide text-slate-400">{{ item.shortcutLabel }}</kbd>
                    </button>

                    <p v-if="!filteredQuickNavItems.length" class="px-5 py-8 text-center text-sm text-slate-400">
                        {{ t('No matching views') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-white/10 bg-white/[0.03] px-4 py-3 text-xs text-slate-500">
                    <span><kbd class="rounded border border-white/10 bg-slate-950/60 px-1.5 py-0.5 font-semibold text-slate-400">Enter</kbd> {{ t('Open selected view') }}</span>
                    <span><kbd class="rounded border border-white/10 bg-slate-950/60 px-1.5 py-0.5 font-semibold text-slate-400">/</kbd> {{ t('Focus page search') }}</span>
                    <span><kbd class="rounded border border-white/10 bg-slate-950/60 px-1.5 py-0.5 font-semibold text-slate-400">Esc</kbd> {{ t('Close') }}</span>
                </div>
            </section>
        </div>

        <div v-if="showUpdateSummary && updateSummary" class="fixed inset-0 z-[70] flex items-end justify-center bg-slate-950/70 px-4 py-6 backdrop-blur-sm sm:items-center" role="dialog" aria-modal="true" :aria-label="t('Update summary')" @click.self="snoozeUpdateSummary">
            <section class="max-h-[90vh] w-full max-w-2xl overflow-hidden rounded-3xl border border-white/10 bg-slate-950 shadow-2xl shadow-black/40">
                <div class="border-b border-white/10 bg-white/[0.03] px-5 py-4 sm:px-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-sky-300">{{ t('Update summary') }}</p>
                            <h2 class="mt-1 text-xl font-bold text-white">{{ t('What changed in VolumeVault') }}</h2>
                            <p class="mt-2 text-sm text-slate-400">{{ t('VolumeVault was updated. Review the important changes before continuing.') }}</p>
                        </div>
                        <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-white/10 bg-white/5 text-slate-300 transition hover:bg-slate-100 hover:text-slate-950 dark:hover:bg-white/10 dark:hover:text-white" :aria-label="t('Remind me later')" @click="snoozeUpdateSummary">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M18 6 6 18" />
                                <path d="m6 6 12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="max-h-[55vh] space-y-5 overflow-y-auto px-5 py-5 sm:px-6">
                    <section v-for="section in updateSummary.sections" :key="section.version" class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold text-white">{{ sectionTitle(section) }}</h3>
                            <span v-if="section.date" class="text-xs text-slate-500">{{ t('Release date: {date}', { date: section.date }) }}</span>
                        </div>
                        <div class="space-y-3">
                            <article v-for="item in section.items" :key="`${section.version}-${item.title}`" class="rounded-xl border border-white/10 bg-slate-950/60 p-3">
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold" :class="changelogTypeClass(item.type)">{{ changelogTypeLabel(item.type) }}</span>
                                <h4 class="mt-2 font-semibold text-white">{{ item.title }}</h4>
                                <p class="mt-1 text-sm text-slate-400">{{ item.description }}</p>
                            </article>
                        </div>
                    </section>
                </div>

                <div class="flex flex-col gap-3 border-t border-white/10 bg-white/[0.03] px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <Link href="/changelog" class="btn-secondary" @click="snoozeUpdateSummary">{{ t('View full changelog') }}</Link>
                    <button type="button" class="btn-primary" @click="markUpdateSummarySeen">{{ t('Mark as read') }}</button>
                </div>
            </section>
        </div>

        <footer class="border-t border-white/10 bg-slate-950/30">
            <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-5 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                <p>
                    &copy; {{ currentYear }}
                    <a :href="githubProfileUrl" class="font-medium text-slate-400 transition hover:text-sky-300" target="_blank" rel="noopener noreferrer">Darkdragon14</a>
                    <span class="mx-1.5 text-slate-600">&middot;</span>
                    <Link href="/changelog" class="transition hover:text-sky-300">VolumeVault {{ app.version || 'main' }}</Link>
                    <template v-if="availableUpdate">
                        <span class="mx-1.5 text-slate-600">&middot;</span>
                        <Link href="/changelog" class="rounded-full border border-sky-300/20 bg-sky-400/10 px-2 py-0.5 font-medium text-sky-300 transition hover:bg-sky-400/15 hover:text-sky-200">
                            {{ t('Version {version} available', { version: availableUpdate.version }) }}
                        </Link>
                    </template>
                </p>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <span v-if="auth.user && areKeyboardShortcutsEnabled" class="hidden items-center gap-1.5 text-slate-400 sm:inline-flex">
                        <span>{{ t('Quick nav') }}</span>
                        <span class="text-slate-600">:</span>
                        <kbd class="rounded border border-white/10 bg-slate-950/60 px-1.5 py-0.5 text-[0.65rem] font-semibold uppercase tracking-wide text-slate-400">{{ quickNavModifierLabel }}</kbd>
                        <span class="text-slate-600">+</span>
                        <kbd class="rounded border border-white/10 bg-slate-950/60 px-1.5 py-0.5 text-[0.65rem] font-semibold uppercase tracking-wide text-slate-400">K</kbd>
                    </span>
                    <a :href="githubRepoUrl" class="inline-flex items-center gap-1.5 text-slate-400 transition hover:text-sky-300" target="_blank" rel="noopener noreferrer" :aria-label="t('Open the GitHub repository')">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.021c0 4.428 2.865 8.184 6.839 9.504.5.092.682-.217.682-.483 0-.237-.009-.866-.014-1.7-2.782.605-3.369-1.343-3.369-1.343-.455-1.158-1.11-1.466-1.11-1.466-.908-.621.069-.608.069-.608 1.004.071 1.532 1.033 1.532 1.033.892 1.53 2.341 1.088 2.91.832.091-.647.35-1.088.636-1.338-2.221-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.987 1.029-2.687-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0 1 12 6.852c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.203 2.398.1 2.651.64.7 1.028 1.594 1.028 2.687 0 3.848-2.337 4.695-4.566 4.944.359.31.678.922.678 1.858 0 1.34-.012 2.421-.012 2.75 0 .268.18.58.688.482A10.024 10.024 0 0 0 22 12.021C22 6.484 17.523 2 12 2Z" clip-rule="evenodd" />
                        </svg>
                        <span>GitHub</span>
                    </a>
                    <a :href="githubIssuesUrl" class="text-slate-400 transition hover:text-sky-300" target="_blank" rel="noopener noreferrer">
                        {{ t('Report a problem or suggest an improvement') }}
                    </a>
                </div>
            </div>
        </footer>
    </div>
</template>
