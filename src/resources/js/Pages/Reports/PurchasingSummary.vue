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
    <AppLayout title="Ringkasan Pembelian">
        <Head title="Ringkasan Pembelian" />

        <DocumentSummary
            title="Ringkasan Pembelian"
            url="/reports/purchasing-summary"
            :rows="rows"
            :summary="summary"
            :filters="filters"
            order-label="Pesanan Pembelian"
            fulfilment-label="Penerimaan Barang"
            :fulfilment-count="summary.receipt_count"
            party-label="Pemasok"
        />
    </AppLayout>
</template>
