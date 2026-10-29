<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Form from './Form.vue';

defineProps({
    item: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <AppLayout :title="`Ubah Tarif Pajak - ${item.name}`">
        <Head :title="`Ubah Tarif Pajak - ${item.name}`" />

        <div class="space-y-6">
            <PageHeader
                title="Ubah Tarif Pajak"
                :description="`Perbarui informasi tarif pajak ${item.name} (${item.code}).`"
            />

            <Form :tax-rate="item" :is-edit="true" />
        </div>
    </AppLayout>
</template>
