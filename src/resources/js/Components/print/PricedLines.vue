<script setup>
import Quantity from '@/Components/ui/Quantity.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';

defineProps({
    // Line items with lineNo, name, unit, quantity, unitPrice, discountAmount, taxAmount and lineTotal.
    items: {
        type: Array,
        required: true,
    },
    // subtotal, discountAmount, taxAmount and total of the document.
    totals: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <table class="mt-6 w-full border-collapse text-left text-sm">
        <thead>
            <tr class="border-b border-slate-400 text-xs font-semibold">
                <th scope="col" class="py-2 pr-2">No</th>
                <th scope="col" class="py-2 pr-2">Barang</th>
                <th scope="col" class="py-2 pr-2 text-right">Kuantitas</th>
                <th scope="col" class="py-2 pr-2 text-right">Harga</th>
                <th scope="col" class="py-2 pr-2 text-right">Diskon</th>
                <th scope="col" class="py-2 pr-2 text-right">Pajak</th>
                <th scope="col" class="py-2 text-right">Total</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
            <tr v-for="line in items" :key="line.lineNo" class="break-inside-avoid">
                <td class="py-2 pr-2">{{ line.lineNo }}</td>
                <td class="py-2 pr-2">{{ line.name ?? line.description }}</td>
                <td class="py-2 pr-2 text-right"><Quantity :value="line.quantity" :unit="line.unit ?? ''" /></td>
                <td class="py-2 pr-2 text-right"><Rupiah :value="line.unitPrice" /></td>
                <td class="py-2 pr-2 text-right"><Rupiah :value="line.discountAmount" /></td>
                <td class="py-2 pr-2 text-right"><Rupiah :value="line.taxAmount" /></td>
                <td class="py-2 text-right"><Rupiah :value="line.lineTotal" /></td>
            </tr>
        </tbody>
    </table>

    <section class="mt-4 flex justify-end break-inside-avoid" aria-label="Ringkasan nilai">
        <dl class="w-72 space-y-1 text-sm">
            <div class="flex justify-between">
                <dt>Subtotal</dt>
                <dd><Rupiah :value="totals.subtotal" /></dd>
            </div>
            <div class="flex justify-between">
                <dt>Diskon</dt>
                <dd><Rupiah :value="totals.discountAmount" /></dd>
            </div>
            <div class="flex justify-between">
                <dt>Pajak</dt>
                <dd><Rupiah :value="totals.taxAmount" /></dd>
            </div>
            <div class="flex justify-between border-t border-slate-400 pt-1 font-bold">
                <dt>Total</dt>
                <dd data-test="total"><Rupiah :value="totals.total" /></dd>
            </div>
        </dl>
    </section>
</template>
