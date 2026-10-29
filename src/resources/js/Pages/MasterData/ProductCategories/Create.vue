<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Form from './Form.vue';

defineProps({
    parentCategories: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <AppLayout title="Tambah Kategori Produk">
        <Head title="Tambah Kategori Produk" />

        <div class="space-y-6">
            <PageHeader
                title="Tambah Kategori Produk"
                description="Kelompokkan produk ke dalam struktur kategori dan sub-kategori hierarki."
            />

            <Form :parent-categories="parentCategories" />
        </div>
    </AppLayout>
</template>
