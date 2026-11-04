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
    isUsed: {
        type: Boolean,
        default: false,
    },
    isMapped: {
        type: Boolean,
        default: false,
    },
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
    { label: props.item.code, href: `/accounting/accounts/${props.item.id}` },
    { label: 'Ubah' },
];
</script>

<template>
    <AppLayout :title="`Ubah Akun - ${item.code}`">
        <Head :title="`Ubah Akun - ${item.code}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />
            <PageHeader :title="`Ubah Akun ${item.code}`" description="Perbarui data akun pada bagan akun." />
            <Form
                :account="item"
                :is-edit="true"
                :is-used="isUsed"
                :is-mapped="isMapped"
                :parents="parents"
                :types="types"
                :normal-balances="normalBalances"
            />
        </div>
    </AppLayout>
</template>
