<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

defineProps({
    assignableRoles: {
        type: Array,
        default: () => [],
    },
});

const breadcrumbs = [
    { label: 'Pengguna', href: '/admin/users' },
    { label: 'Tambah Pengguna' },
];
</script>

<template>
    <AppLayout title="Tambah Pengguna">
        <Head title="Tambah Pengguna" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />
            <PageHeader title="Tambah Pengguna" description="Buat akun baru dan tentukan perannya." />
            <Form :assignable-roles="assignableRoles" />
        </div>
    </AppLayout>
</template>
