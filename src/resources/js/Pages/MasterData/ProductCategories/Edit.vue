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
    parentCategories: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <AppLayout :title="`Ubah Kategori - ${item.name}`">
        <Head :title="`Ubah Kategori - ${item.name}`" />

        <div class="space-y-6">
            <PageHeader
                title="Ubah Kategori Produk"
                :description="`Perbarui informasi kategori ${item.name} (${item.code}).`"
            />

            <Form :category="item" :parent-categories="parentCategories" :is-edit="true" />
        </div>
    </AppLayout>
</template>
