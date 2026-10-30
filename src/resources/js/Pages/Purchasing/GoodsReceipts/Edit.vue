<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

const props = defineProps({
    receipt: {
        type: Object,
        required: true,
    },
});

const breadcrumbs = [
    { label: 'Penerimaan Barang', href: '/purchasing/goods-receipts' },
    { label: props.receipt.number, href: `/purchasing/goods-receipts/${props.receipt.id}` },
    { label: 'Edit Draf' },
];
</script>

<template>
    <AppLayout :title="`Edit GR: ${receipt.number}`">
        <Head :title="`Edit GR: ${receipt.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader
                :title="`Edit GR: ${receipt.number}`"
                description="Perbarui informasi draf surat penerimaan barang sebelum diposting ke persediaan gudang."
            />

            <Form
                :receipt="receipt"
                :is-edit="true"
            />
        </div>
    </AppLayout>
</template>
