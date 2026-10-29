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
    <AppLayout :title="`Ubah Gudang - ${item.name}`">
        <Head :title="`Ubah Gudang - ${item.name}`" />

        <div class="space-y-6">
            <PageHeader
                title="Ubah Master Gudang"
                :description="`Perbarui informasi gudang ${item.name} (${item.code}).`"
            />

            <Form :warehouse="item" :is-edit="true" />
        </div>
    </AppLayout>
</template>
