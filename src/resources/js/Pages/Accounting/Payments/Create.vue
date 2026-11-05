<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import PaymentForm from './Form.vue';

const props = defineProps({
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
    defaults: {
        type: Object,
        default: () => ({}),
    },
});

const isDisbursement = props.paymentType === 'disbursement';
const pageTitle = isDisbursement ? 'Pembayaran ke Pemasok' : 'Terima Pembayaran';
const pageDescription = isDisbursement
    ? 'Catat uang keluar ke pemasok dan alokasikan ke fakturnya.'
    : 'Catat uang masuk dari pelanggan dan alokasikan ke fakturnya.';

const breadcrumbs = [
    { label: 'Pembayaran Kas/Bank', href: '/accounting/payments' },
    { label: pageTitle },
];
</script>

<template>
    <AppLayout :title="pageTitle">
        <Head :title="pageTitle" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader
                :title="pageTitle"
                :description="pageDescription"
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
                :initial="{ paymentDate: defaults.paymentDate }"
            />
        </div>
    </AppLayout>
</template>
