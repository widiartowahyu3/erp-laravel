<script setup>
import { Printer } from 'lucide-vue-next';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import ExportButton from './ExportButton.vue';

defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    exportUrl: {
        type: String,
        default: '',
    },
    exportQuery: {
        type: Object,
        default: () => ({}),
    },
});

function printPage() {
    window.print();
}
</script>

<template>
    <PageHeader :title="title" :description="description">
        <template #actions>
            <div class="flex items-center gap-2 print:hidden">
                <ExportButton v-if="exportUrl" :url="exportUrl" :query="exportQuery" />
                <Button type="button" variant="secondary" @click="printPage">
                    <Printer class="mr-1.5 h-4 w-4" aria-hidden="true" />
                    Cetak
                </Button>
            </div>
        </template>
    </PageHeader>
</template>
