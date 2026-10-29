<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Form from './Form.vue';
</script>

<template>
    <AppLayout title="Tambah Syarat Pembayaran">
        <Head title="Tambah Syarat Pembayaran" />

        <div class="space-y-6">
            <PageHeader
                title="Tambah Syarat Pembayaran"
                description="Daftarkan termin jatuh tempo pembayaran baru untuk transaksi pemasok dan pelanggan."
            />

            <Form />
        </div>
    </AppLayout>
</template>
