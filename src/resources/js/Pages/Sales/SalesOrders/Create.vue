<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

defineProps({
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

const breadcrumbs = [
    { label: 'Pesanan Penjualan', href: '/sales/sales-orders' },
    { label: 'Buat SO Baru' },
];
</script>

<template>
    <AppLayout title="Buat Pesanan Penjualan">
        <Head title="Buat Pesanan Penjualan" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader
                title="Buat Pesanan Penjualan"
                description="Formulir pembuatan pesanan penjualan (SO) baru kepada pelanggan."
            />

            <Form
                :customers="customers"
                :warehouses="warehouses"
                :payment-terms="paymentTerms"
                :tax-rates="taxRates"
                :products="products"
            />
        </div>
    </AppLayout>
</template>
