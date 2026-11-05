<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/ui/Card.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import ReportFilterBar from '@/Components/reports/ReportFilterBar.vue';
import ReportHeader from '@/Components/reports/ReportHeader.vue';
import StatementSection from '@/Components/reports/StatementSection.vue';

defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const url = '/reports/income-statement';
</script>

<template>
    <AppLayout title="Laba Rugi">
        <Head title="Laba Rugi" />

        <div class="report-page space-y-6">
            <ReportHeader
                title="Laba Rugi"
                :description="`Pendapatan dan beban, ${filters.date_from} sampai ${filters.date_to}.`"
                :export-url="url"
                :export-query="{ date_from: filters.date_from, date_to: filters.date_to }"
            />

            <ReportFilterBar :url="url" :filters="filters" />

            <StatementSection v-for="section in rows" :key="section.key" :section="section" />

            <Card class="report-section">
                <template #header>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Hasil</h2>
                </template>

                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt>Laba Kotor</dt>
                        <dd class="font-semibold" data-test="gross-profit"><Rupiah :value="summary.gross_profit" /></dd>
                    </div>
                    <div class="flex justify-between gap-4 border-t border-slate-200 pt-2 text-base dark:border-slate-700">
                        <dt class="font-semibold">Laba Bersih</dt>
                        <dd class="font-bold" data-test="net-profit"><Rupiah :value="summary.net_profit" /></dd>
                    </div>
                </dl>
            </Card>
        </div>
    </AppLayout>
</template>
