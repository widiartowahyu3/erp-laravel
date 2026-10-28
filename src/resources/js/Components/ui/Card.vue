<script setup>
const props = defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: (value) => ['default', 'bordered', 'elevated'].includes(value),
    },
    as: {
        type: String,
        default: 'div',
    },
});

const variantClasses = {
    default:
        'bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-xs',
    bordered:
        'bg-white dark:bg-slate-900 border-2 border-slate-300 dark:border-slate-700 rounded-xl',
    elevated:
        'bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-md dark:shadow-slate-950/50',
};
</script>

<template>
    <component
        :is="as"
        :class="['overflow-hidden transition-colors', variantClasses[variant]]"
    >
        <div
            v-if="$slots.header"
            class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30"
        >
            <slot name="header" />
        </div>
        <div class="p-5">
            <slot />
        </div>
        <div
            v-if="$slots.footer"
            class="px-5 py-3 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30"
        >
            <slot name="footer" />
        </div>
    </component>
</template>
