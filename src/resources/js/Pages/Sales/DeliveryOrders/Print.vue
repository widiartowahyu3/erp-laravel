<script setup>
import { computed } from 'vue';
import DocumentPrint from '@/Components/print/DocumentPrint.vue';
import QuantityLines from '@/Components/print/QuantityLines.vue';

const props = defineProps({
    item: { type: Object, required: true },
});

const facts = computed(() => [
    { label: 'Tanggal Kirim', value: props.item.deliveryDate, date: true },
    { label: 'No. Pesanan', value: props.item.salesOrder.number },
    { label: 'Gudang', value: props.item.warehouse.name },
]);
</script>

<template>
    <DocumentPrint
        title="Surat Jalan"
        :number="item.number"
        :status="item.status"
        :back-url="`/sales/delivery-orders/${item.id}`"
        :party="{ label: 'Kirim Kepada', name: item.customer.name, address: item.shippingAddress }"
        :facts="facts"
        :notes="item.notes ?? ''"
        :signatures="['Gudang', 'Pengemudi', 'Penerima']"
    >
        <QuantityLines :items="item.items" />
    </DocumentPrint>
</template>
