<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Form from './Form.vue';

defineProps({
    item: {
        type: Object,
        required: true,
    },
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
    <AppLayout :title="`Ubah Produk - ${item.name}`">
        <Head :title="`Ubah Produk - ${item.name}`" />

        <div class="space-y-6">
            <PageHeader
                title="Ubah Master Produk"
                :description="`Perbarui informasi produk dagang ${item.name} (${item.sku}).`"
            />

            <Form :product="item" :categories="categories" :units="units" :is-edit="true" />
        </div>
    </AppLayout>
</template>
