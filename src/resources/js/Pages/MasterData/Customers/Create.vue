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
    <AppLayout title="Tambah Master Pelanggan">
        <Head title="Tambah Master Pelanggan" />

        <div class="space-y-6">
            <PageHeader
                title="Tambah Master Pelanggan"
                description="Daftarkan mitra pembeli baru dan kelola plafon batas kredit belanja."
            />

            <Form :payment-terms="paymentTerms" />
        </div>
    </AppLayout>
</template>
