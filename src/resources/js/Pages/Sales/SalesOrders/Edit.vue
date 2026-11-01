<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

const props = defineProps({
    order: {
        type: Object,
        default: null,
    },
    item: {
        type: Object,
        default: null,
    },
    customers: {
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

const currentOrder = computed(() => props.order || props.item || {});

const breadcrumbs = computed(() => [
    { label: 'Pesanan Penjualan', href: '/sales/sales-orders' },
    { label: currentOrder.value.number || 'Detail', href: `/sales/sales-orders/${currentOrder.value.id}` },
    { label: 'Edit Draf' },
]);
</script>

<template>
    <AppLayout :title="`Edit SO: ${currentOrder.number}`">
        <Head :title="`Edit SO: ${currentOrder.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader
                :title="`Edit SO: ${currentOrder.number}`"
                description="Perbarui informasi data pesanan atau baris rincian barang sebelum dokumen disetujui."
            />

            <Form
                :order="currentOrder"
                :customers="customers"
                :warehouses="warehouses"
                :payment-terms="paymentTerms"
                :tax-rates="taxRates"
                :products="products"
                :is-edit="true"
            />
        </div>
    </AppLayout>
</template>
