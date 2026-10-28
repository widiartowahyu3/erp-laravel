<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: {
        type: [String, Date],
        default: null,
    },
    format: {
        type: String,
        default: 'medium',
        validator: (value) => ['short', 'medium', 'long', 'datetime'].includes(value),
    },
});

const formatted = computed(() => {
    if (!props.value) return '-';
    const date = props.value instanceof Date ? props.value : new Date(props.value);
    if (isNaN(date.getTime())) return '-';

    if (props.format === 'short') {
        return new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }).format(date);
    }

    if (props.format === 'datetime') {
        const datePart = new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }).format(date);

        const timePart = new Intl.DateTimeFormat('id-ID', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).format(date);

        return `${datePart}, ${timePart} WIB`;
    }

    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(date);
});
</script>

<template>
    <time :datetime="typeof value === 'string' ? value : undefined" class="whitespace-nowrap">
        {{ formatted }}
    </time>
</template>
