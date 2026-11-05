<script setup>
import Quantity from '@/Components/ui/Quantity.vue';

defineProps({
    // Line items with lineNo, sku, name, unit and quantity. Warehouse papers carry no money.
    items: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <table class="mt-6 w-full border-collapse text-left text-sm">
        <thead>
            <tr class="border-b border-slate-400 text-xs font-semibold">
                <th scope="col" class="py-2 pr-2">No</th>
                <th scope="col" class="py-2 pr-2">SKU</th>
                <th scope="col" class="py-2 pr-2">Barang</th>
                <th scope="col" class="py-2 text-right">Kuantitas</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
            <tr v-for="line in items" :key="line.lineNo" class="break-inside-avoid">
                <td class="py-2 pr-2">{{ line.lineNo }}</td>
                <td class="py-2 pr-2 font-mono text-xs">{{ line.sku }}</td>
                <td class="py-2 pr-2">{{ line.name }}</td>
                <td class="py-2 text-right"><Quantity :value="line.quantity" :unit="line.unit ?? ''" /></td>
            </tr>
        </tbody>
    </table>
</template>
