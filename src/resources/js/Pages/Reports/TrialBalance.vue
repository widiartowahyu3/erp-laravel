<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/ui/Card.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import BalanceIndicator from '@/Components/reports/BalanceIndicator.vue';
import ReportFilterBar from '@/Components/reports/ReportFilterBar.vue';
import ReportHeader from '@/Components/reports/ReportHeader.vue';
import ReportTable from '@/Components/reports/ReportTable.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const url = '/reports/trial-balance';

const columns = [
    { key: 'code', label: 'Kode' },
    { key: 'name', label: 'Nama Akun' },
    { key: 'opening', label: 'Saldo Awal', type: 'money' },
    { key: 'debit', label: 'Debit', type: 'money' },
    { key: 'credit', label: 'Kredit', type: 'money' },
    { key: 'closing', label: 'Saldo Akhir', type: 'money' },
];

const footer = computed(() => ({
    name: 'Total Mutasi',
    debit: props.summary.total_debit,
    credit: props.summary.total_credit,
}));
</script>

<template>
    <AppLayout title="Neraca Saldo">
        <Head title="Neraca Saldo" />

        <div class="report-page space-y-6">
            <ReportHeader
                title="Neraca Saldo"
                :description="`Saldo awal, mutasi, dan saldo akhir per akun, ${filters.date_from} sampai ${filters.date_to}.`"
                :export-url="url"
                :export-query="{ date_from: filters.date_from, date_to: filters.date_to }"
            />

            <ReportFilterBar :url="url" :filters="filters" />

            <Card>
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Daftar Akun</h2>
                        <BalanceIndicator :balanced="summary.balanced" />
                    </div>
                </template>

                <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
                    Saldo awal debit <Rupiah :value="summary.opening_debit" />, saldo awal kredit <Rupiah :value="summary.opening_credit" />.
                </p>

                <ReportTable
                    :columns="columns"
                    :rows="rows"
                    :footer="footer"
                    empty-title="Tidak ada mutasi"
                    empty-description="Belum ada jurnal yang diposting sampai akhir periode ini."
                />
            </Card>
        </div>
    </AppLayout>
</template>
