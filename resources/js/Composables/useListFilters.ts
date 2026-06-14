import { computed, onBeforeUnmount, type Ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

type FilterRef = Ref<string>;

const normalizeSearch = (value: unknown) => String(value ?? '').trim().toLowerCase();

export function readFiltersFromUrl(params: Record<string, FilterRef>): void {
    if (typeof window === 'undefined') return;

    const searchParams = new URLSearchParams(window.location.search);

    for (const [key, ref] of Object.entries(params)) {
        const value = searchParams.get(key);
        if (value !== null) {
            ref.value = value;
        }
    }
}

export function useUrlFilters(filters: Record<string, FilterRef>, options?: { debounce?: number }): void {
    const syncToUrl = () => {
        if (typeof window === 'undefined') return;

        const url = new URL(window.location.href);

        for (const [key, ref] of Object.entries(filters)) {
            if (ref.value) {
                url.searchParams.set(key, ref.value);
            } else {
                url.searchParams.delete(key);
            }
        }

        router.replace({
            url: url.pathname + url.search + url.hash,
            preserveState: true,
            preserveScroll: true,
        });
    };

    if (options?.debounce) {
        let timeout: ReturnType<typeof setTimeout>;
        watch(
            () => Object.values(filters).map((f) => f.value),
            () => {
                clearTimeout(timeout);
                timeout = setTimeout(syncToUrl, options.debounce);
            },
            { deep: true },
        );
        onBeforeUnmount(() => clearTimeout(timeout));
    } else {
        watch(
            () => Object.values(filters).map((f) => f.value),
            syncToUrl,
            { deep: true },
        );
    }
}

export function matchesSearch(values: unknown[], query: string): boolean {
    const normalizedQuery = normalizeSearch(query);

    if (!normalizedQuery) return true;

    return normalizeSearch(values.filter(Boolean).join(' ')).includes(normalizedQuery);
}

export function uniqueSortedOptions<T>(items: T[], resolver: (item: T) => unknown): string[] {
    const values = items
        .map(resolver)
        .filter((value) => value !== null && value !== undefined && value !== '')
        .map(String);

    return [...new Set(values)].sort((left, right) => left.localeCompare(right));
}

export function useListFilters(filters: FilterRef[]) {
    const activeFilterCount = computed(() => filters.filter((filter) => filter.value !== '').length);
    const hasActiveFilters = computed(() => activeFilterCount.value > 0);

    const resetFilters = () => {
        filters.forEach((filter) => {
            filter.value = '';
        });
    };

    return { activeFilterCount, hasActiveFilters, resetFilters };
}
