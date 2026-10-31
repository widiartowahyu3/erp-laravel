<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

defineProps({
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

const breadcrumbs = [
    { label: 'Pesanan Pembelian', href: '/purchasing/purchase-orders' },
    { label: 'Buat PO Baru' },
];
</script>

<template>
    <AppLayout title="Buat Pesanan Pembelian">
        <Head title="Buat Pesanan Pembelian" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader
                title="Buat Pesanan Pembelian"
                description="Formulir pengajuan pesanan pembelian baru kepada pemasok."
            />

            <Form
                :suppliers="suppliers"
                :warehouses="warehouses"
                :payment-terms="paymentTerms"
                :tax-rates="taxRates"
                :products="products"
            />
        </div>
    </AppLayout>
</template>
