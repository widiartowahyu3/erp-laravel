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
    <AppLayout :title="`Ubah Satuan - ${item.name}`">
        <Head :title="`Ubah Satuan - ${item.name}`" />

        <div class="space-y-6">
            <PageHeader
                title="Ubah Satuan Ukuran"
                :description="`Perbarui informasi satuan ukuran ${item.name} (${item.code}).`"
            />

            <Form :unit="item" :is-edit="true" />
        </div>
    </AppLayout>
</template>
