<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import PaymentForm from './Form.vue';

defineProps({
    customers: {
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

const breadcrumbs = [
    { label: 'Pembayaran Kas/Bank', href: '/accounting/payments' },
    { label: 'Terima Pembayaran' },
];
</script>

<template>
    <AppLayout title="Terima Pembayaran">
        <Head title="Terima Pembayaran" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader
                title="Terima Pembayaran"
                description="Catat uang masuk dari pelanggan dan alokasikan ke fakturnya."
            />

            <PaymentForm
                :customers="customers"
                :cash-bank-accounts="cashBankAccounts"
                :methods="methods"
                :selected-customer-id="selectedCustomerId"
                :outstanding-invoices="outstandingInvoices"
                :initial="{ paymentDate: defaults.paymentDate }"
            />
        </div>
    </AppLayout>
</template>
