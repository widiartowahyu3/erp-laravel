<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Filter, RotateCcw, BookOpen } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import FormField from '@/Components/ui/FormField.vue';
import Select from '@/Components/ui/Select.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import Quantity from '@/Components/ui/Quantity.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import Pagination from '@/Components/ui/Pagination.vue';
import { stockMovementTypeConfig } from '@/config/inventory';
import { multiplyDecimals } from '@/Support/decimal';

const props = defineProps({
    filters: {
        type: Object,
        default: () => ({
            product_id: null,
            warehouse_id: null,
            date_from: '',
            date_to: '',
        }),
    },
    opening: {
        type: Object,
        default: null,
    },
    rows: {
        type: Object,
        default: null,
    },
    current: {
        type: Object,
        default: null,
    },
    products: {
        type: Array,
        default: () => [],
    },
    warehouses: {
        type: Array,
        default: () => [],
    },
});

const formFilters = ref({
    product_id: props.filters.product_id ? String(props.filters.product_id) : '',
    warehouse_id: props.filters.warehouse_id ? String(props.filters.warehouse_id) : '',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
});

const selectedProduct = computed(() => {
    if (!props.filters.product_id) return null;
    return props.products.find((p) => String(p.id) === String(props.filters.product_id)) || null;
});

const selectedWarehouse = computed(() => {
    if (!props.filters.warehouse_id) return null;
    return props.warehouses.find((w) => String(w.id) === String(props.filters.warehouse_id)) || null;
});

const productOptions = props.products.map((p) => ({
    value: String(p.id),
    label: `${p.sku} - ${p.name}`,
}));

const warehouseOptions = props.warehouses.map((w) => ({
    value: String(w.id),
    label: `${w.code} - ${w.name}`,
}));

function applyFilter() {
    router.get('/inventory/stock-card', {
        product_id: formFilters.value.product_id || undefined,
        warehouse_id: formFilters.value.warehouse_id || undefined,
        date_from: formFilters.value.date_from || undefined,
        date_to: formFilters.value.date_to || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetFilter() {
    formFilters.value = {
        product_id: '',
        warehouse_id: '',
        date_from: '',
        date_to: '',
    };
    router.get('/inventory/stock-card', {}, {
        preserveState: true,
        preserveScroll: true,
    });
}

function setPage(pageNum) {
    router.get('/inventory/stock-card', {
        ...props.filters,
        page: pageNum,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function setPerPage(perPage) {
    router.get('/inventory/stock-card', {
        ...props.filters,
        per_page: perPage,
        page: 1,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <AppLayout title="Kartu Stok">
        <Head title="Kartu Stok" />

        <div class="space-y-6">
            <PageHeader
                title="Kartu Stok"
                description="Kartu persediaan fisik barang per gudang dengan rincian saldo awal, mutasi masuk/keluar, dan saldo akhir."
            />

            <!-- Filter Card -->
            <Card>
                <form class="space-y-4" @submit.prevent="applyFilter">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <FormField
                            id="stock-card-product"
                            label="Produk"
                            required
                        >
                            <template #default="{ id, describedBy }">
                                <Select
                                    :id="id"
                                    v-model="formFilters.product_id"
                                    :options="productOptions"
                                    placeholder="Pilih Produk..."
                                    :aria-describedby="describedBy"
                                    required
                                />
                            </template>
                        </FormField>

                        <FormField
                            id="stock-card-warehouse"
                            label="Gudang"
                            required
                        >
                            <template #default="{ id, describedBy }">
                                <Select
                                    :id="id"
                                    v-model="formFilters.warehouse_id"
                                    :options="warehouseOptions"
                                    placeholder="Pilih Gudang..."
                                    :aria-describedby="describedBy"
                                    required
                                />
                            </template>
                        </FormField>

                        <FormField
                            id="stock-card-date-from"
                            label="Tanggal Dari"
                        >
                            <template #default="{ id, describedBy }">
                                <TextInput
                                    :id="id"
                                    v-model="formFilters.date_from"
                                    type="date"
                                    :aria-describedby="describedBy"
                                />
                            </template>
                        </FormField>

                        <FormField
                            id="stock-card-date-to"
                            label="Tanggal Sampai"
                        >
                            <template #default="{ id, describedBy }">
                                <TextInput
                                    :id="id"
                                    v-model="formFilters.date_to"
                                    type="date"
                                    :aria-describedby="describedBy"
                                />
                            </template>
                        </FormField>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            @click="resetFilter"
                        >
                            <RotateCcw class="w-3.5 h-3.5 mr-1.5" aria-hidden="true" />
                            Reset
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            size="sm"
                        >
                            <Filter class="w-3.5 h-3.5 mr-1.5" aria-hidden="true" />
                            Tampilkan Kartu Stok
                        </Button>
                    </div>
                </form>
            </Card>

            <!-- When Product & Warehouse are Selected -->
            <div v-if="selectedProduct && selectedWarehouse && opening && current" class="space-y-6">
                <!-- Summary Metrics Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <Card class="bg-slate-50/50 dark:bg-slate-800/40">
                        <div class="text-xs font-medium text-slate-500 dark:text-slate-400">Saldo Awal</div>
                        <div class="mt-1 text-xl font-bold text-slate-900 dark:text-slate-100">
                            <Quantity :value="opening.quantity" />
                        </div>
                        <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Nilai: <Rupiah :value="multiplyDecimals(opening.quantity, opening.avg_cost)" />
                        </div>
                    </Card>

                    <Card class="bg-indigo-50/30 dark:bg-indigo-950/20 border-indigo-200 dark:border-indigo-800/40">
                        <div class="text-xs font-medium text-indigo-700 dark:text-indigo-400">Saldo Akhir Fisik</div>
                        <div class="mt-1 text-xl font-bold text-indigo-700 dark:text-indigo-400">
                            <Quantity :value="current.quantity" />
                        </div>
                        <div class="mt-1 text-xs text-indigo-600 dark:text-indigo-500">
                            Nilai: <Rupiah :value="multiplyDecimals(current.quantity, current.avg_cost)" />
                        </div>
                    </Card>

                    <Card class="bg-slate-50/50 dark:bg-slate-800/40">
                        <div class="text-xs font-medium text-slate-500 dark:text-slate-400">HPP Rata-rata Berjalan</div>
                        <div class="mt-1 text-xl font-bold text-slate-900 dark:text-slate-100">
                            <Rupiah :value="current.avg_cost" />
                        </div>
                        <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Metode: Moving Average
                        </div>
                    </Card>
                </div>

                <!-- Ledger Table -->
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Buku Pembantu Mutasi Persediaan: {{ selectedProduct.sku }} - {{ selectedProduct.name }}
                        </h3>
                        <span class="text-xs text-slate-500 dark:text-slate-400">
                            Lokasi: {{ selectedWarehouse.name }}
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-800/75 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-300 uppercase tracking-wider">
                                <tr>
                                    <th class="py-3 px-4 whitespace-nowrap">Waktu</th>
                                    <th class="py-3 px-4 whitespace-nowrap">No. Dokumen</th>
                                    <th class="py-3 px-4 whitespace-nowrap">Tipe</th>
                                    <th class="py-3 px-4 text-right whitespace-nowrap">Masuk</th>
                                    <th class="py-3 px-4 text-right whitespace-nowrap">Keluar</th>
                                    <th class="py-3 px-4 text-right whitespace-nowrap">Biaya Satuan</th>
                                    <th class="py-3 px-4 text-right whitespace-nowrap">Saldo Akhir</th>
                                    <th class="py-3 px-4 text-right whitespace-nowrap">HPP Rata-rata</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60">
                                <tr v-if="!rows || !rows.data || rows.data.length === 0">
                                    <td colspan="8" class="py-8 text-center text-sm text-slate-500 dark:text-slate-400">
                                        Tidak ada mutasi dalam rentang tanggal ini.
                                    </td>
                                </tr>
                                <tr
                                    v-for="(row, idx) in (rows ? rows.data : [])"
                                    :key="idx"
                                    class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors"
                                >
                                    <td class="py-3 px-4 whitespace-nowrap text-xs">
                                        <DateDisplay :value="row.movement_date" format="datetime" />
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap font-mono text-xs">
                                        <Link
                                            v-if="row.source_url"
                                            :href="row.source_url"
                                            class="font-medium text-indigo-600 dark:text-indigo-400 hover:underline"
                                        >
                                            {{ row.document_number || '-' }}
                                        </Link>
                                        <span v-else class="text-slate-700 dark:text-slate-300">
                                            {{ row.document_number || '-' }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <Badge
                                            :variant="stockMovementTypeConfig[row.movement_type]?.variant || 'neutral'"
                                            size="sm"
                                        >
                                            {{ stockMovementTypeConfig[row.movement_type]?.label || row.movement_type }}
                                        </Badge>
                                    </td>
                                    <td class="py-3 px-4 text-right whitespace-nowrap text-emerald-600 dark:text-emerald-400 font-medium">
                                        <span v-if="row.in && row.in !== '0.0000'">
                                            +<Quantity :value="row.in" />
                                        </span>
                                        <span v-else class="text-slate-400">-</span>
                                    </td>
                                    <td class="py-3 px-4 text-right whitespace-nowrap text-rose-600 dark:text-rose-400 font-medium">
                                        <span v-if="row.out && row.out !== '0.0000'">
                                            -<Quantity :value="row.out" />
                                        </span>
                                        <span v-else class="text-slate-400">-</span>
                                    </td>
                                    <td class="py-3 px-4 text-right whitespace-nowrap">
                                        <Rupiah :value="row.unit_cost" />
                                    </td>
                                    <td class="py-3 px-4 text-right whitespace-nowrap font-medium text-slate-900 dark:text-slate-100">
                                        <Quantity :value="row.balance_after" />
                                    </td>
                                    <td class="py-3 px-4 text-right whitespace-nowrap">
                                        <Rupiah :value="row.avg_cost_after" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <Pagination
                        v-if="rows && rows.meta"
                        :meta="rows.meta"
                        @change-page="setPage"
                        @change-per-page="setPerPage"
                    />
                </div>
            </div>

            <!-- Empty Prompt when nothing selected -->
            <Card v-else class="py-12">
                <EmptyState
                    title="Pilih Produk dan Gudang"
                    description="Silakan tentukan produk dan fasilitas gudang di atas untuk menampilkan rincian kartu stok persediaan."
                >
                    <template #icon>
                        <BookOpen class="w-12 h-12 text-slate-400 mx-auto" aria-hidden="true" />
                    </template>
                </EmptyState>
            </Card>
        </div>
    </AppLayout>
</template>
