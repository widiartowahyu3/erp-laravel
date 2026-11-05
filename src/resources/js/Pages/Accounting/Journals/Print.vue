<script setup>
import { computed } from 'vue';
import DocumentPrint from '@/Components/print/DocumentPrint.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';

const props = defineProps({
    item: { type: Object, required: true },
});

const facts = computed(() => {
    const list = [
        { label: 'Tanggal', value: props.item.journalDate, date: true },
        { label: 'Sumber', value: props.item.sourceLabel ?? 'Manual' },
    ];

    if (props.item.reversalOfNumber) {
        list.push({ label: 'Membalik Jurnal', value: props.item.reversalOfNumber });
    }

    return list;
});
</script>

<template>
    <DocumentPrint
        title="Jurnal"
        :number="item.number"
        :status="item.status"
        :back-url="`/accounting/journals/${item.id}`"
        :party="{ label: 'Keterangan', name: item.description, address: '' }"
        :facts="facts"
        :signatures="['Dibuat oleh', 'Diperiksa oleh']"
    >
        <table class="mt-6 w-full border-collapse text-left text-sm">
            <thead>
                <tr class="border-b border-slate-400 text-xs font-semibold">
                    <th scope="col" class="py-2 pr-2">Kode</th>
                    <th scope="col" class="py-2 pr-2">Akun</th>
                    <th scope="col" class="py-2 pr-2">Keterangan</th>
                    <th scope="col" class="py-2 pr-2 text-right">Debit</th>
                    <th scope="col" class="py-2 text-right">Kredit</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <tr v-for="line in item.lines" :key="line.lineNo" class="break-inside-avoid">
                    <td class="py-2 pr-2 font-mono text-xs">{{ line.code }}</td>
                    <td class="py-2 pr-2">{{ line.name }}</td>
                    <td class="py-2 pr-2">{{ line.description ?? '-' }}</td>
                    <td class="py-2 pr-2 text-right"><Rupiah :value="line.debit" /></td>
                    <td class="py-2 text-right"><Rupiah :value="line.credit" /></td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="border-t border-slate-400 font-bold">
                    <td colspan="3" class="py-2 pr-2">Total</td>
                    <td class="py-2 pr-2 text-right"><Rupiah :value="item.totalDebit" /></td>
                    <td class="py-2 text-right"><Rupiah :value="item.totalCredit" /></td>
                </tr>
            </tfoot>
        </table>
    </DocumentPrint>
</template>
