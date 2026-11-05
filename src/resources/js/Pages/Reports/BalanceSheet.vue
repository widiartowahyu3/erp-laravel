<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/ui/Card.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import BalanceIndicator from '@/Components/reports/BalanceIndicator.vue';
import ReportFilterBar from '@/Components/reports/ReportFilterBar.vue';
import ReportHeader from '@/Components/reports/ReportHeader.vue';
import StatementSection from '@/Components/reports/StatementSection.vue';

defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const url = '/reports/balance-sheet';
</script>

<template>
    <AppLayout title="Neraca">
        <Head title="Neraca" />

        <div class="report-page space-y-6">
            <ReportHeader
                title="Neraca"
                :description="`Posisi aset, liabilitas, dan ekuitas per ${summary.as_of}.`"
                :export-url="url"
                :export-query="{ as_of: filters.as_of }"
            />

            <ReportFilterBar :url="url" :filters="filters" mode="as_of" />

            <StatementSection v-for="section in rows" :key="section.key" :section="section" />

            <Card class="report-section">
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Kesimpulan</h2>
                        <BalanceIndicator :balanced="summary.balanced" />
                    </div>
                </template>

                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt>Total Aset</dt>
                        <dd class="font-semibold"><Rupiah :value="summary.assets" /></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt>Total Liabilitas dan Ekuitas</dt>
                        <dd class="font-semibold"><Rupiah :value="summary.liabilities_and_equity" /></dd>
                    </div>
                </dl>

                <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                    Belum ada tutup buku, jadi laba berjalan mencakup seluruh laba sampai tanggal posisi, termasuk tahun-tahun sebelumnya.
                </p>
            </Card>
        </div>
    </AppLayout>
</template>
