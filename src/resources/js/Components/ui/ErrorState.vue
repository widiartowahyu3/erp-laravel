<script setup>
import { AlertCircle, RotateCcw } from 'lucide-vue-next';
import Button from './Button.vue';

defineProps({
    title: {
        type: String,
        default: 'Terjadi Kesalahan',
    },
    message: {
        type: String,
        default: 'Gagal memuat data dari server. Silakan coba kembali nanti.',
    },
    retryable: {
        type: Boolean,
        default: false,
    },
    retryText: {
        type: String,
        default: 'Coba Lagi',
    },
});

const emit = defineEmits(['retry']);
</script>

<template>
    <div
        role="alert"
        class="flex flex-col items-center justify-center text-center p-8 sm:p-12 space-y-4"
    >
        <div class="w-14 h-14 rounded-full bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
            <AlertCircle class="w-7 h-7" aria-hidden="true" />
        </div>

        <div class="space-y-1 max-w-sm">
            <h3 class="text-base font-semibold text-slate-800 dark:text-slate-200">
                {{ title }}
            </h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
                {{ message }}
            </p>
        </div>

        <div v-if="retryable || $slots.actions" class="pt-2">
            <slot name="actions">
                <Button
                    variant="secondary"
                    size="sm"
                    @click="emit('retry')"
                >
                    <RotateCcw class="w-4 h-4 mr-1.5" aria-hidden="true" />
                    <span>{{ retryText }}</span>
                </Button>
            </slot>
        </div>
    </div>
</template>
