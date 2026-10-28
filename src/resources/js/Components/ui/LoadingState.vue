<script setup>
import Spinner from './Spinner.vue';

defineProps({
    text: {
        type: String,
        default: 'Memuat data...',
    },
});
</script>

<template>
    <div
        role="status"
        class="flex flex-col items-center justify-center text-center p-8 sm:p-12 space-y-3"
    >
        <Spinner size="lg" :label="text" class="text-indigo-600 dark:text-indigo-400" />
        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">
            {{ text }}
        </p>
    </div>
</template>
