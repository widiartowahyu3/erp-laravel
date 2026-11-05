<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Printer, ArrowLeft } from 'lucide-vue-next';
import PrintLayout from '@/Layouts/PrintLayout.vue';
import Button from '@/Components/ui/Button.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import Quantity from '@/Components/ui/Quantity.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import statusMap from '@/generated/enums.json';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
});

const companyName = 'ERP Distribusi/Trading';

// A printed draft or voided invoice must not pass for a valid bill.
const stamp = computed(() => (['draft', 'void'].includes(props.item.status)
    ? statusMap[props.item.status]?.label
    : null));

function printPage() {
    window.print();
}
</script>

<template>
    <PrintLayout>
        <Head :title="`Cetak Faktur: ${item.number}`" />

        <div class="mb-6 flex items-center justify-end gap-2 print:hidden">
            <Link :href="`/sales/invoices/${item.id}`">
                <Button variant="secondary" size="sm">
                    <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                    Kembali
                </Button>
            </Link>
            <Button variant="primary" size="sm" @click="printPage">
                <Printer class="w-4 h-4 mr-1.5" aria-hidden="true" />
                Cetak
            </Button>
        </div>

        <header class="flex items-start justify-between border-b border-slate-300 pb-4">
            <div>
                <p class="text-lg font-bold">{{ companyName }}</p>
            </div>
            <div class="text-right">
                <h1 class="text-xl font-bold">Faktur Penjualan</h1>
                <p class="font-mono text-sm">{{ item.number }}</p>
                <p v-if="stamp" class="mt-1 text-sm font-bold uppercase">{{ stamp }}</p>
            </div>
        </header>

        <section class="mt-4 grid grid-cols-2 gap-6 text-sm" aria-label="Data faktur">
            <div>
                <p class="text-xs text-slate-600">Kepada:</p>
                <p class="font-semibold">{{ item.customer?.name }}</p>
                <p class="whitespace-pre-line">{{ item.customer?.address }}</p>
            </div>
            <dl class="space-y-1">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-600">Tanggal Faktur</dt>
                    <dd><DateDisplay :value="item.invoiceDate" /></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-600">Jatuh Tempo</dt>
                    <dd><DateDisplay :value="item.dueDate" /></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-600">No. Pesanan</dt>
                    <dd class="font-mono">{{ item.salesOrder?.number }}</dd>
                </div>
            </dl>
        </section>

        <table class="mt-6 w-full border-collapse text-left text-sm">
            <thead>
                <tr class="border-b border-slate-400 text-xs font-semibold">
                    <th class="py-2 pr-2">No</th>
                    <th class="py-2 pr-2">Barang</th>
                    <th class="py-2 pr-2 text-right">Kuantitas</th>
                    <th class="py-2 pr-2 text-right">Harga</th>
                    <th class="py-2 pr-2 text-right">Diskon</th>
                    <th class="py-2 pr-2 text-right">Pajak</th>
                    <th class="py-2 text-right">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <tr v-for="line in item.items" :key="line.id" class="break-inside-avoid">
                    <td class="py-2 pr-2">{{ line.lineNo }}</td>
                    <td class="py-2 pr-2">{{ line.description }}</td>
                    <td class="py-2 pr-2 text-right"><Quantity :value="line.quantity" /></td>
                    <td class="py-2 pr-2 text-right"><Rupiah :value="line.unitPrice" /></td>
                    <td class="py-2 pr-2 text-right"><Rupiah :value="line.discountAmount" /></td>
                    <td class="py-2 pr-2 text-right"><Rupiah :value="line.taxAmount" /></td>
                    <td class="py-2 text-right"><Rupiah :value="line.lineTotal" /></td>
                </tr>
            </tbody>
        </table>

        <section class="mt-4 flex justify-end break-inside-avoid" aria-label="Ringkasan faktur">
            <dl class="w-72 space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt>Subtotal</dt>
                    <dd><Rupiah :value="item.subtotal" /></dd>
                </div>
                <div class="flex justify-between">
                    <dt>Diskon</dt>
                    <dd><Rupiah :value="item.discountAmount" /></dd>
                </div>
                <div class="flex justify-between">
                    <dt>Pajak</dt>
                    <dd><Rupiah :value="item.taxAmount" /></dd>
                </div>
                <div class="flex justify-between border-t border-slate-400 pt-1 font-bold">
                    <dt>Total</dt>
                    <dd><Rupiah :value="item.total" /></dd>
                </div>
                <div class="flex justify-between">
                    <dt>Dibayar</dt>
                    <dd><Rupiah :value="item.paidAmount" /></dd>
                </div>
                <div class="flex justify-between font-semibold">
                    <dt>Sisa</dt>
                    <dd><Rupiah :value="item.outstanding" /></dd>
                </div>
            </dl>
        </section>

        <p v-if="item.notes" class="mt-6 whitespace-pre-line text-sm">Catatan: {{ item.notes }}</p>
    </PrintLayout>
</template>
