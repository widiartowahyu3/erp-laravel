<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import DataTable from '@/Components/ui/DataTable.vue';
import FilterBar from '@/Components/ui/FilterBar.vue';
import Pagination from '@/Components/ui/Pagination.vue';
import Select from '@/Components/ui/Select.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Badge from '@/Components/ui/Badge.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import Quantity from '@/Components/ui/Quantity.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import { useListQuery } from '@/composables/useListQuery';
import { stockMovementTypeConfig } from '@/config/inventory';

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
    warehouses: {
        type: Array,
        default: () => [],
    },
    movementTypes: {
        type: Array,
        default: () => [],
    },
});

const { search, filters, setSearch, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/inventory/stock-movements', {
    defaults: {
        search: props.items.query?.search || '',
        perPage: props.items.meta?.per_page || 15,
        filters: {
            warehouse_id: props.items.query?.filter?.warehouse_id || '',
            movement_type: props.items.query?.filter?.movement_type || '',
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

const movementTypeOptions = computed(() => [
    { value: '', label: 'Semua Tipe' },
    ...props.movementTypes.map((type) => ({
        value: type,
        label: stockMovementTypeConfig[type]?.label || type,
    })),
]);

const columns = [
    { key: 'movement_date', label: 'Waktu Mutasi', sortable: false, class: 'whitespace-nowrap text-xs' },
    { key: 'document', label: 'No. Dokumen', sortable: false, class: 'whitespace-nowrap font-mono text-xs' },
    { key: 'movement_type', label: 'Tipe Mutasi', sortable: false },
    { key: 'product', label: 'SKU & Produk', sortable: false },
    { key: 'warehouse', label: 'Gudang', sortable: false },
    { key: 'quantity', label: 'Kuantitas', sortable: false, align: 'right' },
    { key: 'unit_cost', label: 'Biaya Satuan', sortable: false, align: 'right' },
    { key: 'balance_after', label: 'Saldo Akhir', sortable: false, align: 'right' },
    { key: 'avg_cost_after', label: 'HPP Akhir', sortable: false, align: 'right' },
    { key: 'created_by', label: 'Dicatat Oleh', sortable: false },
];

const hasActiveFilters = computed(() => {
    return Boolean(
        filters.value.warehouse_id ||
        filters.value.movement_type ||
        filters.value.date_from ||
        filters.value.date_to
    );
});
</script>

<template>
    <AppLayout title="Mutasi Stok">
        <Head title="Mutasi Stok" />

        <div class="space-y-6">
            <PageHeader
                title="Mutasi Stok"
                description="Buku jurnal mutasi persediaan barang masuk, keluar, dan penyesuaian fisik secara kronologis."
            />

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
                <FilterBar
                    :search="search"
                    :has-active-filters="hasActiveFilters"
                    search-placeholder="Cari SKU atau nama produk..."
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

                        <div class="w-40 sm:w-48">
                            <Select
                                :model-value="filters.movement_type || ''"
                                :options="movementTypeOptions"
                                aria-label="Filter Tipe Mutasi"
                                @update:model-value="setFilter('movement_type', $event)"
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
                    empty-title="Tidak ada catatan mutasi stok"
                    empty-description="Belum ada riwayat mutasi barang sesuai kriteria penyaringan yang ditentukan."
                >
                    <template #cell(movement_date)="{ row }">
                        <DateDisplay :value="row.movement_date" format="datetime" />
                    </template>

                    <template #cell(document)="{ row }">
                        <Link
                            v-if="row.source_url"
                            :href="row.source_url"
                            class="font-mono text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline"
                        >
                            {{ row.source_label || row.source_number || '-' }}
                        </Link>
                        <span v-else class="font-mono text-xs text-slate-700 dark:text-slate-300">
                            {{ row.source_label || row.source_number || '-' }}
                        </span>
                    </template>

                    <template #cell(movement_type)="{ row }">
                        <Badge
                            :variant="stockMovementTypeConfig[row.movement_type]?.variant || 'neutral'"
                            size="sm"
                        >
                            {{ stockMovementTypeConfig[row.movement_type]?.label || row.movement_type }}
                        </Badge>
                    </template>

                    <template #cell(product)="{ row }">
                        <div>
                            <span class="font-mono text-xs text-slate-500 dark:text-slate-400 block">
                                {{ row.product?.sku || '-' }}
                            </span>
                            <span class="text-sm font-medium text-slate-900 dark:text-slate-100">
                                {{ row.product?.name || '-' }}
                            </span>
                        </div>
                    </template>

                    <template #cell(warehouse)="{ row }">
                        <span class="text-xs text-slate-700 dark:text-slate-300">
                            {{ row.warehouse?.name || '-' }}
                        </span>
                    </template>

                    <template #cell(quantity)="{ row }">
                        <span
                            :class="[
                                'font-medium',
                                String(row.quantity).startsWith('-')
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : 'text-emerald-600 dark:text-emerald-400'
                            ]"
                        >
                            <span v-if="!String(row.quantity).startsWith('-')">+</span>
                            <Quantity :value="row.quantity" :unit="row.product?.base_unit?.code || ''" />
                        </span>
                    </template>

                    <template #cell(unit_cost)="{ row }">
                        <Rupiah :value="row.unit_cost" />
                    </template>

                    <template #cell(balance_after)="{ row }">
                        <Quantity :value="row.balance_after" :unit="row.product?.base_unit?.code || ''" />
                    </template>

                    <template #cell(avg_cost_after)="{ row }">
                        <Rupiah :value="row.avg_cost_after" />
                    </template>

                    <template #cell(created_by)="{ row }">
                        <span class="text-xs text-slate-600 dark:text-slate-400">
                            {{ row.creator?.name || 'Sistem' }}
                        </span>
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
