<script setup lang="ts">
import { useI18n } from '@/i18n';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    data: { data: unknown[]; meta: { current_page: number; per_page: number; total: number; last_page: number } };
    baseUrl: string;
    /** Extra query params to preserve (search, filters, etc.) */
    extraParams?: Record<string, string | number | undefined>;
    /** Query param name carrying the page number (lets several paginators coexist on one page). */
    pageParam?: string;
}>();

const { t } = useI18n();
const pageKey = computed(() => props.pageParam ?? 'page');
const meta = computed(() => props.data.meta);
const totalPages = computed(() => meta.value.last_page);
const currentPage = computed(() => meta.value.current_page);
const total = computed(() => meta.value.total);

const pageOptions = [10, 20, 50, 100, 0] as const;
const perPageLabel = (value: number) => value === 0 ? t('All') : String(value);

const currentPerPage = computed(() => meta.value.per_page === 0 ? 0 : meta.value.per_page);

function goToPage(page: number) {
    if (page < 1 || page > totalPages.value || page === currentPage.value) return;

    router.get(props.baseUrl, {
        ...props.extraParams,
        [pageKey.value]: page,
        per_page: currentPerPage.value === 0 ? 'all' : currentPerPage.value,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function changePerPage(event: Event) {
    const value = (event.target as HTMLSelectElement).value;
    const perPage = value === 'all' || value === '0' ? 'all' : Number(value);

    router.get(props.baseUrl, {
        ...props.extraParams,
        [pageKey.value]: 1,
        per_page: perPage,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

const visiblePages = computed(() => {
    const pages: (number | '...')[] = [];
    const total = totalPages.value;
    const current = currentPage.value;

    if (total <= 7) {
        for (let i = 1; i <= total; i++) pages.push(i);
        return pages;
    }

    pages.push(1);

    if (current > 3) {
        pages.push('...');
    }

    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);

    for (let i = start; i <= end; i++) {
        pages.push(i);
    }

    if (current < total - 2) {
        pages.push('...');
    }

    pages.push(total);

    return pages;
});
</script>

<template>
    <div v-if="total > 0" class="flex flex-wrap items-center justify-between gap-4 border-t border-white/10 px-4 py-3">
        <div class="flex items-center gap-2 text-sm text-slate-400">
            <span>{{ t('{count} results', { count: total }) }}</span>
            <span class="text-slate-600">·</span>
            <label class="flex items-center gap-1.5">
                <span>{{ t('Per page') }}</span>
                <select
                    :value="currentPerPage"
                    class="input !w-[5rem] !rounded-lg !px-2 !py-1 !text-xs dark:[color-scheme:dark]"
                    @change="changePerPage"
                >
                    <option v-for="option in pageOptions" :key="option" :value="option" class="bg-white text-slate-950 dark:bg-slate-950 dark:text-slate-100">{{ perPageLabel(option) }}</option>
                </select>
            </label>
        </div>

        <nav v-if="totalPages > 1" class="flex items-center gap-1" :aria-label="t('Pagination')">
            <button
                type="button"
                class="rounded px-2.5 py-1.5 text-sm text-slate-400 hover:bg-white/5 hover:text-white disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="currentPage === 1"
                @click="goToPage(currentPage - 1)"
            >
                {{ t('Previous') }}
            </button>

            <template v-for="(page, index) in visiblePages" :key="index">
                <span v-if="page === '...'" class="px-2 py-1 text-sm text-slate-600">…</span>
                <button
                    v-else
                    type="button"
                    class="min-w-[2rem] rounded px-2.5 py-1.5 text-sm font-medium"
                    :class="page === currentPage ? 'bg-sky-500/20 text-sky-200' : 'text-slate-400 hover:bg-white/5 hover:text-white'"
                    @click="goToPage(page)"
                >
                    {{ page }}
                </button>
            </template>

            <button
                type="button"
                class="rounded px-2.5 py-1.5 text-sm text-slate-400 hover:bg-white/5 hover:text-white disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="currentPage === totalPages"
                @click="goToPage(currentPage + 1)"
            >
                {{ t('Next') }}
            </button>
        </nav>
    </div>
</template>
