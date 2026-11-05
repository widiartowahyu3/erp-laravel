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
    statuses: {
        type: Array,
        default: () => [],
    },
});

const { canEditSalesInvoice } = useActionGuard();

const { search, filters, setSearch, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/sales/invoices', {
    defaults: {
        search: props.items.query?.search || '',
        perPage: props.items.meta?.per_page || 25,
        filters: {
            customer_id: props.items.query?.filter?.customer_id || '',
            status: props.items.query?.filter?.status || '',
            overdue: props.items.query?.filter?.overdue || '',
            date_from: props.items.query?.filter?.date_from || '',
            date_to: props.items.query?.filter?.date_to || '',
        },
    },
});

const customerOptions = computed(() => [
    { value: '', label: 'Semua Pelanggan' },
    ...props.customers.map((c) => ({
        value: String(c.id),
        label: `${c.code} - ${c.name}`,
    })),
]);

const statusOptions = computed(() => [
    { value: '', label: 'Semua Status' },
    ...props.statuses.map((s) => ({
        value: s,
        label: statusMap[s]?.label || s,
    })),
]);

const overdueOptions = [
    { value: '', label: 'Semua Jatuh Tempo' },
    { value: '1', label: 'Jatuh tempo lewat' },
];

const columns = [
    { key: 'number', label: 'No. Faktur', sortable: false, class: 'whitespace-nowrap font-mono text-xs' },
    { key: 'invoiceDate', label: 'Tanggal', sortable: false, class: 'whitespace-nowrap text-xs' },
    { key: 'dueDate', label: 'Jatuh Tempo', sortable: false, class: 'whitespace-nowrap text-xs' },
    { key: 'customer', label: 'Pelanggan', sortable: false },
    { key: 'total', label: 'Total', sortable: false, align: 'right' },
    { key: 'paidAmount', label: 'Dibayar', sortable: false, align: 'right' },
    { key: 'outstanding', label: 'Sisa', sortable: false, align: 'right' },
    { key: 'status', label: 'Status', sortable: false, align: 'center' },
    { key: 'actions', label: 'Aksi', sortable: false, align: 'right' },
];

const hasActiveFilters = computed(() => Boolean(
    filters.value.customer_id ||
    filters.value.status ||
    filters.value.overdue ||
    filters.value.date_from ||
    filters.value.date_to
));
</script>

<template>
    <AppLayout title="Faktur Penjualan (SI)">
        <Head title="Faktur Penjualan (SI)" />

        <div class="space-y-6">
            <PageHeader
                title="Faktur Penjualan (SI)"
                description="Penagihan pelanggan dari surat jalan yang sudah diposting."
            >
                <template #actions>
                    <Can permission="sales_invoices.create">
                        <Link href="/sales/invoices/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Buat Faktur
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <FilterBar
                :search="search"
                search-placeholder="Cari nomor faktur..."
                :has-active-filters="hasActiveFilters"
                @update:search="setSearch"
                @reset="resetFilters"
            >
                <div class="w-full sm:w-56">
                    <Select
                        :model-value="filters.customer_id"
                        :options="customerOptions"
                        size="sm"
                        aria-label="Filter berdasarkan pelanggan"
                        @update:model-value="setFilter('customer_id', $event)"
                    />
                </div>

                <div class="w-full sm:w-44">
                    <Select
                        :model-value="filters.status"
                        :options="statusOptions"
                        size="sm"
                        aria-label="Filter berdasarkan status"
                        @update:model-value="setFilter('status', $event)"
                    />
                </div>

                <div class="w-full sm:w-44">
                    <Select
                        :model-value="filters.overdue"
                        :options="overdueOptions"
                        size="sm"
                        aria-label="Filter jatuh tempo"
                        @update:model-value="setFilter('overdue', $event)"
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
                empty-title="Belum ada faktur penjualan"
                empty-description="Faktur dibuat dari surat jalan yang sudah diposting."
            >
                <template #cell(number)="{ row }">
                    <Link
                        :href="`/sales/invoices/${row.id}`"
                        class="text-indigo-600 dark:text-indigo-400 font-semibold hover:underline"
                    >
                        {{ row.number }}
                    </Link>
                </template>

                <template #cell(invoiceDate)="{ row }">
                    <DateDisplay :value="row.invoiceDate" />
                </template>

                <template #cell(dueDate)="{ row }">
                    <DateDisplay :value="row.dueDate" />
                    <span v-if="row.isOverdue" class="block text-xs font-medium text-red-700 dark:text-red-400">
                        Lewat tempo
                    </span>
                </template>

                <template #cell(customer)="{ row }">
                    <div class="font-medium text-slate-800 dark:text-slate-200">
                        {{ row.customer?.name || '-' }}
                    </div>
                    <div class="text-xs text-slate-500 dark:text-slate-400 font-mono">
                        {{ row.customer?.code }}
                    </div>
                </template>

                <template #cell(total)="{ row }">
                    <Rupiah :value="row.total" />
                </template>

                <template #cell(paidAmount)="{ row }">
                    <Rupiah :value="row.paidAmount" />
                </template>

                <template #cell(outstanding)="{ row }">
                    <Rupiah :value="row.outstanding" />
                </template>

                <template #cell(status)="{ row }">
                    <StatusBadge :status="row.status" />
                </template>

                <template #cell(actions)="{ row }">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="`/sales/invoices/${row.id}`">
                            <IconButton
                                variant="ghost"
                                size="sm"
                                title="Lihat Detail Faktur"
                                :aria-label="`Lihat detail faktur ${row.number}`"
                            >
                                <Eye class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                            </IconButton>
                        </Link>

                        <Link
                            v-if="canEditSalesInvoice(row)"
                            :href="`/sales/invoices/${row.id}/edit`"
                        >
                            <IconButton
                                variant="ghost"
                                size="sm"
                                title="Ubah Draf Faktur"
                                :aria-label="`Ubah draf faktur ${row.number}`"
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
