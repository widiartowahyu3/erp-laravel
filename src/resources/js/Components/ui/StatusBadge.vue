<script setup>
import { computed } from 'vue';
import Badge from './Badge.vue';
import statusMap from '@/generated/enums.json';

const props = defineProps({
    status: {
        type: String,
        required: true,
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md'].includes(value),
    },
});

const resolved = computed(() => {
    const key = (props.status || '').toLowerCase().trim();
    if (statusMap[key]) {
        return statusMap[key];
    }
    // Fallback format
    const fallbackLabel = key
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase()) || props.status;
    return {
        variant: 'neutral',
        label: fallbackLabel,
    };
});
</script>

<template>
    <Badge :variant="resolved.variant" :size="size">
        {{ resolved.label }}
    </Badge>
</template>
