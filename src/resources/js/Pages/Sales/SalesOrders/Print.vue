<script setup>
import { computed } from 'vue';
import DocumentPrint from '@/Components/print/DocumentPrint.vue';
import PricedLines from '@/Components/print/PricedLines.vue';

const props = defineProps({
    item: { type: Object, required: true },
});

const facts = computed(() => [
    { label: 'Tanggal Pesanan', value: props.item.orderDate, date: true },
    { label: 'Gudang', value: props.item.warehouse.name },
]);
</script>

<template>
    <DocumentPrint
        title="Pesanan Penjualan"
        :number="item.number"
        :status="item.status"
        :back-url="`/sales/sales-orders/${item.id}`"
        :party="{ label: 'Pelanggan', name: item.customer.name, address: item.customer.address }"
        :facts="facts"
        :notes="item.notes ?? ''"
        :signatures="['Disetujui oleh', 'Pelanggan']"
    >
        <PricedLines :items="item.items" :totals="item" />
    </DocumentPrint>
</template>
