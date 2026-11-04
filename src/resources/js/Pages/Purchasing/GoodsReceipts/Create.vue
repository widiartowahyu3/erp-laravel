<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

const props = defineProps({
    purchaseOrder: {
        type: Object,
        default: null,
    },
    eligibleItems: {
        type: Array,
        default: () => [],
    },
});

// The server opens this page for one purchase order, with only the lines still to be received
const purchaseOrders = computed(() => (
    props.purchaseOrder ? [{ ...props.purchaseOrder, items: props.eligibleItems }] : []
));
const selectedPoId = computed(() => props.purchaseOrder?.id ?? '');

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
