<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Eye, Edit } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import DataTable from '@/Components/ui/DataTable.vue';
import FilterBar from '@/Components/ui/FilterBar.vue';
import Pagination from '@/Components/ui/Pagination.vue';
import Button from '@/Components/ui/Button.vue';
import IconButton from '@/Components/ui/IconButton.vue';
import Select from '@/Components/ui/Select.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import StatusBadge from '@/Components/ui/StatusBadge.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import Can from '@/Components/ui/Can.vue';
import { useListQuery } from '@/composables/useListQuery';
import { useActionGuard } from '@/composables/useActionGuard';
import statusMap from '@/generated/enums.json';

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
    customers: {
        type: Array,
        default: () => [],
    },
    suppliers: {
        type: Array,
        default: () => [],
    },
    methods: {
        type: Array,
        default: () => [],
    },
    statuses: {
        type: Array,
        default: () => [],
    },
});

const { canEditPayment } = useActionGuard();

const { search, filters, setSearch, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/accounting/payments', {
    defaults: {
        search: props.items.query?.search || '',
        perPage: props.items.meta?.per_page || 25,
        filters: {
            payment_type: props.items.query?.filter?.payment_type || '',
            customer_id: props.items.query?.filter?.customer_id || '',
            supplier_id: props.items.query?.filter?.supplier_id || '',
            status: props.items.query?.filter?.status || '',
            method: props.items.query?.filter?.method || '',
            date_from: props.items.query?.filter?.date_from || '',
            date_to: props.items.query?.filter?.date_to || '',
        },
    },
});

const customerOptions = computed(() => [
    { value: '', label: 'Semua Pelanggan' },
    ...props.customers.map((c) => ({ value: String(c.id), label: `${c.code} - ${c.name}` })),
]);

const supplierOptions = computed(() => [
    { value: '', label: 'Semua Pemasok' },
    ...props.suppliers.map((s) => ({ value: String(s.id), label: `${s.code} - ${s.name}` })),
]);

const typeTabs = [
    { value: '', label: 'Semua' },
    { value: 'receipt', label: 'Penerimaan' },
    { value: 'disbursement', label: 'Pengeluaran' },
];

const showingDisbursements = computed(() => filters.value.payment_type === 'disbursement');

const statusOptions = computed(() => [
    { value: '', label: 'Semua Status' },
    ...props.statuses.map((s) => ({ value: s, label: statusMap[s]?.label || s })),
]);

const methodOptions = computed(() => [
    { value: '', label: 'Semua Metode' },
    ...props.methods,
]);

const columns = [
    { key: 'number', label: 'No. Pembayaran', sortable: false, class: 'whitespace-nowrap font-mono text-xs' },
    { key: 'paymentDate', label: 'Tanggal', sortable: false, class: 'whitespace-nowrap text-xs' },
    { key: 'customer', label: 'Pelanggan/Pemasok', sortable: false },
    { key: 'methodLabel', label: 'Metode', sortable: false },
    { key: 'account', label: 'Akun Kas/Bank', sortable: false },
    { key: 'amount', label: 'Jumlah', sortable: false, align: 'right' },
    { key: 'status', label: 'Status', sortable: false, align: 'center' },
    { key: 'actions', label: 'Aksi', sortable: false, align: 'right' },
];

const hasActiveFilters = computed(() => Boolean(
    filters.value.payment_type ||
    filters.value.customer_id ||
    filters.value.supplier_id ||
    filters.value.status ||
    filters.value.method ||
    filters.value.date_from ||
    filters.value.date_to
));
</script>

<template>
    <AppLayout title="Pembayaran Kas/Bank">
        <Head title="Pembayaran Kas/Bank" />

        <div class="space-y-6">
            <PageHeader
                title="Pembayaran Kas/Bank"
                description="Penerimaan dari pelanggan, pembayaran ke pemasok, dan alokasinya ke faktur."
            >
                <template #actions>
                    <Can permission="payments.create">
                        <Link href="/accounting/payments/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Terima Pembayaran
                            </Button>
                        </Link>
                        <Link href="/accounting/payments/create?payment_type=disbursement">
                            <Button variant="secondary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Bayar Pemasok
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <div class="flex flex-wrap gap-2" role="group" aria-label="Jenis pembayaran">
                <Button
                    v-for="tab in typeTabs"
                    :key="tab.value"
                    type="button"
                    size="sm"
                    :variant="filters.payment_type === tab.value ? 'primary' : 'secondary'"
                    :aria-pressed="filters.payment_type === tab.value"
                    @click="setFilter('payment_type', tab.value)"
                >
                    {{ tab.label }}
                </Button>
            </div>

            <FilterBar
                :search="search"
                search-placeholder="Cari nomor atau referensi..."
                :has-active-filters="hasActiveFilters"
                @update:search="setSearch"
                @reset="resetFilters"
            >
                <div v-if="showingDisbursements" class="w-full sm:w-56">
                    <Select
                        :model-value="filters.supplier_id"
                        :options="supplierOptions"
                        size="sm"
                        aria-label="Filter berdasarkan pemasok"
                        @update:model-value="setFilter('supplier_id', $event)"
                    />
                </div>

                <div v-else class="w-full sm:w-56">
                    <Select
                        :model-value="filters.customer_id"
                        :options="customerOptions"
                        size="sm"
                        aria-label="Filter berdasarkan pelanggan"
                        @update:model-value="setFilter('customer_id', $event)"
                    />
                </div>

                <div class="w-full sm:w-40">
                    <Select
                        :model-value="filters.status"
                        :options="statusOptions"
                        size="sm"
                        aria-label="Filter berdasarkan status"
                        @update:model-value="setFilter('status', $event)"
                    />
                </div>

                <div class="w-full sm:w-40">
                    <Select
                        :model-value="filters.method"
                        :options="methodOptions"
                        size="sm"
                        aria-label="Filter berdasarkan metode"
                        @update:model-value="setFilter('method', $event)"
                    />
                </div>

                <div class="w-full sm:w-36">
                    <TextInput
                        :model-value="filters.date_from"
                        type="date"
                        size="sm"
                        aria-label="Filter tanggal mulai"
                        @update:model-value="setFilter('date_from', $event)"
                    />
                </div>

                <div class="w-full sm:w-36">
                    <TextInput
                        :model-value="filters.date_to"
                        type="date"
                        size="sm"
                        aria-label="Filter tanggal akhir"
                        @update:model-value="setFilter('date_to', $event)"
                    />
                </div>
            </FilterBar>

            <DataTable
                :columns="columns"
                :data="items.data"
                empty-title="Belum ada pembayaran"
                empty-description="Pembayaran dari pelanggan akan tampil di sini."
            >
                <template #cell(number)="{ row }">
                    <Link
                        :href="`/accounting/payments/${row.id}`"
                        class="text-indigo-600 dark:text-indigo-400 font-semibold hover:underline"
                    >
                        {{ row.number }}
                    </Link>
                </template>

                <template #cell(paymentDate)="{ row }">
                    <DateDisplay :value="row.paymentDate" />
                </template>

                <template #cell(customer)="{ row }">
                    <div class="font-medium text-slate-800 dark:text-slate-200">{{ (row.supplier ?? row.customer)?.name || '-' }}</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400 font-mono">{{ (row.supplier ?? row.customer)?.code }}</div>
                </template>

                <template #cell(account)="{ row }">
                    <span class="text-xs text-slate-700 dark:text-slate-300">{{ row.account?.code }} - {{ row.account?.name }}</span>
                </template>

                <template #cell(amount)="{ row }">
                    <Rupiah :value="row.amount" />
                </template>

                <template #cell(status)="{ row }">
                    <StatusBadge :status="row.status" />
                </template>

                <template #cell(actions)="{ row }">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="`/accounting/payments/${row.id}`">
                            <IconButton
                                variant="ghost"
                                size="sm"
                                title="Lihat Detail Pembayaran"
                                :aria-label="`Lihat detail pembayaran ${row.number}`"
                            >
                                <Eye class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                            </IconButton>
                        </Link>

                        <Link v-if="canEditPayment(row)" :href="`/accounting/payments/${row.id}/edit`">
                            <IconButton
                                variant="ghost"
                                size="sm"
                                title="Ubah Draf Pembayaran"
                                :aria-label="`Ubah draf pembayaran ${row.number}`"
                            >
                                <Edit class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                            </IconButton>
                        </Link>
                    </div>
                </template>
            </DataTable>

            <Pagination
                :meta="items.meta"
                @change-page="setPage"
                @change-per-page="setPerPage"
            />
        </div>
    </AppLayout>
</template>
