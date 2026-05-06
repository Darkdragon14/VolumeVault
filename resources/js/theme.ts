import { router, usePage } from '@inertiajs/vue3';
import { computed, readonly, ref, watchEffect } from 'vue';

export type Theme = 'light' | 'dark';

const storageKey = 'volumevault-theme';
const defaultTheme: Theme = 'dark';

function isTheme(value: unknown): value is Theme {
    return value === 'light' || value === 'dark';
}

function readStoredTheme(): Theme | null {
    if (typeof window === 'undefined') return null;

    const stored = (() => {
        try {
            return window.localStorage.getItem(storageKey);
        } catch {
            return null;
        }
    })();

    return isTheme(stored) ? stored : null;
}

function resolveInitialTheme(): Theme {
    return readStoredTheme() || defaultTheme;
}

const theme = ref<Theme>(resolveInitialTheme());

function applyTheme(value: Theme): void {
    if (typeof document === 'undefined' || typeof window === 'undefined') return;

    document.documentElement.classList.toggle('dark', value === 'dark');
    document.documentElement.dataset.theme = value;
    document.documentElement.style.colorScheme = value;

    try {
        window.localStorage.setItem(storageKey, value);
    } catch {
        // Local storage can be disabled; the class on <html> is enough for this page view.
    }
}

export function initializeTheme(): void {
    applyTheme(theme.value);
}

export function useTheme() {
    const page = usePage();
    const userTheme = computed(() => (page.props.auth as any)?.user?.theme);
    const hasAuthenticatedUser = computed(() => Boolean((page.props.auth as any)?.user));

    watchEffect(() => {
        if (! isTheme(userTheme.value)) return;

        theme.value = userTheme.value;
        applyTheme(theme.value);
    });

    const setTheme = (value: Theme) => {
        theme.value = value;
        applyTheme(value);

        if (hasAuthenticatedUser.value) {
            router.patch('/user/theme', { theme: value }, {
                preserveScroll: true,
                preserveState: true,
            });
        }
    };

    const toggleTheme = () => setTheme(theme.value === 'dark' ? 'light' : 'dark');

    return {
        theme: readonly(theme),
        isDark: computed(() => theme.value === 'dark'),
        setTheme,
        toggleTheme,
    };
}
