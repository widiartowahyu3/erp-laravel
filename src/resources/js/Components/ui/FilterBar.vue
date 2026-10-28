<script setup>
import { ref, watch, onUnmounted } from 'vue';
import { Search, X, RotateCcw } from 'lucide-vue-next';
import IconButton from './IconButton.vue';
import Button from './Button.vue';

const props = defineProps({
    search: {
        type: String,
        default: '',
    },
    searchPlaceholder: {
        type: String,
        default: 'Cari data...',
    },
    debounce: {
        type: Number,
        default: 300,
    },
    hasActiveFilters: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:search', 'search', 'reset']);

const localSearch = ref(props.search);
let debounceTimer = null;

watch(
    () => props.search,
    (val) => {
        localSearch.value = val;
    }
);

function handleInput(event) {
    const val = event.target.value;
    localSearch.value = val;
    emit('update:search', val);

    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        emit('search', val);
    }, props.debounce);
}

function clearSearch() {
    localSearch.value = '';
    emit('update:search', '');
    emit('search', '');
}

onUnmounted(() => {
    if (debounceTimer) clearTimeout(debounceTimer);
});
</script>

<template>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-4 bg-slate-50/50 dark:bg-slate-800/30 border-b border-slate-200 dark:border-slate-800">
        <!-- Search Field -->
        <div class="relative flex-1 max-w-md">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400 dark:text-slate-500">
                <Search class="w-4 h-4" aria-hidden="true" />
            </div>
            <input
                type="text"
                :value="localSearch"
                :placeholder="searchPlaceholder"
                aria-label="Cari data"
                class="w-full min-h-[40px] pl-9 pr-9 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 focus-visible:border-indigo-600 transition-colors"
                @input="handleInput"
            />
            <div v-if="localSearch" class="absolute inset-y-0 right-0 flex items-center pr-1">
                <IconButton
                    type="button"
                    variant="ghost"
                    size="sm"
                    aria-label="Hapus pencarian"
                    @click="clearSearch"
                >
                    <X class="w-4 h-4" aria-hidden="true" />
                </IconButton>
            </div>
        </div>

        <!-- Filter Slots & Reset Button -->
        <div class="flex items-center gap-2.5 flex-wrap">
            <slot />

            <Button
                v-if="hasActiveFilters || localSearch"
                variant="ghost"
                size="sm"
                class="text-xs text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100"
                @click="emit('reset')"
            >
                <RotateCcw class="w-3.5 h-3.5 mr-1.5" aria-hidden="true" />
                <span>Reset</span>
            </Button>
        </div>
    </div>
</template>
