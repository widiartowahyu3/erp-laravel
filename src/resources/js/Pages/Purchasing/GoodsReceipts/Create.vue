<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

defineProps({
    purchaseOrders: {
        type: Array,
        default: () => [],
    },
    selectedPoId: {
        type: [String, Number],
        default: '',
    },
});

const breadcrumbs = [
    { label: 'Penerimaan Barang', href: '/purchasing/goods-receipts' },
    { label: 'Terima Barang Baru' },
];
</script>

<template>
    <AppLayout title="Penerimaan Barang (GR)">
        <Head title="Penerimaan Barang (GR)" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader
                title="Penerimaan Barang (GR)"
                description="Pencatatan barang masuk dari pesanan pembelian yang tiba di gudang."
            />

            <Form
                :purchase-orders="purchaseOrders"
                :selected-po-id="selectedPoId"
            />
        </div>
    </AppLayout>
</template>
