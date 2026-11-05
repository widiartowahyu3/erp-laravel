<script setup>
import { computed } from 'vue';
import { Download } from 'lucide-vue-next';

const props = defineProps({
    url: {
        type: String,
        required: true,
    },
    query: {
        type: Object,
        default: () => ({}),
    },
});

// A plain link, because the browser has to receive the file as a download.
const href = computed(() => {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(props.query)) {
        if (value !== null && value !== undefined && value !== '' && value !== false) {
            params.set(key, value === true ? '1' : String(value));
        }
    }

    params.set('export', 'csv');

    return `${props.url}?${params.toString()}`;
});
</script>

<template>
    <a
        :href="href"
        class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 print:hidden"
    >
        <Download class="h-4 w-4" aria-hidden="true" />
        Ekspor CSV
    </a>
</template>
