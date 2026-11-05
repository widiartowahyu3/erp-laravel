<script setup>
import { computed } from 'vue';
import DocumentPrint from '@/Components/print/DocumentPrint.vue';
import QuantityLines from '@/Components/print/QuantityLines.vue';

const props = defineProps({
    item: { type: Object, required: true },
});

const facts = computed(() => [
    { label: 'Tanggal Terima', value: props.item.receiptDate, date: true },
    { label: 'No. Pesanan', value: props.item.purchaseOrder.number },
    { label: 'Gudang', value: props.item.warehouse.name },
]);
</script>

<template>
    <DocumentPrint
        title="Penerimaan Barang"
        :number="item.number"
        :status="item.status"
        :back-url="`/purchasing/goods-receipts/${item.id}`"
        :party="{ label: 'Pemasok', name: item.supplier.name, address: item.supplier.address }"
        :facts="facts"
        :notes="item.notes ?? ''"
        :signatures="['Pengirim', 'Penerima']"
    >
        <QuantityLines :items="item.items" />
    </DocumentPrint>
</template>
