<script setup>
import Card from '../Components/ui/Card.vue';

defineProps({
    title: {
        type: String,
        default: 'ERP Distribusi',
    },
});
</script>

<template>
    <div
        class="min-h-screen bg-slate-50 dark:bg-slate-950 flex flex-col justify-center items-center p-4 sm:p-6 text-slate-800 dark:text-slate-100 transition-colors"
    >
        <div class="mb-6 flex items-center gap-2.5">
            <div
                class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold text-xl shadow-xs"
            >
                E
            </div>
            <span class="font-bold text-slate-900 dark:text-white text-2xl tracking-tight"
                >ERP Distribusi</span
            >
        </div>

        <div class="w-full max-w-md">
            <Card padding="lg" class="shadow-sm">
                <slot />
            </Card>
        </div>

        <div class="mt-8 text-center text-xs text-slate-500 dark:text-slate-400">
            &copy; 2026 ERP Distribusi &amp; Trading. Seluruh hak cipta dilindungi.
        </div>
    </div>
</template>
