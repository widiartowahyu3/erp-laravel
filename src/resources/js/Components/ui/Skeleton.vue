<script setup>
const props = defineProps({
    variant: {
        type: String,
        default: 'line',
        validator: (value) => ['line', 'circle', 'block'].includes(value),
    },
    count: {
        type: Number,
        default: 1,
    },
});

const variantClasses = {
    line: 'h-4 w-full rounded-md',
    circle: 'h-10 w-10 rounded-full shrink-0',
    block: 'h-24 w-full rounded-xl',
};
</script>

<template>
    <div
        role="presentation"
        aria-hidden="true"
        class="space-y-2.5 w-full"
    >
        <div
            v-for="index in count"
            :key="index"
            :class="[
                'animate-pulse bg-slate-200 dark:bg-slate-800',
                variantClasses[variant],
            ]"
        />
    </div>
</template>
