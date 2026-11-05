<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/ui/Card.vue';
import Checkbox from '@/Components/ui/Checkbox.vue';
import FormField from '@/Components/ui/FormField.vue';
import Pagination from '@/Components/ui/Pagination.vue';
import Select from '@/Components/ui/Select.vue';
import ReportFilterBar from '@/Components/reports/ReportFilterBar.vue';
import ReportHeader from '@/Components/reports/ReportHeader.vue';
import ReportTable from '@/Components/reports/ReportTable.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
    meta: { type: Object, default: null },
    filters: { type: Object, required: true },
    warehouses: { type: Array, default: () => [] },
});

const url = '/reports/inventory-summary';

const warehouseId = ref(props.filters.warehouse_id ? String(props.filters.warehouse_id) : '');
const belowMinimum = ref(Boolean(props.filters.below_minimum));

const warehouseOptions = computed(() => [
    { value: '', label: 'Semua Gudang' },
    ...props.warehouses.map((warehouse) => ({ value: String(warehouse.id), label: `${warehouse.code} - ${warehouse.name}` })),
]);

const columns = [
    { key: 'warehouse_name', label: 'Gudang' },
    { key: 'sku', label: 'SKU' },
    { key: 'product_name', label: 'Produk' },
    { key: 'quantity', label: 'Stok', type: 'quantity' },
    { key: 'min_stock', label: 'Minimum', type: 'quantity' },
    { key: 'below_minimum', label: 'Status' },
];

const activeQuery = computed(() => ({
    warehouse_id: props.filters.warehouse_id,
    below_minimum: props.filters.below_minimum,
}));

function visit(extra) {
    const query = { per_page: props.filters.per_page, ...extra };

    if (props.filters.warehouse_id) {
        query.warehouse_id = props.filters.warehouse_id;
    }

    if (props.filters.below_minimum) {
        query.below_minimum = 1;
    }

    router.get(url, query, { preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
    <AppLayout title="Ringkasan Persediaan">
        <Head title="Ringkasan Persediaan" />

        <div class="report-page space-y-6">
            <ReportHeader
                title="Ringkasan Persediaan"
                description="Stok saat ini per gudang dan produk, tanpa nilai biaya."
                :export-url="url"
                :export-query="activeQuery"
            />

            <ReportFilterBar
                v-slot="{ errors }"
                :url="url"
                :filters="filters"
                mode="none"
                :params="{ warehouse_id: warehouseId, below_minimum: belowMinimum, per_page: filters.per_page }"
            >
                <FormField v-slot="{ id, invalid }" label="Gudang" :error="errors.warehouse_id" class="sm:w-72">
                    <Select :id="id" v-model="warehouseId" :options="warehouseOptions" :invalid="invalid" />
                </FormField>
                <div class="sm:pt-8">
                    <Checkbox v-model="belowMinimum" label="Hanya di bawah minimum" />
                </div>
            </ReportFilterBar>

            <Card class="report-section">
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Stok</h2>
                        <p class="text-sm text-slate-600 dark:text-slate-300" data-test="counts">
                            {{ summary.product_count }} produk di {{ summary.warehouse_count }} gudang, {{ summary.below_minimum_count }} di bawah minimum
                        </p>
                    </div>
                </template>

                <ReportTable
                    :columns="columns"
                    :rows="rows"
                    empty-title="Tidak ada stok"
                    empty-description="Tidak ada saldo stok yang cocok dengan filter ini."
                >
                    <template #cell-sku="{ row }">
                        <span class="font-mono text-xs">{{ row.sku }}</span>
                    </template>
                    <template #cell-below_minimum="{ row }">
                        <span v-if="row.below_minimum" class="inline-flex items-center gap-1 font-medium text-rose-700 dark:text-rose-400">
                            <AlertTriangle class="h-4 w-4" aria-hidden="true" />
                            Di bawah minimum
                        </span>
                        <template v-else>Aman</template>
                    </template>
                </ReportTable>

                <template v-if="meta && meta.total > 0" #footer>
                    <div class="print:hidden">
                        <Pagination
                            :meta="meta"
                            :per-page="filters.per_page"
                            :per-page-options="[25, 50, 100]"
                            @change-page="visit({ page: $event })"
                            @change-per-page="visit({ per_page: $event })"
                        />
                    </div>
                </template>
            </Card>
        </div>
    </AppLayout>
</template>
