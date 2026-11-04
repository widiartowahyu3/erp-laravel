<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

const props = defineProps({
    item: {
        type: Object,
        default: null,
    },
    order: {
        type: Object,
        default: null,
    },
    suppliers: {
        type: Array,
        default: () => [],
    },
    warehouses: {
        type: Array,
        default: () => [],
    },
    paymentTerms: {
        type: Array,
        default: () => [],
    },
    taxRates: {
        type: Array,
        default: () => [],
    },
    products: {
        type: Array,
        default: () => [],
    },
});

const order = computed(() => props.order || props.item);

const breadcrumbs = [
    { label: 'Pesanan Pembelian', href: '/purchasing/purchase-orders' },
    { label: order.value.number, href: `/purchasing/purchase-orders/${order.value.id}` },
    { label: 'Edit Draf' },
];
</script>

<template>
    <AppLayout :title="`Edit PO: ${order.number}`">
        <Head :title="`Edit PO: ${order.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader
                :title="`Edit PO: ${order.number}`"
                description="Perbarui informasi data pesanan atau baris rincian barang sebelum dokumen disetujui."
            />

            <Form
                :order="order"
                :suppliers="suppliers"
                :warehouses="warehouses"
                :payment-terms="paymentTerms"
                :tax-rates="taxRates"
                :products="products"
                :is-edit="true"
            />
        </div>
    </AppLayout>
</template>
