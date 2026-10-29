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
    paymentTerms: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <AppLayout :title="`Ubah Pemasok - ${item.name}`">
        <Head :title="`Ubah Pemasok - ${item.name}`" />

        <div class="space-y-6">
            <PageHeader
                title="Ubah Master Pemasok"
                :description="`Perbarui informasi mitra pemasok ${item.name} (${item.code}).`"
            />

            <Form :supplier="item" :payment-terms="paymentTerms" :is-edit="true" />
        </div>
    </AppLayout>
</template>
