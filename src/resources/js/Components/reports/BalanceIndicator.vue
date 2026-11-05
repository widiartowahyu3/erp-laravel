<script setup>
import { AlertTriangle, CheckCircle2 } from 'lucide-vue-next';

defineProps({
    balanced: {
        type: Boolean,
        required: true,
    },
});
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1.5 text-sm font-semibold',
            balanced ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400',
        ]"
    >
        <CheckCircle2 v-if="balanced" class="h-4 w-4" aria-hidden="true" />
        <AlertTriangle v-else class="h-4 w-4" aria-hidden="true" />
        {{ balanced ? 'Seimbang' : 'Tidak seimbang' }}
    </span>
</template>
