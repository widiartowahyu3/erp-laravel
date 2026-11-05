<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from 'lucide-vue-next';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({
    rows: {
        type: Array,
        default: () => [],
    },
    summary: {
        type: Object,
        required: true,
    },
    partyLabel: {
        type: String,
        required: true,
    },
    invoiceBaseUrl: {
        type: String,
        required: true,
    },
    invoicePermission: {
        type: String,
        required: true,
    },
});

const { can } = usePermission();

const buckets = [
    { key: 'not_due', label: 'Belum Jatuh Tempo' },
    { key: 'days_1_30', label: '1-30 Hari' },
    { key: 'days_31_60', label: '31-60 Hari' },
    { key: 'days_61_90', label: '61-90 Hari' },
    { key: 'days_over_90', label: '> 90 Hari' },
];

const bucketLabels = Object.fromEntries(buckets.map((bucket) => [bucket.key, bucket.label]));

const expanded = ref(new Set());

function isOpen(partyId) {
    return expanded.value.has(partyId);
}

function toggle(partyId) {
    const next = new Set(expanded.value);

    if (next.has(partyId)) {
        next.delete(partyId);
    } else {
        next.add(partyId);
    }

    expanded.value = next;
}

const detailId = (partyId) => `aging-invoices-${partyId}`;
const columnCount = buckets.length + 2;
</script>

<template>
    <EmptyState
        v-if="rows.length === 0"
        title="Tidak ada tagihan terbuka"
        description="Semua faktur sudah lunas atau belum ada faktur yang diposting."
    />

    <div v-else class="report-table overflow-x-auto print:overflow-visible">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:text-slate-400">
                    <th scope="col" class="px-3 py-2 text-left">{{ partyLabel }}</th>
                    <th v-for="bucket in buckets" :key="bucket.key" scope="col" class="whitespace-nowrap px-3 py-2 text-right">{{ bucket.label }}</th>
                    <th scope="col" class="px-3 py-2 text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="row in rows" :key="row.party_id">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <td class="px-3 py-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded text-left font-medium text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:text-slate-100 print:hidden"
                                :aria-expanded="isOpen(row.party_id)"
                                :aria-controls="isOpen(row.party_id) ? detailId(row.party_id) : undefined"
                                @click="toggle(row.party_id)"
                            >
                                <ChevronDown v-if="isOpen(row.party_id)" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                <ChevronRight v-else class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ row.party_name }}
                            </button>
                            <span class="hidden font-medium print:inline">{{ row.party_name }}</span>
                            <span class="ml-5 block font-mono text-xs text-slate-500 dark:text-slate-400 print:ml-0">{{ row.party_code }}</span>
                        </td>
                        <td v-for="bucket in buckets" :key="bucket.key" class="px-3 py-2 text-right"><Rupiah :value="row[bucket.key]" /></td>
                        <td class="px-3 py-2 text-right font-semibold"><Rupiah :value="row.total" /></td>
                    </tr>
                    <tr v-if="isOpen(row.party_id)" :id="detailId(row.party_id)" class="bg-slate-50 dark:bg-slate-800/40">
                        <td :colspan="columnCount" class="px-3 py-3">
                            <table class="w-full text-xs">
                                <caption class="sr-only">Faktur {{ row.party_name }}</caption>
                                <thead>
                                    <tr class="text-slate-500 dark:text-slate-400">
                                        <th scope="col" class="px-2 py-1 text-left">Nomor</th>
                                        <th scope="col" class="px-2 py-1 text-left">Tanggal</th>
                                        <th scope="col" class="px-2 py-1 text-left">Jatuh Tempo</th>
                                        <th scope="col" class="px-2 py-1 text-left">Kelompok</th>
                                        <th scope="col" class="px-2 py-1 text-right">Total</th>
                                        <th scope="col" class="px-2 py-1 text-right">Dibayar</th>
                                        <th scope="col" class="px-2 py-1 text-right">Sisa</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="invoice in row.invoices" :key="invoice.id">
                                        <td class="px-2 py-1 font-mono">
                                            <Link
                                                v-if="can(invoicePermission)"
                                                :href="`${invoiceBaseUrl}/${invoice.id}`"
                                                class="text-indigo-700 hover:underline dark:text-indigo-400"
                                            >
                                                {{ invoice.number }}
                                            </Link>
                                            <template v-else>{{ invoice.number }}</template>
                                        </td>
                                        <td class="px-2 py-1"><DateDisplay :value="invoice.invoice_date" /></td>
                                        <td class="px-2 py-1"><DateDisplay :value="invoice.due_date" /></td>
                                        <td class="px-2 py-1">
                                            {{ bucketLabels[invoice.bucket] }}
                                            <template v-if="invoice.days_overdue > 0">(lewat {{ invoice.days_overdue }} hari)</template>
                                        </td>
                                        <td class="px-2 py-1 text-right"><Rupiah :value="invoice.total" /></td>
                                        <td class="px-2 py-1 text-right"><Rupiah :value="invoice.paid_amount" /></td>
                                        <td class="px-2 py-1 text-right font-semibold"><Rupiah :value="invoice.outstanding" /></td>
                                    </tr>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </template>
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-slate-300 font-semibold dark:border-slate-600">
                    <td class="px-3 py-2">Total</td>
                    <td v-for="bucket in buckets" :key="bucket.key" class="px-3 py-2 text-right"><Rupiah :value="summary[bucket.key]" /></td>
                    <td class="px-3 py-2 text-right"><Rupiah :value="summary.total" /></td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
