<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import DataTable from '@/Components/ui/DataTable.vue';
import FilterBar from '@/Components/ui/FilterBar.vue';
import Button from '@/Components/ui/Button.vue';
import Alert from '@/Components/ui/Alert.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import StatusBadge from '@/Components/ui/StatusBadge.vue';
import InvoiceForm from './Form.vue';

const props = defineProps({
    salesOrder: {
        type: Object,
        default: null,
    },
    eligibleItems: {
        type: Array,
        default: () => [],
    },
    defaults: {
        type: Object,
        default: null,
    },
    billableOrders: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({ search: '' }),
    },
});

const page = usePage();
const flashError = computed(() => page.props.flash?.error || null);

const search = ref(props.filters?.search || '');

const breadcrumbs = computed(() => [
    { label: 'Faktur Penjualan (SI)', href: '/sales/invoices' },
    { label: props.salesOrder ? `Buat dari ${props.salesOrder.number}` : 'Pilih Pesanan' },
]);

const columns = [
    { key: 'number', label: 'No. SO', sortable: false, class: 'whitespace-nowrap font-mono text-xs' },
    { key: 'orderDate', label: 'Tanggal', sortable: false, class: 'whitespace-nowrap text-xs' },
    { key: 'customer', label: 'Pelanggan', sortable: false },
    { key: 'total', label: 'Total SO', sortable: false, align: 'right' },
    { key: 'status', label: 'Status', sortable: false, align: 'center' },
    { key: 'actions', label: 'Aksi', sortable: false, align: 'right' },
];

function runSearch(value) {
    search.value = value;

    router.get('/sales/invoices/create', value ? { search: value } : {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}
</script>

<template>
    <AppLayout title="Buat Faktur Penjualan">
        <Head title="Buat Faktur Penjualan" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <Alert v-if="flashError" variant="danger">
                {{ flashError }}
            </Alert>

            <template v-if="salesOrder">
                <PageHeader
                    :title="`Buat Faktur: ${salesOrder.number}`"
                    description="Pilih barang dari surat jalan yang sudah diposting."
                />

                <InvoiceForm
                    :sales-order="salesOrder"
                    :eligible-items="eligibleItems"
                    :initial="{ invoiceDate: defaults?.invoiceDate, dueDate: defaults?.dueDate, notes: '' }"
                />
            </template>

            <template v-else>
                <PageHeader
                    title="Buat Faktur Penjualan"
                    description="Pilih pesanan penjualan yang masih memiliki barang terkirim dan belum ditagih."
                />

                <FilterBar
                    :search="search"
                    search-placeholder="Cari nomor SO atau pelanggan..."
                    :has-active-filters="false"
                    @update:search="runSearch"
                    @reset="runSearch('')"
                />

                <DataTable
                    :columns="columns"
                    :data="billableOrders"
                    empty-title="Tidak ada pesanan yang bisa ditagih"
                    empty-description="Faktur hanya dapat dibuat dari surat jalan yang sudah diposting dan belum ditagih penuh."
                >
                    <template #cell(number)="{ row }">
                        <Link
                            :href="`/sales/sales-orders/${row.id}`"
                            class="text-indigo-600 dark:text-indigo-400 font-semibold hover:underline"
                        >
                            {{ row.number }}
                        </Link>
                    </template>

                    <template #cell(orderDate)="{ row }">
                        <DateDisplay :value="row.orderDate" />
                    </template>

                    <template #cell(customer)="{ row }">
                        {{ row.customer?.name || '-' }}
                    </template>

                    <template #cell(total)="{ row }">
                        <Rupiah :value="row.total" />
                    </template>

                    <template #cell(status)="{ row }">
                        <StatusBadge :status="row.status" />
                    </template>

                    <template #cell(actions)="{ row }">
                        <Link :href="`/sales/invoices/create?sales_order_id=${row.id}`">
                            <Button variant="primary" size="sm">Buat Faktur</Button>
                        </Link>
                    </template>
                </DataTable>
            </template>
        </div>
    </AppLayout>
</template>
