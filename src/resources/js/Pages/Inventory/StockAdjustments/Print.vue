<script setup>
import { computed } from 'vue';
import DocumentPrint from '@/Components/print/DocumentPrint.vue';
import Quantity from '@/Components/ui/Quantity.vue';

const props = defineProps({
    item: { type: Object, required: true },
});

const facts = computed(() => [
    { label: 'Tanggal', value: props.item.adjustmentDate, date: true },
    { label: 'Gudang', value: props.item.warehouse.name },
    { label: 'Alasan', value: props.item.reason },
]);
</script>

<template>
    <DocumentPrint
        title="Penyesuaian Stok"
        :number="item.number"
        :status="item.status"
        :back-url="`/inventory/stock-adjustments/${item.id}`"
        :facts="facts"
        :notes="item.notes ?? ''"
        :signatures="['Dihitung oleh', 'Disetujui oleh']"
    >
        <table class="mt-6 w-full border-collapse text-left text-sm">
            <thead>
                <tr class="border-b border-slate-400 text-xs font-semibold">
                    <th scope="col" class="py-2 pr-2">No</th>
                    <th scope="col" class="py-2 pr-2">SKU</th>
                    <th scope="col" class="py-2 pr-2">Barang</th>
                    <th scope="col" class="py-2 pr-2 text-right">Stok Sistem</th>
                    <th scope="col" class="py-2 pr-2 text-right">Hasil Hitung</th>
                    <th scope="col" class="py-2 text-right">Selisih</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <tr v-for="line in item.items" :key="line.lineNo" class="break-inside-avoid">
                    <td class="py-2 pr-2">{{ line.lineNo }}</td>
                    <td class="py-2 pr-2 font-mono text-xs">{{ line.sku }}</td>
                    <td class="py-2 pr-2">{{ line.name }}</td>
                    <td class="py-2 pr-2 text-right"><Quantity :value="line.systemQuantity" :unit="line.unit ?? ''" /></td>
                    <td class="py-2 pr-2 text-right"><Quantity :value="line.countedQuantity" :unit="line.unit ?? ''" /></td>
                    <td class="py-2 text-right"><Quantity :value="line.differenceQuantity" :unit="line.unit ?? ''" /></td>
                </tr>
            </tbody>
        </table>
    </DocumentPrint>
</template>
