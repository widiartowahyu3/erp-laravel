<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

defineProps({
    parents: {
        type: Array,
        default: () => [],
    },
    types: {
        type: Array,
        default: () => [],
    },
    normalBalances: {
        type: Array,
        default: () => [],
    },
});

const breadcrumbs = [
    { label: 'Bagan Akun', href: '/accounting/accounts' },
    { label: 'Tambah Akun' },
];
</script>

<template>
    <AppLayout title="Tambah Akun">
        <Head title="Tambah Akun" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />
            <PageHeader title="Tambah Akun" description="Tambahkan akun baru ke bagan akun." />
            <Form :parents="parents" :types="types" :normal-balances="normalBalances" />
        </div>
    </AppLayout>
</template>
