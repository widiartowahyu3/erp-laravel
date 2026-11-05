<script setup>
import { computed } from 'vue';
import Card from '@/Components/ui/Card.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import ReportFilterBar from './ReportFilterBar.vue';
import ReportHeader from './ReportHeader.vue';
import ReportTable from './ReportTable.vue';

const props = defineProps({
    title: { type: String, required: true },
    url: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
    filters: { type: Object, required: true },
    orderLabel: { type: String, required: true },
    fulfilmentLabel: { type: String, required: true },
    fulfilmentCount: { type: Number, required: true },
    partyLabel: { type: String, required: true },
});

const columns = computed(() => [
    { key: 'party_code', label: 'Kode' },
    { key: 'party_name', label: props.partyLabel },
    { key: 'invoice_count', label: 'Jumlah Faktur', align: 'right' },
    { key: 'value', label: 'Nilai Faktur', type: 'money' },
]);
</script>

<template>
    <div class="report-page space-y-6">
        <ReportHeader
            :title="title"
            :description="`Periode ${filters.date_from} sampai ${filters.date_to}.`"
            :export-url="url"
            :export-query="{ date_from: filters.date_from, date_to: filters.date_to }"
        />

        <ReportFilterBar :url="url" :filters="filters" />

        <Card class="report-section">
            <template #header>
                <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Ringkasan Dokumen</h2>
            </template>

            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">{{ orderLabel }}</dt>
                    <dd class="mt-1 font-semibold">{{ summary.order_count }} dokumen, <Rupiah :value="summary.order_value" /></dd>
                </div>
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">{{ fulfilmentLabel }}</dt>
                    <dd class="mt-1 font-semibold">{{ fulfilmentCount }} dokumen</dd>
                </div>
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">Faktur</dt>
                    <dd class="mt-1 font-semibold">{{ summary.invoice_count }} dokumen, <Rupiah :value="summary.invoice_value" /></dd>
                </div>
            </dl>
        </Card>

        <Card class="report-section">
            <template #header>
                <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">10 {{ partyLabel }} Teratas</h2>
            </template>

            <ReportTable
                :columns="columns"
                :rows="rows"
                empty-title="Belum ada faktur"
                empty-description="Tidak ada faktur yang diposting pada periode ini."
            />
        </Card>
    </div>
</template>
