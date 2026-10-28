<script setup>
import { computed } from 'vue';
import Badge from './Badge.vue';

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

const statusMap = {
    // Draft & Inactive
    draft: { variant: 'neutral', label: 'Draft' },
    inactive: { variant: 'neutral', label: 'Nonaktif' },
    cancelled: { variant: 'neutral', label: 'Dibatalkan' },
    closed: { variant: 'neutral', label: 'Ditutup' },

    // Pending & Partial
    pending: { variant: 'warning', label: 'Menunggu' },
    partially_delivered: { variant: 'warning', label: 'Terkirim Sebagian' },
    partially_received: { variant: 'warning', label: 'Diterima Sebagian' },
    partially_paid: { variant: 'warning', label: 'Dibayar Sebagian' },

    // In Progress / Info
    submitted: { variant: 'info', label: 'Diajukan' },
    confirmed: { variant: 'info', label: 'Dikonfirmasi' },
    approved: { variant: 'info', label: 'Disetujui' },
    processing: { variant: 'info', label: 'Diproses' },
    shipped: { variant: 'info', label: 'Dikirim' },
    open: { variant: 'info', label: 'Terbuka' },

    // Success / Completed
    posted: { variant: 'success', label: 'Diposting' },
    delivered: { variant: 'success', label: 'Terkirim' },
    received: { variant: 'success', label: 'Diterima' },
    paid: { variant: 'success', label: 'Lunas' },
    completed: { variant: 'success', label: 'Selesai' },
    active: { variant: 'success', label: 'Aktif' },

    // Danger / Void / Rejected
    rejected: { variant: 'danger', label: 'Ditolak' },
    void: { variant: 'danger', label: 'Void' },
    voided: { variant: 'danger', label: 'Void' },
};

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
