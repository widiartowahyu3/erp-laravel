<script setup>
import { computed } from 'vue';
import {
    CheckCircle2,
    AlertTriangle,
    AlertCircle,
    Info,
    X,
} from 'lucide-vue-next';
import IconButton from './IconButton.vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'info',
        validator: (value) => ['success', 'warning', 'danger', 'info'].includes(value),
    },
    title: {
        type: String,
        default: '',
    },
    dismissible: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['dismiss']);

const role = computed(() =>
    ['danger', 'warning'].includes(props.variant) ? 'alert' : 'status'
);

const iconMap = {
    success: CheckCircle2,
    warning: AlertTriangle,
    danger: AlertCircle,
    info: Info,
};

const variantClasses = {
    success:
        'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-900 dark:text-emerald-200 border-emerald-200 dark:border-emerald-800',
    warning:
        'bg-amber-50 dark:bg-amber-950/40 text-amber-900 dark:text-amber-200 border-amber-200 dark:border-amber-800',
    danger:
        'bg-rose-50 dark:bg-rose-950/40 text-rose-900 dark:text-rose-200 border-rose-200 dark:border-rose-800',
    info:
        'bg-sky-50 dark:bg-sky-950/40 text-sky-900 dark:text-sky-200 border-sky-200 dark:border-sky-800',
};

const iconClasses = {
    success: 'text-emerald-600 dark:text-emerald-400',
    warning: 'text-amber-600 dark:text-amber-400',
    danger: 'text-rose-600 dark:text-rose-400',
    info: 'text-sky-600 dark:text-sky-400',
};
</script>

<template>
    <div
        :role="role"
        :class="[
            'flex items-start gap-3 p-4 rounded-xl border text-sm transition-colors',
            variantClasses[variant],
        ]"
    >
        <component
            :is="iconMap[variant]"
            :class="['w-5 h-5 shrink-0 mt-0.5', iconClasses[variant]]"
            aria-hidden="true"
        />
        <div class="flex-1 space-y-1">
            <h4 v-if="title" class="font-semibold text-sm leading-5">
                {{ title }}
            </h4>
            <div class="text-sm leading-relaxed opacity-90">
                <slot />
            </div>
        </div>
        <div v-if="dismissible" class="shrink-0 -mr-1 -mt-1">
            <IconButton
                type="button"
                variant="ghost"
                size="sm"
                aria-label="Tutup pemberitahuan"
                @click="emit('dismiss')"
            >
                <X class="w-4 h-4" aria-hidden="true" />
            </IconButton>
        </div>
    </div>
</template>
