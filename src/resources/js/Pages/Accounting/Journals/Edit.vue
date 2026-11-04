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
    accounts: {
        type: Array,
        default: () => [],
    },
});

const breadcrumbs = [
    { label: 'Jurnal', href: '/accounting/journals' },
    { label: props.item.number, href: `/accounting/journals/${props.item.id}` },
    { label: 'Ubah Draf' },
];
</script>

<template>
    <AppLayout :title="`Ubah Jurnal - ${item.number}`">
        <Head :title="`Ubah Jurnal - ${item.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />
            <PageHeader :title="`Ubah Draf ${item.number}`" description="Perbarui draf jurnal sebelum diposting." />
            <Form :journal="item" :accounts="accounts" :is-edit="true" />
        </div>
    </AppLayout>
</template>
