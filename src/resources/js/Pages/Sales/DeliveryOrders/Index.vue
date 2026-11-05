<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Eye, Edit } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import ExportButton from '@/Components/reports/ExportButton.vue';
import DataTable from '@/Components/ui/DataTable.vue';
import FilterBar from '@/Components/ui/FilterBar.vue';
import Pagination from '@/Components/ui/Pagination.vue';
import Button from '@/Components/ui/Button.vue';
import IconButton from '@/Components/ui/IconButton.vue';
import Select from '@/Components/ui/Select.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import StatusBadge from '@/Components/ui/StatusBadge.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
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
    warehouses: {
        type: Array,
        default: () => [],
    },
    statuses: {
        type: Array,
        default: () => [],
    },
});

const { canEditDeliveryOrder } = useActionGuard();

const { search, filters, setSearch, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/sales/delivery-orders', {
    defaults: {
        search: props.items.query?.search || '',
        perPage: props.items.meta?.per_page || 15,
        filters: {
            customer_id: props.items.query?.filter?.customer_id || '',
            warehouse_id: props.items.query?.filter?.warehouse_id || '',
            status: props.items.query?.filter?.status || '',
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

const warehouseOptions = computed(() => [
    { value: '', label: 'Semua Gudang' },
    ...props.warehouses.map((w) => ({
        value: String(w.id),
        label: `${w.code} - ${w.name}`,
    })),
]);

const statusOptions = computed(() => [
    { value: '', label: 'Semua Status' },
    ...props.statuses.map((s) => ({
        value: s,
        label: statusMap[s]?.label || s,
    })),
]);

const columns = [
    { key: 'number', label: 'No. DO', sortable: false, class: 'whitespace-nowrap font-mono text-xs' },
    { key: 'delivery_date', label: 'Tanggal Kirim', sortable: false, class: 'whitespace-nowrap text-xs' },
    { key: 'so_number', label: 'No. SO Terkait', sortable: false, class: 'whitespace-nowrap font-mono text-xs' },
    { key: 'customer', label: 'Pelanggan', sortable: false },
    { key: 'warehouse', label: 'Gudang', sortable: false },
    { key: 'status', label: 'Status', sortable: false, align: 'center' },
    { key: 'actions', label: 'Aksi', sortable: false, align: 'right' },
];

const hasActiveFilters = computed(() => {
    return Boolean(
        filters.value.customer_id ||
        filters.value.warehouse_id ||
        filters.value.status ||
        filters.value.date_from ||
        filters.value.date_to
    );
});
</script>

<template>
    <AppLayout title="Surat Jalan / Pengiriman (DO)">
        <Head title="Surat Jalan / Pengiriman (DO)" />

        <div class="space-y-6">
            <PageHeader
                title="Surat Jalan / Pengiriman (DO)"
                description="Pengelolaan pengiriman fisik barang ke pelanggan dan pemotongan stok gudang."
            >
                <template #actions>
                    <ExportButton url="/sales/delivery-orders" :query="items.query ?? {}" />
                    <Can permission="delivery_orders.create">
                        <Link
                            href="/sales/sales-orders?filter[status]=approved"
                            title="Surat jalan dibuat dari pesanan penjualan (SO) yang telah disetujui"
                        >
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Kirim dari SO
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <FilterBar
                :search="search"
                search-placeholder="Cari nomor DO, catatan..."
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

                <div class="w-full sm:w-48">
                    <Select
                        :model-value="filters.warehouse_id"
                        :options="warehouseOptions"
                        size="sm"
                        aria-label="Filter berdasarkan gudang"
                        @update:model-value="setFilter('warehouse_id', $event)"
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
                empty-title="Belum ada surat jalan"
                empty-description="Belum ada data surat jalan untuk ditampilkan."
            >
                <template #cell(number)="{ row }">
                    <Link
                        :href="`/sales/delivery-orders/${row.id}`"
                        class="text-indigo-600 dark:text-indigo-400 font-semibold hover:underline"
                    >
                        {{ row.number }}
                    </Link>
                </template>

                <template #cell(delivery_date)="{ row }">
                    <DateDisplay :value="row.delivery_date" />
                </template>

                <template #cell(so_number)="{ row }">
                    <Link
                        v-if="row.sales_order"
                        :href="`/sales/sales-orders/${row.sales_order.id}`"
                        class="text-slate-600 dark:text-slate-400 hover:underline"
                    >
                        {{ row.sales_order.number }}
                    </Link>
                    <span v-else class="text-slate-400">-</span>
                </template>

                <template #cell(customer)="{ row }">
                    <div class="font-medium text-slate-800 dark:text-slate-200">
                        {{ row.customer?.name || '-' }}
                    </div>
                    <div class="text-xs text-slate-500 dark:text-slate-400 font-mono">
                        {{ row.customer?.code }}
                    </div>
                </template>

                <template #cell(warehouse)="{ row }">
                    <span class="text-xs text-slate-700 dark:text-slate-300">
                        {{ row.warehouse?.name || '-' }}
                    </span>
                </template>

                <template #cell(status)="{ row }">
                    <StatusBadge :status="row.status" />
                </template>

                <template #cell(actions)="{ row }">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="`/sales/delivery-orders/${row.id}`">
                            <IconButton
                                variant="ghost"
                                size="sm"
                                title="Lihat Detail DO"
                                :aria-label="`Lihat detail DO ${row.number}`"
                            >
                                <Eye class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                            </IconButton>
                        </Link>

                        <Link
                            v-if="canEditDeliveryOrder(row)"
                            :href="`/sales/delivery-orders/${row.id}/edit`"
                        >
                            <IconButton
                                variant="ghost"
                                size="sm"
                                title="Edit Draf DO"
                                :aria-label="`Edit draf DO ${row.number}`"
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
