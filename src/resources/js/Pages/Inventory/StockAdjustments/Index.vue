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
import { stockAdjustmentStatusConfig } from '@/config/inventory';

const props = defineProps({
    items: {
        type: Object,
        required: true,
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

const { canEditAdjustment } = useActionGuard();

const { search, filters, setSearch, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/inventory/stock-adjustments', {
    defaults: {
        search: props.items.query?.search || '',
        perPage: props.items.meta?.per_page || 15,
        filters: {
            warehouse_id: props.items.query?.filter?.warehouse_id || '',
            status: props.items.query?.filter?.status || '',
            date_from: props.items.query?.filter?.date_from || '',
            date_to: props.items.query?.filter?.date_to || '',
        },
    },
});

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
        label: stockAdjustmentStatusConfig[s]?.label || s,
    })),
]);

const columns = [
    { key: 'number', label: 'No. Penyesuaian', sortable: false, class: 'whitespace-nowrap font-mono text-xs' },
    { key: 'adjustment_date', label: 'Tanggal', sortable: false, class: 'whitespace-nowrap text-xs' },
    { key: 'warehouse', label: 'Gudang', sortable: false },
    { key: 'reason', label: 'Alasan Penyesuaian', sortable: false },
    { key: 'status', label: 'Status', sortable: false, align: 'center' },
    { key: 'created_by', label: 'Dibuat Oleh', sortable: false },
    { key: 'actions', label: 'Aksi', sortable: false, align: 'right' },
];

const hasActiveFilters = computed(() => {
    return Boolean(
        filters.value.warehouse_id ||
        filters.value.status ||
        filters.value.date_from ||
        filters.value.date_to
    );
});
</script>

<template>
    <AppLayout title="Penyesuaian Stok">
        <Head title="Penyesuaian Stok" />

        <div class="space-y-6">
            <PageHeader
                title="Penyesuaian Stok"
                description="Pengelolaan dokumen penyesuaian fisik barang persediaan (stock opname) dan koreksi kuantitas gudang."
            >
                <template #actions>
                    <Can permission="stock_adjustments.create">
                        <Link href="/inventory/stock-adjustments/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Buat Penyesuaian
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
                <FilterBar
                    :search="search"
                    :has-active-filters="hasActiveFilters"
                    search-placeholder="Cari nomor penyesuaian atau alasan..."
                    @update:search="setSearch"
                    @reset="resetFilters"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="w-40 sm:w-48">
                            <Select
                                :model-value="filters.warehouse_id || ''"
                                :options="warehouseOptions"
                                aria-label="Filter Gudang"
                                @update:model-value="setFilter('warehouse_id', $event)"
                            />
                        </div>

                        <div class="w-36">
                            <Select
                                :model-value="filters.status || ''"
                                :options="statusOptions"
                                aria-label="Filter Status"
                                @update:model-value="setFilter('status', $event)"
                            />
                        </div>

                        <div class="w-36">
                            <TextInput
                                :model-value="filters.date_from || ''"
                                type="date"
                                aria-label="Tanggal Dari"
                                @change="setFilter('date_from', $event.target.value)"
                            />
                        </div>

                        <div class="w-36">
                            <TextInput
                                :model-value="filters.date_to || ''"
                                type="date"
                                aria-label="Tanggal Sampai"
                                @change="setFilter('date_to', $event.target.value)"
                            />
                        </div>
                    </div>
                </FilterBar>

                <DataTable
                    :columns="columns"
                    :data="items.data"
                    empty-title="Belum ada penyesuaian stok"
                    empty-description="Belum ada dokumen penyesuaian stok opname yang tercatat pada sistem."
                >
                    <template #cell(number)="{ row }">
                        <Link
                            :href="`/inventory/stock-adjustments/${row.id}`"
                            class="font-mono text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                        >
                            {{ row.number }}
                        </Link>
                    </template>

                    <template #cell(adjustment_date)="{ row }">
                        <DateDisplay :value="row.adjustment_date" format="short" />
                    </template>

                    <template #cell(warehouse)="{ row }">
                        <span class="text-xs text-slate-700 dark:text-slate-300">
                            {{ row.warehouse?.code }} - {{ row.warehouse?.name }}
                        </span>
                    </template>

                    <template #cell(reason)="{ row }">
                        <span class="text-sm text-slate-900 dark:text-slate-100 line-clamp-1">
                            {{ row.reason }}
                        </span>
                    </template>

                    <template #cell(status)="{ row }">
                        <StatusBadge :status="row.status" />
                    </template>

                    <template #cell(created_by)="{ row }">
                        <span class="text-xs text-slate-600 dark:text-slate-400">
                            {{ row.creator?.name || 'Sistem' }}
                        </span>
                    </template>

                    <template #cell(actions)="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <Link :href="`/inventory/stock-adjustments/${row.id}`">
                                <IconButton variant="ghost" size="sm" aria-label="Lihat Detail">
                                    <Eye class="w-4 h-4 text-slate-600 dark:text-slate-400" aria-hidden="true" />
                                </IconButton>
                            </Link>

                            <Link
                                v-if="canEditAdjustment(row)"
                                :href="`/inventory/stock-adjustments/${row.id}/edit`"
                            >
                                <IconButton variant="ghost" size="sm" aria-label="Ubah Draf">
                                    <Edit class="w-4 h-4 text-slate-600 dark:text-slate-400" aria-hidden="true" />
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
        </div>
    </AppLayout>
</template>
