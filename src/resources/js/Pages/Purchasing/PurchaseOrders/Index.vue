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
import { purchaseOrderStatusConfig } from '@/config/purchasing';

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
    suppliers: {
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

const { canEditPurchaseOrder } = useActionGuard();

const { search, filters, setSearch, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/purchasing/purchase-orders', {
    defaults: {
        search: props.items.query?.search || '',
        perPage: props.items.meta?.per_page || 15,
        filters: {
            supplier_id: props.items.query?.filter?.supplier_id || '',
            warehouse_id: props.items.query?.filter?.warehouse_id || '',
            status: props.items.query?.filter?.status || '',
            date_from: props.items.query?.filter?.date_from || '',
            date_to: props.items.query?.filter?.date_to || '',
        },
    },
});

const supplierOptions = computed(() => [
    { value: '', label: 'Semua Pemasok' },
    ...props.suppliers.map((s) => ({
        value: String(s.id),
        label: `${s.code} - ${s.name}`,
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
        label: purchaseOrderStatusConfig[s]?.label || s,
    })),
]);

const columns = [
    { key: 'number', label: 'No. PO', sortable: false, class: 'whitespace-nowrap font-mono text-xs' },
    { key: 'order_date', label: 'Tanggal', sortable: false, class: 'whitespace-nowrap text-xs' },
    { key: 'supplier', label: 'Pemasok', sortable: false },
    { key: 'warehouse', label: 'Gudang', sortable: false },
    { key: 'status', label: 'Status', sortable: false, align: 'center' },
    { key: 'total', label: 'Total Pesanan', sortable: false, align: 'right' },
    { key: 'actions', label: 'Aksi', sortable: false, align: 'right' },
];

const hasActiveFilters = computed(() => {
    return Boolean(
        filters.value.supplier_id ||
        filters.value.warehouse_id ||
        filters.value.status ||
        filters.value.date_from ||
        filters.value.date_to
    );
});
</script>

<template>
    <AppLayout title="Pesanan Pembelian (PO)">
        <Head title="Pesanan Pembelian (PO)" />

        <div class="space-y-6">
            <PageHeader
                title="Pesanan Pembelian (PO)"
                description="Pengelolaan pengadaan barang, pesanan pembelian kepada mitra pemasok, dan kontrol penerimaan barang."
            >
                <template #actions>
                    <Can permission="purchase_orders.create">
                        <Link href="/purchasing/purchase-orders/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Buat PO Baru
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <FilterBar
                :search="search"
                :has-active-filters="hasActiveFilters"
                placeholder="Cari no. PO, pemasok, catatan..."
                @update:search="setSearch"
                @reset="resetFilters"
            >
                <template #filters>
                    <div class="w-44">
                        <Select
                            :model-value="filters.supplier_id"
                            :options="supplierOptions"
                            size="sm"
                            aria-label="Filter berdasarkan mitra pemasok"
                            @update:model-value="setFilter('supplier_id', $event)"
                        />
                    </div>
                    <div class="w-44">
                        <Select
                            :model-value="filters.warehouse_id"
                            :options="warehouseOptions"
                            size="sm"
                            aria-label="Filter berdasarkan fasilitas gudang"
                            @update:model-value="setFilter('warehouse_id', $event)"
                        />
                    </div>
                    <div class="w-40">
                        <Select
                            :model-value="filters.status"
                            :options="statusOptions"
                            size="sm"
                            aria-label="Filter berdasarkan status PO"
                            @update:model-value="setFilter('status', $event)"
                        />
                    </div>
                    <div class="w-36">
                        <TextInput
                            type="date"
                            :model-value="filters.date_from"
                            size="sm"
                            aria-label="Dari tanggal pesanan"
                            placeholder="Dari tanggal"
                            @update:model-value="setFilter('date_from', $event)"
                        />
                    </div>
                    <div class="w-36">
                        <TextInput
                            type="date"
                            :model-value="filters.date_to"
                            size="sm"
                            aria-label="Sampai tanggal pesanan"
                            placeholder="Sampai tanggal"
                            @update:model-value="setFilter('date_to', $event)"
                        />
                    </div>
                </template>
            </FilterBar>

            <DataTable
                :columns="columns"
                :rows="items.data"
                :empty="items.data.length === 0"
                empty-title="Belum ada pesanan pembelian"
                empty-description="Buat pesanan pembelian baru untuk memulai proses pengadaan barang."
            >
                <template #cell(number)="{ row }">
                    <Link
                        :href="`/purchasing/purchase-orders/${row.id}`"
                        class="text-indigo-600 dark:text-indigo-400 font-semibold hover:underline"
                    >
                        {{ row.number }}
                    </Link>
                </template>

                <template #cell(order_date)="{ row }">
                    <DateDisplay :value="row.order_date" />
                </template>

                <template #cell(supplier)="{ row }">
                    <div class="text-sm font-medium text-slate-800 dark:text-slate-100">
                        {{ row.supplier?.name || '-' }}
                    </div>
                    <div class="text-xs text-slate-500 font-mono">
                        {{ row.supplier?.code }}
                    </div>
                </template>

                <template #cell(warehouse)="{ row }">
                    <div class="text-sm text-slate-700 dark:text-slate-200">
                        {{ row.warehouse?.name || '-' }}
                    </div>
                </template>

                <template #cell(status)="{ row }">
                    <StatusBadge
                        :status="row.status"
                        :label="purchaseOrderStatusConfig[row.status]?.label || row.status"
                        :variant="purchaseOrderStatusConfig[row.status]?.variant || 'neutral'"
                    />
                </template>

                <template #cell(total)="{ row }">
                    <span class="font-medium text-slate-900 dark:text-slate-100 font-mono text-sm">
                        <Rupiah :value="row.total" />
                    </span>
                </template>

                <template #cell(actions)="{ row }">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="`/purchasing/purchase-orders/${row.id}`">
                            <IconButton
                                variant="ghost"
                                size="sm"
                                :aria-label="`Lihat detail PO ${row.number}`"
                                title="Lihat detail"
                            >
                                <Eye class="w-4 h-4 text-slate-500" aria-hidden="true" />
                            </IconButton>
                        </Link>

                        <Link
                            v-if="canEditPurchaseOrder(row)"
                            :href="`/purchasing/purchase-orders/${row.id}/edit`"
                        >
                            <IconButton
                                variant="ghost"
                                size="sm"
                                :aria-label="`Edit PO ${row.number}`"
                                title="Edit draf PO"
                            >
                                <Edit class="w-4 h-4 text-indigo-600 dark:text-indigo-400" aria-hidden="true" />
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
