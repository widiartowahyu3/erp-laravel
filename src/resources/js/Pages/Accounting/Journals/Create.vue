<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

defineProps({
    accounts: {
        type: Array,
        default: () => [],
    },
});

const breadcrumbs = [
    { label: 'Jurnal', href: '/accounting/journals' },
    { label: 'Buat Jurnal Manual' },
];
</script>

<template>
    <AppLayout title="Buat Jurnal Manual">
        <Head title="Buat Jurnal Manual" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />
            <PageHeader title="Buat Jurnal Manual" description="Catat jurnal umum dengan debit dan kredit yang seimbang." />
            <Form :accounts="accounts" />
        </div>
    </AppLayout>
</template>
