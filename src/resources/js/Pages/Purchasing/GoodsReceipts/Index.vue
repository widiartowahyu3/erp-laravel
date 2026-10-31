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
import Can from '@/Components/ui/Can.vue';
import { useListQuery } from '@/composables/useListQuery';
import { useActionGuard } from '@/composables/useActionGuard';
import { goodsReceiptStatusConfig } from '@/config/purchasing';

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

const { canEditGoodsReceipt } = useActionGuard();

const { search, filters, setSearch, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/purchasing/goods-receipts', {
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
        label: goodsReceiptStatusConfig[s]?.label || s,
    })),
]);

const columns = [
    { key: 'number', label: 'No. GR', sortable: false, class: 'whitespace-nowrap font-mono text-xs' },
    { key: 'receipt_date', label: 'Tanggal Terima', sortable: false, class: 'whitespace-nowrap text-xs' },
    { key: 'po_number', label: 'No. PO Terkait', sortable: false, class: 'whitespace-nowrap font-mono text-xs' },
    { key: 'supplier', label: 'Pemasok', sortable: false },
    { key: 'warehouse', label: 'Gudang', sortable: false },
    { key: 'status', label: 'Status', sortable: false, align: 'center' },
    { key: 'items_count', label: 'Jml Item', sortable: false, align: 'center' },
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
    <AppLayout title="Penerimaan Barang (GR)">
        <Head title="Penerimaan Barang (GR)" />

        <div class="space-y-6">
            <PageHeader
                title="Penerimaan Barang (GR)"
                description="Pencatatan penerimaan fisik barang dari pesanan pembelian dan pembaruan saldo persediaan gudang."
            >
                <template #actions>
                    <Can permission="goods_receipts.create">
                        <Link
                            href="/purchasing/purchase-orders?status=approved"
                            title="Penerimaan barang dibuat dari pesanan pembelian (PO) yang telah disetujui"
                        >
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Terima dari PO
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <FilterBar
                :search="search"
                :has-active-filters="hasActiveFilters"
                placeholder="Cari no. GR, PO, pemasok, catatan..."
                @update:search="setSearch"
                @reset="resetFilters"
            >
                <template #filters>
                    <div class="w-44">
                        <Select
                            :model-value="filters.supplier_id"
                            :options="supplierOptions"
                            size="sm"
                            aria-label="Filter berdasarkan pemasok"
                            @update:model-value="setFilter('supplier_id', $event)"
                        />
                    </div>
                    <div class="w-44">
                        <Select
                            :model-value="filters.warehouse_id"
                            :options="warehouseOptions"
                            size="sm"
                            aria-label="Filter berdasarkan gudang"
                            @update:model-value="setFilter('warehouse_id', $event)"
                        />
                    </div>
                    <div class="w-40">
                        <Select
                            :model-value="filters.status"
                            :options="statusOptions"
                            size="sm"
                            aria-label="Filter berdasarkan status penerimaan"
                            @update:model-value="setFilter('status', $event)"
                        />
                    </div>
                    <div class="w-36">
                        <TextInput
                            type="date"
                            :model-value="filters.date_from"
                            size="sm"
                            aria-label="Dari tanggal penerimaan"
                            placeholder="Dari tanggal"
                            @update:model-value="setFilter('date_from', $event)"
                        />
                    </div>
                    <div class="w-36">
                        <TextInput
                            type="date"
                            :model-value="filters.date_to"
                            size="sm"
                            aria-label="Sampai tanggal penerimaan"
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
                empty-title="Belum ada penerimaan barang"
                empty-description="Buat penerimaan barang baru berdasarkan pesanan pembelian yang telah disetujui."
            >
                <template #cell(number)="{ row }">
                    <Link
                        :href="`/purchasing/goods-receipts/${row.id}`"
                        class="text-indigo-600 dark:text-indigo-400 font-semibold hover:underline"
                    >
                        {{ row.number }}
                    </Link>
                </template>

                <template #cell(receipt_date)="{ row }">
                    <DateDisplay :value="row.receipt_date" />
                </template>

                <template #cell(po_number)="{ row }">
                    <Link
                        v-if="row.purchase_order"
                        :href="`/purchasing/purchase-orders/${row.purchase_order.id}`"
                        class="text-slate-700 dark:text-slate-300 hover:underline hover:text-indigo-600"
                    >
                        {{ row.purchase_order.number }}
                    </Link>
                    <span v-else class="text-slate-400">-</span>
                </template>

                <template #cell(supplier)="{ row }">
                    <div class="text-sm font-medium text-slate-800 dark:text-slate-100">
                        {{ row.supplier?.name || '-' }}
                    </div>
                    <div class="text-xs text-slate-400 font-mono">
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
                        :label="goodsReceiptStatusConfig[row.status]?.label || row.status"
                        :variant="goodsReceiptStatusConfig[row.status]?.variant || 'neutral'"
                    />
                </template>

                <template #cell(items_count)="{ row }">
                    <span class="font-mono text-sm text-slate-700 dark:text-slate-300">
                        {{ row.items ? row.items.length : (row.items_count || 0) }}
                    </span>
                </template>

                <template #cell(actions)="{ row }">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="`/purchasing/goods-receipts/${row.id}`">
                            <IconButton
                                variant="ghost"
                                size="sm"
                                :aria-label="`Lihat detail GR ${row.number}`"
                                title="Lihat detail"
                            >
                                <Eye class="w-4 h-4 text-slate-500" aria-hidden="true" />
                            </IconButton>
                        </Link>

                        <Link
                            v-if="canEditGoodsReceipt(row)"
                            :href="`/purchasing/goods-receipts/${row.id}/edit`"
                        >
                            <IconButton
                                variant="ghost"
                                size="sm"
                                :aria-label="`Edit GR ${row.number}`"
                                title="Edit draf GR"
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
