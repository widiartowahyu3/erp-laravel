<script setup>
const props = defineProps({
    variant: {
        type: String,
        default: 'neutral',
        validator: (value) =>
            ['neutral', 'primary', 'success', 'warning', 'danger', 'info'].includes(value),
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md'].includes(value),
    },
});

const variantClasses = {
    neutral:
        'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200 border border-slate-200 dark:border-slate-700',
    primary:
        'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800',
    success:
        'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800',
    warning:
        'bg-amber-50 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800',
    danger:
        'bg-rose-50 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200 dark:border-rose-800',
    info:
        'bg-sky-50 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300 border border-sky-200 dark:border-sky-800',
};

const sizeClasses = {
    sm: 'px-2 py-0.5 text-xs font-medium rounded-md gap-1',
    md: 'px-2.5 py-0.5 text-xs font-semibold rounded-full gap-1.5',
};
</script>

<template>
    <span
        :class="[
            'inline-flex items-center justify-center select-none font-medium shrink-0',
            variantClasses[variant],
            sizeClasses[size],
        ]"
    >
        <slot />
    </span>
</template>
