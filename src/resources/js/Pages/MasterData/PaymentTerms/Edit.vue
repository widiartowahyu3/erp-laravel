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
    <AppLayout :title="`Ubah Syarat Pembayaran - ${item.name}`">
        <Head :title="`Ubah Syarat Pembayaran - ${item.name}`" />

        <div class="space-y-6">
            <PageHeader
                title="Ubah Syarat Pembayaran"
                :description="`Perbarui informasi termin ${item.name} (${item.code}).`"
            />

            <Form :payment-term="item" :is-edit="true" />
        </div>
    </AppLayout>
</template>
