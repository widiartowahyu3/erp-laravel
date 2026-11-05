<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/ui/Card.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import FormField from '@/Components/ui/FormField.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import Select from '@/Components/ui/Select.vue';
import ReportFilterBar from '@/Components/reports/ReportFilterBar.vue';
import ReportHeader from '@/Components/reports/ReportHeader.vue';
import ReportTable from '@/Components/reports/ReportTable.vue';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: null },
    filters: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
});

const url = '/reports/customer-statement';
const { can } = usePermission();

const customerId = ref(props.filters.customer_id ? String(props.filters.customer_id) : '');

const customerOptions = computed(() => [
    { value: '', label: 'Pilih pelanggan' },
    ...props.customers.map((customer) => ({
        value: String(customer.id),
        label: `${customer.code} - ${customer.name}${customer.is_active ? '' : ' (nonaktif)'}`,
    })),
]);

const columns = [
    { key: 'entry_date', label: 'Tanggal', type: 'date' },
    { key: 'kind', label: 'Jenis' },
    { key: 'number', label: 'Nomor' },
    { key: 'applied_to', label: 'Untuk Faktur' },
    { key: 'debit', label: 'Debit', type: 'money' },
    { key: 'credit', label: 'Kredit', type: 'money' },
    { key: 'balance', label: 'Saldo', type: 'money' },
];

const footer = computed(() => ({
    kind: 'Total',
    debit: props.summary?.total_debit,
    credit: props.summary?.total_credit,
    balance: props.summary?.closing,
}));

function documentUrl(row) {
    if (row.kind === 'invoice') {
        return can('sales_invoices.view') ? `/sales/invoices/${row.document_id}` : null;
    }

    return can('payments.view') ? `/accounting/payments/${row.document_id}` : null;
}
</script>

<template>
    <AppLayout title="Kartu Piutang">
        <Head title="Kartu Piutang" />

        <div class="report-page space-y-6">
            <ReportHeader
                title="Kartu Piutang"
                :description="summary ? `${summary.customer_code} ${summary.customer_name}, ${filters.date_from} sampai ${filters.date_to}.` : 'Faktur dan pembayaran satu pelanggan dengan saldo berjalan.'"
                :export-url="summary ? url : ''"
                :export-query="{ date_from: filters.date_from, date_to: filters.date_to, customer_id: filters.customer_id }"
            />

            <ReportFilterBar v-slot="{ errors }" :url="url" :filters="filters" :params="{ customer_id: customerId }">
                <FormField v-slot="{ id, invalid }" label="Pelanggan" :error="errors.customer_id" class="sm:w-80">
                    <Select :id="id" v-model="customerId" :options="customerOptions" :invalid="invalid" />
                </FormField>
            </ReportFilterBar>

            <Card v-if="summary">
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Mutasi Piutang</h2>
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            Saldo awal <Rupiah :value="summary.opening" />
                        </p>
                    </div>
                </template>

                <ReportTable
                    :columns="columns"
                    :rows="rows"
                    :footer="footer"
                    empty-title="Tidak ada mutasi"
                    empty-description="Pelanggan ini tidak punya faktur atau pembayaran pada periode ini."
                >
                    <template #cell-kind="{ row }">{{ row.kind === 'invoice' ? 'Faktur' : 'Pembayaran' }}</template>
                    <template #cell-number="{ row }">
                        <Link
                            v-if="documentUrl(row)"
                            :href="documentUrl(row)"
                            class="font-mono text-xs text-indigo-700 hover:underline dark:text-indigo-400"
                        >
                            {{ row.number }}
                        </Link>
                        <span v-else class="font-mono text-xs">{{ row.number }}</span>
                    </template>
                    <template #cell-applied_to="{ row }">
                        <span class="font-mono text-xs">{{ row.applied_to ?? '-' }}</span>
                    </template>
                </ReportTable>
            </Card>

            <Card v-else>
                <EmptyState title="Pilih pelanggan" description="Pilih pelanggan lalu tekan Terapkan untuk melihat kartu piutangnya." />
            </Card>
        </div>
    </AppLayout>
</template>
