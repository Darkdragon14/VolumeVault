<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from '@/i18n';
import { useTheme } from '@/theme';

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
const updateLocale = (event: Event) => router.patch('/user/locale', { locale: (event.target as HTMLSelectElement).value }, { preserveScroll: true });
const themeToggleLabel = computed(() => isDark.value ? t('Switch to light theme') : t('Switch to dark theme'));
const themeName = computed(() => isDark.value ? t('Dark theme') : t('Light theme'));
const currentYear = new Date().getFullYear();
const githubProfileUrl = 'https://github.com/Darkdragon14';
const githubRepoUrl = 'https://github.com/Darkdragon14/VolumeVault';
const githubIssuesUrl = 'https://github.com/Darkdragon14/VolumeVault/issues';

const openMenu = ref<'settings' | 'user' | null>(null);
const headerRef = ref<HTMLElement | null>(null);

const primaryNav = computed(() => [
    { label: t('Dashboard'), href: '/dashboard' },
    { label: t('Volumes'), href: '/volumes' },
    { label: t('Backup jobs'), href: '/backup-jobs' },
]);

const settingsNav = computed(() => [
    ...(can.value.manageSensitiveData ? [
        { label: t('Destinations'), description: t('Storage targets'), href: '/destinations' },
        { label: t('Notifications'), description: t('Alert channels'), href: '/notifications' },
        { label: t('Installation save'), description: t('Export and import setup'), href: '/installation-save' },
    ] : []),
    ...(can.value.manageUsers ? [
        { label: t('Users'), description: t('Team access'), href: '/users' },
    ] : []),
]);

const hasActiveItem = (items: { href: string }[]) => items.some((item) => page.url.startsWith(item.href));
const toggleMenu = (menu: 'settings' | 'user') => openMenu.value = openMenu.value === menu ? null : menu;
const closeMenu = () => openMenu.value = null;

const closeOnOutsideClick = (event: MouseEvent) => {
    if (headerRef.value && !headerRef.value.contains(event.target as Node)) {
        closeMenu();
    }
};

const closeOnEscape = (event: KeyboardEvent) => {
    if (event.key === 'Escape') closeMenu();
};

onMounted(() => {
    document.addEventListener('click', closeOnOutsideClick);
    document.addEventListener('keydown', closeOnEscape);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeOnOutsideClick);
    document.removeEventListener('keydown', closeOnEscape);
});
</script>

<template>
    <div class="app-shell">
        <header ref="headerRef" class="relative z-50 border-b border-white/10 bg-slate-950/70 backdrop-blur">
            <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-4 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
                <Link href="/dashboard" class="flex min-w-0 items-center gap-3">
                    <img :src="'/logo.png'" alt="" class="h-10 w-auto shrink-0 object-contain">
                    <div class="min-w-0">
                        <p class="text-lg font-bold tracking-tight">VolumeVault</p>
                        <p class="text-xs text-slate-400">{{ t('Back up and restore Docker volumes') }}</p>
                    </div>
                </Link>
                <nav class="flex min-w-0 flex-col gap-3 lg:flex-row lg:items-center">
                    <div class="flex flex-wrap gap-2">
                        <Link
                            v-for="item in primaryNav"
                            :key="item.href"
                            :href="item.href"
                            class="rounded-xl px-3 py-2 text-sm font-medium text-slate-300 transition hover:bg-white/10 hover:text-white"
                            :class="{ 'bg-white/10 text-white': page.url.startsWith(item.href) }"
                            @click="closeMenu"
                        >
                            {{ item.label }}
                        </Link>

                        <div v-if="settingsNav.length" class="relative">
                            <button
                                class="group inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium text-slate-300 transition hover:bg-white/10 hover:text-white"
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
                                    class="block rounded-xl px-3 py-3 text-sm transition hover:bg-white/10"
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
                            class="group flex w-full items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-3 py-2 text-left text-sm text-slate-200 transition hover:bg-white/10 lg:w-auto"
                            :class="{ 'bg-white/10 text-white': openMenu === 'user' || page.url.startsWith('/profile') || page.url.startsWith('/api-tokens') }"
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

                            <Link href="/profile" class="mt-2 block rounded-xl px-3 py-3 text-sm font-medium text-slate-200 transition hover:bg-white/10" role="menuitem" @click="closeMenu">
                                {{ t('Edit profile') }}
                            </Link>

                            <Link v-if="can.manageUsers" href="/api-tokens" class="block rounded-xl px-3 py-3 text-sm font-medium text-slate-200 transition hover:bg-white/10" role="menuitem" @click="closeMenu">
                                {{ t('API tokens') }}
                            </Link>

                            <div class="mt-2 rounded-xl border border-white/10 bg-white/[0.03] p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('Theme') }}</span>
                                    <button
                                        type="button"
                                        role="switch"
                                        class="relative inline-flex h-9 w-20 items-center rounded-full border border-white/10 bg-white/10 p-1 text-slate-400 transition focus:outline-none focus:ring-2 focus:ring-sky-400/30 dark:bg-slate-950/70"
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

        <footer class="border-t border-white/10 bg-slate-950/30">
            <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-5 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                <p>
                    &copy; {{ currentYear }}
                    <a :href="githubProfileUrl" class="font-medium text-slate-400 transition hover:text-sky-300" target="_blank" rel="noopener noreferrer">Darkdragon14</a>
                </p>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
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
