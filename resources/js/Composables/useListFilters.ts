import { computed, type Ref } from 'vue';

type FilterRef = Ref<string>;

const normalizeSearch = (value: unknown) => String(value ?? '').trim().toLowerCase();

export function initialSearchFromUrl(param = 'search'): string {
    if (typeof window === 'undefined') return '';

    return new URLSearchParams(window.location.search).get(param) || '';
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
