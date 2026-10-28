<script setup>
import Spinner from './Spinner.vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (value) => ['primary', 'secondary', 'danger', 'ghost'].includes(value),
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg'].includes(value),
    },
    type: {
        type: String,
        default: 'button',
        validator: (value) => ['button', 'submit', 'reset'].includes(value),
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    loading: {
        type: Boolean,
        default: false,
    },
});

const variantClasses = {
    primary:
        'bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white focus-visible:ring-indigo-500 shadow-xs',
    secondary:
        'border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 active:bg-slate-100 dark:active:bg-slate-600 focus-visible:ring-slate-400 shadow-xs',
    danger:
        'bg-rose-600 hover:bg-rose-700 active:bg-rose-800 text-white focus-visible:ring-rose-500 shadow-xs',
    ghost:
        'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 active:bg-slate-200 dark:active:bg-slate-700 focus-visible:ring-slate-400',
};

const sizeClasses = {
    sm: 'min-h-[40px] px-3 py-1.5 text-xs font-medium rounded-lg gap-1.5',
    md: 'min-h-[40px] px-4 py-2 text-sm font-medium rounded-lg gap-2',
    lg: 'min-h-[44px] px-5 py-2.5 text-base font-medium rounded-xl gap-2.5',
};

const spinnerSizeMap = {
    sm: 'sm',
    md: 'sm',
    lg: 'md',
};
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :class="[
            'inline-flex items-center justify-center font-medium transition-colors select-none cursor-pointer',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900',
            variantClasses[variant],
            sizeClasses[size],
            (disabled || loading) ? 'opacity-60 cursor-not-allowed pointer-events-none' : '',
        ]"
    >
        <Spinner
            v-if="loading"
            :size="spinnerSizeMap[size]"
            label="Memproses aksi..."
        />
        <slot />
    </button>
</template>
