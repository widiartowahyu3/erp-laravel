<script setup>
import { PackageOpen } from 'lucide-vue-next';

defineProps({
    title: {
        type: String,
        default: 'Tidak Ada Data',
    },
    description: {
        type: String,
        default: 'Belum ada data yang tersedia untuk ditampilkan.',
    },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center text-center p-8 sm:p-12 space-y-4">
        <div class="w-14 h-14 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 flex items-center justify-center shrink-0">
            <slot name="icon">
                <PackageOpen class="w-7 h-7" aria-hidden="true" />
            </slot>
        </div>

        <div class="space-y-1 max-w-sm">
            <h3 class="text-base font-semibold text-slate-800 dark:text-slate-200">
                {{ title }}
            </h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
                {{ description }}
            </p>
        </div>

        <div v-if="$slots.actions" class="pt-2">
            <slot name="actions" />
        </div>
    </div>
</template>
