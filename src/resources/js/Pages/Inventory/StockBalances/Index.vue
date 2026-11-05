<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { BookOpen } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import ExportButton from '@/Components/reports/ExportButton.vue';
import DataTable from '@/Components/ui/DataTable.vue';
import FilterBar from '@/Components/ui/FilterBar.vue';
import Pagination from '@/Components/ui/Pagination.vue';
import Button from '@/Components/ui/Button.vue';
import Select from '@/Components/ui/Select.vue';
import Quantity from '@/Components/ui/Quantity.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import { useListQuery } from '@/composables/useListQuery';
import { multiplyDecimals } from '@/Support/decimal';

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
    warehouses: {
        type: Array,
        default: () => [],
    },
});

const { search, filters, setSearch, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/inventory/stock-balances', {
    defaults: {
        search: props.items.query?.search || '',
        perPage: props.items.meta?.per_page || 15,
        filters: {
            warehouse_id: props.items.query?.filter?.warehouse_id || '',
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

const columns = [
    { key: 'sku', label: 'SKU', sortable: false, class: 'whitespace-nowrap font-mono text-xs' },
    { key: 'product_name', label: 'Nama Produk', sortable: false },
    { key: 'category', label: 'Kategori', sortable: false },
    { key: 'base_unit', label: 'Satuan', sortable: false },
    { key: 'warehouse', label: 'Gudang', sortable: false },
    { key: 'quantity', label: 'Saldo Fisik', sortable: false, align: 'right' },
    { key: 'avg_cost', label: 'Biaya Rata-rata', sortable: false, align: 'right' },
    { key: 'total_value', label: 'Nilai Total', sortable: false, align: 'right' },
    { key: 'actions', label: 'Aksi', sortable: false, align: 'right' },
];

const hasActiveFilters = computed(() => {
    return Boolean(filters.value.warehouse_id);
});
</script>

<template>
    <AppLayout title="Saldo Stok">
        <Head title="Saldo Stok" />

        <div class="space-y-6">
            <PageHeader
                title="Saldo Stok"
                description="Informasi kuantitas fisik dan nilai saldo persediaan per gudang secara waktu nyata."
            >
                <template #actions>
                    <ExportButton url="/inventory/stock-balances" :query="items.query ?? {}" />
                </template>
            </PageHeader>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
                <FilterBar
                    :search="search"
                    :has-active-filters="hasActiveFilters"
                    search-placeholder="Cari SKU atau nama produk..."
                    @update:search="setSearch"
                    @reset="resetFilters"
                >
                    <div class="w-48 sm:w-56">
                        <Select
                            :model-value="filters.warehouse_id || ''"
                            :options="warehouseOptions"
                            aria-label="Pilih Gudang"
                            @update:model-value="setFilter('warehouse_id', $event)"
                        />
                    </div>
                </FilterBar>

                <DataTable
                    :columns="columns"
                    :data="items.data"
                    empty-title="Tidak ada saldo persediaan"
                    empty-description="Belum ada catatan saldo produk di gudang yang dipilih atau sesuai kata kunci."
                >
                    <template #cell(sku)="{ row }">
                        <span class="font-mono text-xs font-medium text-slate-800 dark:text-slate-200">
                            {{ row.product?.sku || '-' }}
                        </span>
                    </template>

                    <template #cell(product_name)="{ row }">
                        <span class="text-sm font-medium text-slate-900 dark:text-slate-100">
                            {{ row.product?.name || '-' }}
                        </span>
                    </template>

                    <template #cell(category)="{ row }">
                        <span class="text-xs text-slate-600 dark:text-slate-400">
                            {{ row.product?.category?.name || '-' }}
                        </span>
                    </template>

                    <template #cell(base_unit)="{ row }">
                        <span class="text-xs font-mono text-slate-600 dark:text-slate-400">
                            {{ row.product?.base_unit?.code || '-' }}
                        </span>
                    </template>

                    <template #cell(warehouse)="{ row }">
                        <span class="text-xs text-slate-700 dark:text-slate-300">
                            {{ row.warehouse?.name || '-' }}
                        </span>
                    </template>

                    <template #cell(quantity)="{ row }">
                        <Quantity :value="row.quantity" :unit="row.product?.base_unit?.code || ''" />
                    </template>

                    <template #cell(avg_cost)="{ row }">
                        <Rupiah :value="row.avg_cost" />
                    </template>

                    <template #cell(total_value)="{ row }">
                        <Rupiah :value="multiplyDecimals(row.quantity, row.avg_cost)" />
                    </template>

                    <template #cell(actions)="{ row }">
                        <div class="flex items-center justify-end">
                            <Link
                                :href="`/inventory/stock-card?product_id=${row.product_id}&warehouse_id=${row.warehouse_id}`"
                            >
                                <Button variant="secondary" size="sm">
                                    <BookOpen class="w-3.5 h-3.5 mr-1.5" aria-hidden="true" />
                                    Kartu Stok
                                </Button>
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
