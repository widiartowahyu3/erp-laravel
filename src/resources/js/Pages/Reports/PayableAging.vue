<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/ui/Card.vue';
import AgingTable from '@/Components/reports/AgingTable.vue';
import ReportHeader from '@/Components/reports/ReportHeader.vue';

defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
});
</script>

<template>
    <AppLayout title="Umur Utang">
        <Head title="Umur Utang" />

        <div class="report-page space-y-6">
            <ReportHeader
                title="Umur Utang"
                :description="`Sisa tagihan pemasok menurut lama lewat jatuh tempo. Posisi hari ini, ${summary.as_of}.`"
                export-url="/reports/payable-aging"
            />

            <Card>
                <template #header>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Utang per Umur</h2>
                </template>

                <AgingTable
                    :rows="rows"
                    :summary="summary"
                    party-label="Pemasok"
                    invoice-base-url="/purchasing/invoices"
                    invoice-permission="purchase_invoices.view"
                />
            </Card>
        </div>
    </AppLayout>
</template>
