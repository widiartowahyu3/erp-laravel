<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Form from './Form.vue';
</script>

<template>
    <AppLayout title="Tambah Master Gudang">
        <Head title="Tambah Master Gudang" />

        <div class="space-y-6">
            <PageHeader
                title="Tambah Master Gudang"
                description="Daftarkan fasilitas gudang fisik atau lokasi penyimpanan baru."
            />

            <Form />
        </div>
    </AppLayout>
</template>
