<script setup>
defineProps({
    title: {
        type: String,
        default: '',
    },
    description: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div class="space-y-2 pb-6 border-b border-slate-200 dark:border-slate-800">
        <div v-if="$slots.breadcrumbs" class="mb-1">
            <slot name="breadcrumbs" />
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="space-y-1">
                <slot name="title">
                    <h1
                        v-if="title"
                        class="text-2xl font-bold text-slate-900 dark:text-slate-100 tracking-tight"
                    >
                        {{ title }}
                    </h1>
                </slot>
                <slot name="description">
                    <p
                        v-if="description"
                        class="text-sm text-slate-500 dark:text-slate-400"
                    >
                        {{ description }}
                    </p>
                </slot>
            </div>

            <div
                v-if="$slots.actions"
                class="flex items-center gap-2.5 shrink-0"
            >
                <slot name="actions" />
            </div>
        </div>
    </div>
</template>
