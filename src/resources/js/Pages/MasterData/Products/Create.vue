<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Form from './Form.vue';

defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
    units: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <AppLayout title="Tambah Master Produk">
        <Head title="Tambah Master Produk" />

        <div class="space-y-6">
            <PageHeader
                title="Tambah Master Produk"
                description="Daftarkan barang dagang baru dengan SKU, harga jual, dan aturan pelacakan persediaan."
            />

            <Form :categories="categories" :units="units" />
        </div>
    </AppLayout>
</template>
