<script setup>
import Spinner from './Spinner.vue';

const props = defineProps({
    ariaLabel: {
        type: String,
        required: true,
        validator: (value) => value.trim().length > 0,
    },
    variant: {
        type: String,
        default: 'ghost',
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
        'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 active:bg-slate-200 dark:active:bg-slate-700 focus-visible:ring-slate-400',
};

const sizeClasses = {
    sm: 'w-10 h-10 min-w-[40px] min-h-[40px] p-2 rounded-lg text-sm',
    md: 'w-10 h-10 min-w-[40px] min-h-[40px] p-2.5 rounded-lg text-base',
    lg: 'w-11 h-11 min-w-[44px] min-h-[44px] p-3 rounded-xl text-lg',
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
        :aria-label="ariaLabel"
        :title="ariaLabel"
        :class="[
            'inline-flex items-center justify-center transition-colors select-none cursor-pointer shrink-0',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900',
            variantClasses[variant],
            sizeClasses[size],
            (disabled || loading) ? 'opacity-60 cursor-not-allowed pointer-events-none' : '',
        ]"
    >
        <Spinner
            v-if="loading"
            :size="spinnerSizeMap[size]"
            :label="ariaLabel"
        />
        <slot v-else />
    </button>
</template>
