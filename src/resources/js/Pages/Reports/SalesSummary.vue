<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DocumentSummary from '@/Components/reports/DocumentSummary.vue';

defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
    filters: { type: Object, required: true },
});
</script>

<template>
    <AppLayout title="Ringkasan Penjualan">
        <Head title="Ringkasan Penjualan" />

        <DocumentSummary
            title="Ringkasan Penjualan"
            url="/reports/sales-summary"
            :rows="rows"
            :summary="summary"
            :filters="filters"
            order-label="Pesanan Penjualan"
            fulfilment-label="Surat Jalan"
            :fulfilment-count="summary.delivery_count"
            party-label="Pelanggan"
        />
    </AppLayout>
</template>
