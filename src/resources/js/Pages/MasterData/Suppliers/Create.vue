<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Form from './Form.vue';

defineProps({
    paymentTerms: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <AppLayout title="Tambah Master Pemasok">
        <Head title="Tambah Master Pemasok" />

        <div class="space-y-6">
            <PageHeader
                title="Tambah Master Pemasok"
                description="Daftarkan mitra bisnis pemasok baru dan konfigurasi termin pembayaran."
            />

            <Form :payment-terms="paymentTerms" />
        </div>
    </AppLayout>
</template>
