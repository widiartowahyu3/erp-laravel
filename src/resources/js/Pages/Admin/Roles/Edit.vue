<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Form from './Form.vue';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    selectedPermissions: {
        type: Array,
        default: () => [],
    },
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
    { label: props.item.display_name, href: `/admin/roles/${props.item.id}` },
    { label: 'Ubah' },
];
</script>

<template>
    <AppLayout :title="`Ubah Peran - ${item.display_name}`">
        <Head :title="`Ubah Peran - ${item.display_name}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />
            <PageHeader :title="`Ubah Peran ${item.display_name}`" description="Perubahan izin berlaku pada permintaan berikutnya pengguna." />
            <Form
                :role="item"
                :selected-permissions="selectedPermissions"
                :permission-matrix="permissionMatrix"
                :grantable-permissions="grantablePermissions"
            />
        </div>
    </AppLayout>
</template>
