<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import InvoiceForm from './Form.vue';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    salesOrder: {
        type: Object,
        required: true,
    },
    eligibleItems: {
        type: Array,
        default: () => [],
    },
});

const breadcrumbs = computed(() => [
    { label: 'Faktur Penjualan (SI)', href: '/sales/invoices' },
    { label: props.item.number, href: `/sales/invoices/${props.item.id}` },
    { label: 'Ubah' },
]);
</script>

<template>
    <AppLayout :title="`Ubah Faktur: ${item.number}`">
        <Head :title="`Ubah Faktur: ${item.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader
                :title="`Ubah Faktur: ${item.number}`"
                description="Hanya draf yang dapat diubah. Nilai dihitung ulang saat disimpan."
            />

            <InvoiceForm
                :sales-order="salesOrder"
                :eligible-items="eligibleItems"
                :initial="{ invoiceDate: item.invoiceDate, dueDate: item.dueDate, notes: item.notes }"
                :invoice-id="item.id"
            />
        </div>
    </AppLayout>
</template>
