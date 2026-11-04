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
    assignableRoles: {
        type: Array,
        default: () => [],
    },
    isSelf: {
        type: Boolean,
        default: false,
    },
    isLastSuperAdmin: {
        type: Boolean,
        default: false,
    },
    canManage: {
        type: Boolean,
        default: true,
    },
});

const breadcrumbs = [
    { label: 'Pengguna', href: '/admin/users' },
    { label: props.item.name, href: `/admin/users/${props.item.id}` },
    { label: 'Ubah' },
];
</script>

<template>
    <AppLayout :title="`Ubah Pengguna - ${item.name}`">
        <Head :title="`Ubah Pengguna - ${item.name}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />
            <PageHeader :title="`Ubah Pengguna ${item.name}`" description="Perbarui data akun dan perannya." />
            <Form
                :user="item"
                :assignable-roles="assignableRoles"
                :is-self="isSelf"
                :is-last-super-admin="isLastSuperAdmin"
            />
        </div>
    </AppLayout>
</template>
