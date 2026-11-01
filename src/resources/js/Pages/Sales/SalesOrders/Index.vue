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
import { salesOrderStatusConfig } from '@/config/sales';

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

const { canEditSalesOrder } = useActionGuard();

const { search, filters, setSearch, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/sales/sales-orders', {
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
        label: salesOrderStatusConfig[s]?.label || s,
    })),
]);

const columns = [
    { key: 'number', label: 'No. SO', sortable: false, class: 'whitespace-nowrap font-mono text-xs' },
    { key: 'order_date', label: 'Tanggal', sortable: false, class: 'whitespace-nowrap text-xs' },
    { key: 'customer', label: 'Pelanggan', sortable: false },
    { key: 'warehouse', label: 'Gudang', sortable: false },
    { key: 'status', label: 'Status', sortable: false, align: 'center' },
    { key: 'total', label: 'Total Pesanan', sortable: false, align: 'right' },
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
    <AppLayout title="Pesanan Penjualan">
        <Head title="Pesanan Penjualan" />

        <div class="space-y-6">
            <PageHeader
                title="Pesanan Penjualan (SO)"
                description="Kelola pesanan penjualan dari pelanggan, persetujuan pesanan, dan pemantauan pengiriman."
            >
                <template #actions>
                    <Can permission="sales_orders.create">
                        <Link href="/sales/sales-orders/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Buat SO Baru
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <FilterBar
                :search="search"
                search-placeholder="Cari nomor SO, catatan..."
                :has-active-filters="hasActiveFilters"
                @update:search="setSearch"
                @reset="resetFilters"
            >
                <template #filters>
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
                </template>
            </FilterBar>

            <DataTable
                :columns="columns"
                :items="items.data"
                empty-text="Belum ada data pesanan penjualan."
            >
                <template #cell(number)="{ item }">
                    <Link
                        :href="`/sales/sales-orders/${item.id}`"
                        class="text-indigo-600 dark:text-indigo-400 font-semibold hover:underline"
                    >
                        {{ item.number }}
                    </Link>
                </template>

                <template #cell(order_date)="{ item }">
                    <DateDisplay :value="item.order_date" />
                </template>

                <template #cell(customer)="{ item }">
                    <div class="font-medium text-slate-800 dark:text-slate-200">
                        {{ item.customer?.name || '-' }}
                    </div>
                    <div class="text-xs text-slate-500 dark:text-slate-400 font-mono">
                        {{ item.customer?.code }}
                    </div>
                </template>

                <template #cell(warehouse)="{ item }">
                    <span class="text-xs text-slate-700 dark:text-slate-300">
                        {{ item.warehouse?.name || '-' }}
                    </span>
                </template>

                <template #cell(status)="{ item }">
                    <StatusBadge
                        :label="salesOrderStatusConfig[item.status]?.label || item.status"
                        :variant="salesOrderStatusConfig[item.status]?.variant || 'neutral'"
                    />
                </template>

                <template #cell(total)="{ item }">
                    <span class="font-mono text-xs font-semibold text-slate-800 dark:text-slate-200">
                        <Rupiah :amount="item.total" />
                    </span>
                </template>

                <template #cell(actions)="{ item }">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="`/sales/sales-orders/${item.id}`">
                            <IconButton
                                variant="ghost"
                                size="sm"
                                title="Lihat Detail SO"
                                :aria-label="`Lihat detail SO ${item.number}`"
                            >
                                <Eye class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                            </IconButton>
                        </Link>

                        <Link
                            v-if="canEditSalesOrder(item)"
                            :href="`/sales/sales-orders/${item.id}/edit`"
                        >
                            <IconButton
                                variant="ghost"
                                size="sm"
                                title="Edit Draf SO"
                                :aria-label="`Edit draf SO ${item.number}`"
                            >
                                <Edit class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                            </IconButton>
                        </Link>
                    </div>
                </template>
            </DataTable>

            <Pagination
                :meta="items.meta"
                @page-change="setPage"
                @per-page-change="setPerPage"
            />
        </div>
    </AppLayout>
</template>
