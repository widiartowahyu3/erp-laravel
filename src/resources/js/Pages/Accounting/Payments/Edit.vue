<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import PaymentForm from './Form.vue';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    paymentType: {
        type: String,
        default: 'receipt',
    },
    customers: {
        type: Array,
        default: () => [],
    },
    suppliers: {
        type: Array,
        default: () => [],
    },
    selectedSupplierId: {
        type: Number,
        default: null,
    },
    outstandingPurchaseInvoices: {
        type: Array,
        default: () => [],
    },
    cashBankAccounts: {
        type: Array,
        default: () => [],
    },
    methods: {
        type: Array,
        default: () => [],
    },
    selectedCustomerId: {
        type: Number,
        default: null,
    },
    outstandingInvoices: {
        type: Array,
        default: () => [],
    },
});

const breadcrumbs = computed(() => [
    { label: 'Pembayaran Kas/Bank', href: '/accounting/payments' },
    { label: props.item.number, href: `/accounting/payments/${props.item.id}` },
    { label: 'Ubah' },
]);
</script>

<template>
    <AppLayout :title="`Ubah Pembayaran: ${item.number}`">
        <Head :title="`Ubah Pembayaran: ${item.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader
                :title="`Ubah Pembayaran: ${item.number}`"
                description="Hanya draf yang dapat diubah. Alokasi diganti seluruhnya saat disimpan."
            />

            <PaymentForm
                :payment-type="paymentType"
                :customers="customers"
                :suppliers="suppliers"
                :selected-supplier-id="selectedSupplierId"
                :outstanding-purchase-invoices="outstandingPurchaseInvoices"
                :cash-bank-accounts="cashBankAccounts"
                :methods="methods"
                :selected-customer-id="selectedCustomerId"
                :outstanding-invoices="outstandingInvoices"
                :initial="item"
                :payment-id="item.id"
                :saved-allocations="item.allocations"
            />
        </div>
    </AppLayout>
</template>
