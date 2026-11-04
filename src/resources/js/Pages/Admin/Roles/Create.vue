<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

defineProps({
    permissionMatrix: {
        type: Array,
        required: true,
    },
    grantablePermissions: {
        type: Array,
        default: () => [],
    },
});

const breadcrumbs = [
    { label: 'Peran & Hak Akses', href: '/admin/roles' },
    { label: 'Tambah Peran' },
];
</script>

<template>
    <AppLayout title="Tambah Peran">
        <Head title="Tambah Peran" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />
            <PageHeader title="Tambah Peran" description="Buat peran kustom dan pilih izinnya." />
            <Form :permission-matrix="permissionMatrix" :grantable-permissions="grantablePermissions" />
        </div>
    </AppLayout>
</template>
