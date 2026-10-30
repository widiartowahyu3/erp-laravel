<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

defineProps({
    warehouses: {
        type: Array,
        default: () => [],
    },
    products: {
        type: Array,
        default: () => [],
    },
});

const breadcrumbs = [
    { label: 'Penyesuaian Stok', href: '/inventory/stock-adjustments' },
    { label: 'Buat Baru' },
];
</script>

<template>
    <AppLayout title="Buat Penyesuaian Stok">
        <Head title="Buat Penyesuaian Stok" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader
                title="Buat Penyesuaian Stok Baru"
                description="Catat dokumen draf penyesuaian stok opname dan selisih kuantitas fisik gudang."
            />

            <Form
                :warehouses="warehouses"
                :products="products"
                :is-edit="false"
            />
        </div>
    </AppLayout>
</template>
