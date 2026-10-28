<script setup>
const props = defineProps({
    text: {
        type: String,
        required: true,
    },
    position: {
        type: String,
        default: 'top',
        validator: (value) => ['top', 'bottom', 'left', 'right'].includes(value),
    },
});

const positionClasses = {
    top: 'bottom-full left-1/2 -translate-x-1/2 mb-2',
    bottom: 'top-full left-1/2 -translate-x-1/2 mt-2',
    left: 'right-full top-1/2 -translate-y-1/2 mr-2',
    right: 'left-full top-1/2 -translate-y-1/2 ml-2',
};
</script>

<template>
    <div class="relative inline-flex group">
        <slot />
        <div
            role="tooltip"
            aria-hidden="true"
            :class="[
                'pointer-events-none absolute z-50 px-2.5 py-1 text-xs font-medium text-white bg-slate-900 dark:bg-slate-100 dark:text-slate-900 rounded-md shadow-sm whitespace-nowrap transition-all duration-150',
                'opacity-0 invisible group-hover:opacity-100 group-hover:visible group-focus-within:opacity-100 group-focus-within:visible',
                positionClasses[position],
            ]"
        >
            {{ text }}
        </div>
    </div>
</template>
