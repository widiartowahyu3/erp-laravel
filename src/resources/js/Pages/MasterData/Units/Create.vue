<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Form from './Form.vue';
</script>

<template>
    <AppLayout title="Tambah Satuan Ukuran">
        <Head title="Tambah Satuan Ukuran" />

        <div class="space-y-6">
            <PageHeader
                title="Tambah Satuan Ukuran"
                description="Daftarkan satuan ukuran baru untuk produk dan inventaris."
            />

            <Form />
        </div>
    </AppLayout>
</template>
