<script setup>
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import IconButton from './IconButton.vue';

const props = defineProps({
    meta: {
        type: Object,
        required: true,
        validator: (value) =>
            typeof value === 'object' &&
            value !== null &&
            ('current_page' in value || 'page' in value) &&
            'last_page' in value &&
            'total' in value,
    },
    perPage: {
        type: [Number, String],
        default: undefined,
    },
    perPageOptions: {
        type: Array,
        default: () => [10, 25, 50, 100],
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['change-page', 'change-per-page', 'update:perPage']);

const currentPage = computed(() => Number(props.meta.current_page ?? props.meta.page) || 1);
const lastPage = computed(() => Number(props.meta.last_page) || 1);
const effectivePerPage = computed(() => Number(props.perPage ?? props.meta.per_page) || 25);
const total = computed(() => Number(props.meta.total) || 0);

const from = computed(() => {
    if (total.value === 0) return 0;
    return props.meta.from ?? ((currentPage.value - 1) * effectivePerPage.value + 1);
});

const to = computed(() => {
    if (total.value === 0) return 0;
    return props.meta.to ?? Math.min(currentPage.value * effectivePerPage.value, total.value);
});

const pages = computed(() => {
    const current = currentPage.value;
    const last = lastPage.value;
    const delta = 1;

    if (last <= 7) {
        return Array.from({ length: last }, (_, i) => i + 1);
    }

    const range = [];
    for (
        let i = Math.max(2, current - delta);
        i <= Math.min(last - 1, current + delta);
        i++
    ) {
        range.push(i);
    }

    if (current - delta > 2) {
        range.unshift('...');
    }
    range.unshift(1);

    if (current + delta < last - 1) {
        range.push('...');
    }
    range.push(last);

    return range;
});

function goToPage(page) {
    if (props.disabled || page === '...' || page === currentPage.value) return;
    if (page >= 1 && page <= lastPage.value) {
        emit('change-page', page);
    }
}

function handlePerPageChange(event) {
    const val = Number(event.target.value);
    emit('change-per-page', val);
    emit('update:perPage', val);
}
</script>

<template>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 py-3 px-4 border-t border-slate-200 dark:border-slate-800 text-xs sm:text-sm text-slate-600 dark:text-slate-400">
        <!-- Summary & Per-Page -->
        <div class="flex items-center gap-3">
            <span v-if="total > 0">
                Menampilkan <span class="font-semibold text-slate-900 dark:text-slate-100">{{ from }}</span> sampai <span class="font-semibold text-slate-900 dark:text-slate-100">{{ to }}</span> dari <span class="font-semibold text-slate-900 dark:text-slate-100">{{ total }}</span> data
            </span>
            <span v-else>
                Menampilkan 0 dari 0 data
            </span>

            <div v-if="perPageOptions.length > 0" class="flex items-center gap-1.5 ml-2">
                <label for="per-page-select" class="text-xs text-slate-500 dark:text-slate-400">
                    Per halaman:
                </label>
                <select
                    id="per-page-select"
                    :value="effectivePerPage"
                    :disabled="disabled"
                    aria-label="Jumlah data per halaman"
                    class="h-8 px-2 py-1 text-xs rounded-md border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 cursor-pointer"
                    @change="handlePerPageChange"
                >
                    <option v-for="opt in perPageOptions" :key="opt" :value="opt">
                        {{ opt }}
                    </option>
                </select>
            </div>
        </div>

        <!-- Page Numbers Navigation -->
        <nav
            v-if="lastPage > 1"
            aria-label="Navigasi Halaman"
            class="flex items-center gap-1"
        >
            <IconButton
                variant="ghost"
                size="sm"
                aria-label="Halaman sebelumnya"
                :disabled="disabled || currentPage <= 1"
                @click="goToPage(currentPage - 1)"
            >
                <ChevronLeft class="w-4 h-4" aria-hidden="true" />
            </IconButton>

            <template v-for="(p, idx) in pages" :key="idx">
                <span
                    v-if="p === '...'"
                    class="px-2 py-1 text-slate-400 dark:text-slate-600 select-none"
                    aria-hidden="true"
                >
                    ...
                </span>
                <button
                    v-else
                    type="button"
                    :aria-current="p === currentPage ? 'page' : undefined"
                    :aria-label="`Ke halaman ${p}`"
                    :disabled="disabled"
                    :class="[
                        'min-w-[32px] h-8 px-2 rounded-md font-medium text-xs transition-colors cursor-pointer',
                        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600',
                        p === currentPage
                            ? 'bg-indigo-600 text-white font-semibold'
                            : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 active:bg-slate-200 dark:active:bg-slate-700',
                    ]"
                    @click="goToPage(p)"
                >
                    {{ p }}
                </button>
            </template>

            <IconButton
                variant="ghost"
                size="sm"
                aria-label="Halaman berikutnya"
                :disabled="disabled || currentPage >= lastPage"
                @click="goToPage(currentPage + 1)"
            >
                <ChevronRight class="w-4 h-4" aria-hidden="true" />
            </IconButton>
        </nav>
    </div>
</template>
