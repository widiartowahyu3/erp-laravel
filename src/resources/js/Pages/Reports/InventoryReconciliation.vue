<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2 } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/ui/Card.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import ReportHeader from '@/Components/reports/ReportHeader.vue';
import ReportTable from '@/Components/reports/ReportTable.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
});

const matched = computed(() => props.summary.status === 'matched');

const columns = [
    { key: 'warehouse_code', label: 'Kode' },
    { key: 'warehouse_name', label: 'Gudang' },
    { key: 'stock_value', label: 'Nilai Persediaan', type: 'money' },
];
</script>

<template>
    <AppLayout title="Rekonsiliasi Persediaan">
        <Head title="Rekonsiliasi Persediaan" />

        <div class="report-page space-y-6">
            <ReportHeader
                title="Rekonsiliasi Persediaan"
                :description="`Nilai stok saat ini dibanding saldo akun ${summary.account_code}.`"
                export-url="/reports/inventory-reconciliation"
            />

            <Card class="report-section">
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Hasil Rekonsiliasi</h2>
                        <span
                            :class="[
                                'inline-flex items-center gap-1.5 text-sm font-semibold',
                                matched ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400',
                            ]"
                        >
                            <CheckCircle2 v-if="matched" class="h-4 w-4" aria-hidden="true" />
                            <AlertTriangle v-else class="h-4 w-4" aria-hidden="true" />
                            {{ matched ? 'Cocok' : 'Ada selisih' }}
                        </span>
                    </div>
                </template>

                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt>Nilai Persediaan</dt>
                        <dd class="font-semibold"><Rupiah :value="summary.stock_value" /></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt>Saldo Akun {{ summary.account_code }}</dt>
                        <dd class="font-semibold"><Rupiah :value="summary.ledger_balance" /></dd>
                    </div>
                    <div class="flex justify-between gap-4 border-t border-slate-200 pt-2 dark:border-slate-700">
                        <dt class="font-semibold">Selisih</dt>
                        <dd :class="['font-bold', matched ? '' : 'text-rose-700 dark:text-rose-400']" data-test="difference">
                            <Rupiah :value="summary.difference" />
                        </dd>
                    </div>
                </dl>

                <div v-if="!matched" class="mt-4 text-sm text-slate-600 dark:text-slate-300" data-test="causes">
                    <p class="font-medium">Sebab yang diketahui:</p>
                    <ul class="mt-1 list-disc space-y-1 pl-5">
                        <li>Pembulatan nilai per baris saldo stok.</li>
                        <li>Pembatalan penyesuaian stok yang menambah barang, karena stok keluar pada biaya rata-rata saat itu.</li>
                        <li>Jurnal manual yang memakai akun persediaan.</li>
                    </ul>
                </div>
            </Card>

            <Card class="report-section">
                <template #header>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Nilai per Gudang</h2>
                </template>

                <ReportTable
                    :columns="columns"
                    :rows="rows"
                    :footer="{ warehouse_name: 'Total', stock_value: summary.stock_value }"
                    empty-title="Belum ada stok"
                    empty-description="Belum ada saldo stok di gudang mana pun."
                />
            </Card>
        </div>
    </AppLayout>
</template>
