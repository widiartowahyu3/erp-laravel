<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
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
    { label: props.item.number, href: `/inventory/stock-adjustments/${props.item.id}` },
    { label: 'Ubah' },
];
</script>

<template>
    <AppLayout :title="`Ubah Penyesuaian Stok: ${item.number}`">
        <Head :title="`Ubah Penyesuaian Stok: ${item.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader
                :title="`Ubah Penyesuaian Stok: ${item.number}`"
                description="Perbarui rincian barang dan hasil hitung fisik pada draf penyesuaian stok."
            />

            <Form
                :adjustment="item"
                :warehouses="warehouses"
                :products="products"
                :is-edit="true"
            />
        </div>
    </AppLayout>
</template>
