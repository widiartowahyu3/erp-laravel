<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/ui/Card.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import FormField from '@/Components/ui/FormField.vue';
import Pagination from '@/Components/ui/Pagination.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import Select from '@/Components/ui/Select.vue';
import ReportFilterBar from '@/Components/reports/ReportFilterBar.vue';
import ReportHeader from '@/Components/reports/ReportHeader.vue';
import ReportTable from '@/Components/reports/ReportTable.vue';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: null },
    meta: { type: Object, default: null },
    filters: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
});

const url = '/reports/general-ledger';
const { can } = usePermission();

const accountId = ref(props.filters.account_id ? String(props.filters.account_id) : '');

const accountOptions = computed(() => [
    { value: '', label: 'Pilih akun' },
    ...props.accounts.map((account) => ({ value: String(account.id), label: `${account.code} - ${account.name}` })),
]);

const columns = [
    { key: 'journal_date', label: 'Tanggal', type: 'date' },
    { key: 'journal_number', label: 'Nomor Jurnal' },
    { key: 'description', label: 'Deskripsi' },
    { key: 'source_label', label: 'Sumber' },
    { key: 'debit', label: 'Debit', type: 'money' },
    { key: 'credit', label: 'Kredit', type: 'money' },
    { key: 'balance', label: 'Saldo', type: 'money' },
];

const baseQuery = computed(() => ({
    date_from: props.filters.date_from,
    date_to: props.filters.date_to,
    account_id: props.filters.account_id,
}));

const footer = computed(() => ({
    description: 'Total Mutasi',
    debit: props.summary?.total_debit,
    credit: props.summary?.total_credit,
    balance: props.summary?.closing,
}));

function visit(extra) {
    router.get(url, { ...baseQuery.value, per_page: props.filters.per_page, ...extra }, { preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
    <AppLayout title="Buku Besar">
        <Head title="Buku Besar" />

        <div class="report-page space-y-6">
            <ReportHeader
                title="Buku Besar"
                :description="summary ? `${summary.account_code} ${summary.account_name}, ${filters.date_from} sampai ${filters.date_to}.` : 'Mutasi satu akun dengan saldo berjalan.'"
                :export-url="summary ? url : ''"
                :export-query="baseQuery"
            />

            <ReportFilterBar v-slot="{ errors }" :url="url" :filters="filters" :params="{ account_id: accountId, per_page: filters.per_page }">
                <FormField v-slot="{ id, invalid }" label="Akun" :error="errors.account_id" class="sm:w-80">
                    <Select :id="id" v-model="accountId" :options="accountOptions" :invalid="invalid" />
                </FormField>
            </ReportFilterBar>

            <Card v-if="summary">
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Mutasi Akun</h2>
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
                    empty-description="Akun ini tidak punya jurnal yang diposting pada periode ini."
                >
                    <template #cell-journal_number="{ row }">
                        <Link
                            v-if="can('journals.view')"
                            :href="`/accounting/journals/${row.journal_id}`"
                            class="font-mono text-xs text-indigo-700 hover:underline dark:text-indigo-400"
                        >
                            {{ row.journal_number }}
                        </Link>
                        <span v-else class="font-mono text-xs">{{ row.journal_number }}</span>
                    </template>
                    <template #cell-source_label="{ row }">
                        <Link v-if="row.source_url" :href="row.source_url" class="text-indigo-700 hover:underline dark:text-indigo-400">
                            {{ row.source_label }}
                        </Link>
                        <template v-else>{{ row.source_label ?? 'Manual' }}</template>
                    </template>
                </ReportTable>

                <template v-if="meta && meta.total > 0" #footer>
                    <div class="print:hidden">
                        <Pagination
                            :meta="meta"
                            :per-page="filters.per_page"
                            :per-page-options="[25, 50, 100]"
                            @change-page="visit({ page: $event })"
                            @change-per-page="visit({ per_page: $event })"
                        />
                    </div>
                </template>
            </Card>

            <Card v-else>
                <EmptyState title="Pilih akun" description="Pilih akun lalu tekan Terapkan untuk melihat buku besarnya." />
            </Card>
        </div>
    </AppLayout>
</template>
