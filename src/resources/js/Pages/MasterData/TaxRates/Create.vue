<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Form from './Form.vue';
</script>

<template>
    <AppLayout title="Tambah Tarif Pajak">
        <Head title="Tambah Tarif Pajak" />

        <div class="space-y-6">
            <PageHeader
                title="Tambah Tarif Pajak"
                description="Daftarkan skema tarif pajak transaksi baru untuk kepatuhan perpajakan."
            />

            <Form />
        </div>
    </AppLayout>
</template>
