<script setup>
import { computed } from 'vue';
import DocumentPrint from '@/Components/print/DocumentPrint.vue';
import PricedLines from '@/Components/print/PricedLines.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';

const props = defineProps({
    item: { type: Object, required: true },
});

const facts = computed(() => [
    { label: 'No. Faktur Pemasok', value: props.item.supplierInvoiceNumber },
    { label: 'Tanggal Faktur', value: props.item.invoiceDate, date: true },
    { label: 'Jatuh Tempo', value: props.item.dueDate, date: true },
    { label: 'No. Pesanan', value: props.item.purchaseOrder?.number },
]);
</script>

<template>
    <DocumentPrint
        title="Faktur Pembelian"
        :number="item.number"
        :status="item.status"
        :back-url="`/purchasing/invoices/${item.id}`"
        :party="{ label: 'Pemasok', name: item.supplier.name, address: item.supplier.address }"
        :facts="facts"
        :notes="item.notes ?? ''"
    >
        <PricedLines :items="item.items" :totals="item" />

        <section class="mt-2 flex justify-end break-inside-avoid" aria-label="Status pembayaran">
            <dl class="w-72 space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt>Dibayar</dt>
                    <dd><Rupiah :value="item.paidAmount" /></dd>
                </div>
                <div class="flex justify-between font-semibold">
                    <dt>Sisa Tagihan</dt>
                    <dd data-test="outstanding"><Rupiah :value="item.outstanding" /></dd>
                </div>
            </dl>
        </section>
    </DocumentPrint>
</template>
