<script setup>
import { computed } from 'vue';
import DocumentPrint from '@/Components/print/DocumentPrint.vue';
import PricedLines from '@/Components/print/PricedLines.vue';

const props = defineProps({
    item: { type: Object, required: true },
});

const facts = computed(() => [
    { label: 'Tanggal Pesanan', value: props.item.orderDate, date: true },
    { label: 'Perkiraan Tiba', value: props.item.expectedDate, date: true },
    { label: 'Gudang', value: props.item.warehouse.name },
]);
</script>

<template>
    <DocumentPrint
        title="Pesanan Pembelian"
        :number="item.number"
        :status="item.status"
        :back-url="`/purchasing/purchase-orders/${item.id}`"
        :party="{ label: 'Kepada', name: item.supplier.name, address: item.supplier.address }"
        :facts="facts"
        :notes="item.notes ?? ''"
        :signatures="['Disetujui oleh']"
    >
        <PricedLines :items="item.items" :totals="item" />
    </DocumentPrint>
</template>
