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

function parseDateValue(val) {
    if (typeof val === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(val.trim())) {
        const [year, month, day] = val.trim().split('-').map(Number);
        return new Date(Date.UTC(year, month - 1, day, 12, 0, 0));
    }
    return val instanceof Date ? val : new Date(val);
}

const formatted = computed(() => {
    if (!props.value) return '-';
    const date = parseDateValue(props.value);
    if (isNaN(date.getTime())) return '-';

    if (props.format === 'short') {
        return new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            timeZone: 'Asia/Jakarta',
        }).format(date);
    }

    if (props.format === 'datetime') {
        const datePart = new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            timeZone: 'Asia/Jakarta',
        }).format(date);

        const timePart = new Intl.DateTimeFormat('id-ID', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
            timeZone: 'Asia/Jakarta',
        }).format(date).replace('.', ':');

        return `${datePart}, ${timePart} WIB`;
    }

    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'Asia/Jakarta',
    }).format(date);
});
</script>

<template>
    <time :datetime="props.value ? String(props.value) : undefined">
        {{ formatted }}
    </time>
</template>
