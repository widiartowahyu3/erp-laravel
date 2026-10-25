<script setup>
defineProps({
    title: {
        type: String,
        default: 'ERP Distribusi',
    },
});
</script>

<template>
    <div
        class="min-h-screen bg-slate-50 flex flex-col justify-center items-center p-4 sm:p-6 text-slate-800"
    >
        <div class="mb-6 flex items-center gap-2">
            <div
                class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold text-xl shadow-xs"
            >
                E
            </div>
            <span class="font-bold text-slate-900 text-2xl tracking-tight"
                >ERP Distribusi</span
            >
        </div>

        <div
            class="w-full max-w-md bg-white border border-slate-200 rounded-xl shadow-xs p-6 sm:p-8"
        >
            <slot />
        </div>

        <div class="mt-8 text-center text-xs text-slate-500">
            &copy; 2026 ERP Distribusi & Trading. Seluruh hak cipta dilindungi.
        </div>
    </div>
</template>
