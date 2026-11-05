<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Edit, CheckCircle2, Truck, Lock, Ban, ArrowLeft, FileText } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Can from '@/Components/ui/Can.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import StatusBadge from '@/Components/ui/StatusBadge.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import Quantity from '@/Components/ui/Quantity.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import Alert from '@/Components/ui/Alert.vue';
import { useActionGuard } from '@/composables/useActionGuard';
import { useSubmitLock } from '@/composables/useSubmitLock';
import { useConfirm } from '@/composables/useConfirm';
import { subtractDecimals } from '@/Support/decimal';

const props = defineProps({
    order: {
        type: Object,
        default: null,
    },
    item: {
        type: Object,
        default: null,
    },
    hasBillableItems: {
        type: Boolean,
        default: false,
    },
});

const salesOrder = computed(() => props.order || props.item || {});

const page = usePage();
const {
    canEditSalesOrder,
    canApproveSalesOrder,
    canVoidSalesOrder,
    canCloseSalesOrder,
    canCreateDOFromSO,
} = useActionGuard();

const { confirm } = useConfirm();
const { busy, submit } = useSubmitLock();

const breadcrumbs = computed(() => [
    { label: 'Pesanan Penjualan', href: '/sales/sales-orders' },
    { label: salesOrder.value.number || 'Detail' },
]);

const flashError = computed(() => page.props.flash?.error || null);
const flashSuccess = computed(() => page.props.flash?.success || null);
const pageErrors = computed(() => page.props.errors || {});

async function handleApprove() {
    if (busy.value) return;

    const confirmed = await confirm({
        title: 'Setujui Pesanan Penjualan',
        message: `Apakah Anda yakin ingin menyetujui pesanan penjualan "${salesOrder.value.number}"? Dokumen yang disetujui dapat dibuatkan surat jalan pengiriman.`,
        confirmText: 'Ya, Setujui',
        variant: 'primary',
    });

    if (confirmed) {
        submit((options) => {
            router.post(`/sales/sales-orders/${salesOrder.value.id}/approve`, {}, {
                preserveScroll: true,
                ...options,
            });
        });
    }
}

async function handleClose() {
    if (busy.value) return;

    const confirmed = await confirm({
        title: 'Tutup Pesanan Penjualan',
        message: `Tutup dokumen "${salesOrder.value.number}"? Pesanan yang ditutup tidak dapat dibuatkan surat jalan baru lagi.`,
        confirmText: 'Ya, Tutup Dokumen',
        variant: 'warning',
    });

    if (confirmed) {
        submit((options) => {
            router.post(`/sales/sales-orders/${salesOrder.value.id}/close`, {}, {
                preserveScroll: true,
                ...options,
            });
        });
    }
}

async function handleVoid() {
    if (busy.value) return;

    const res = await confirm({
        title: 'Batalkan (Void) Pesanan Penjualan',
        message: `Masukkan alasan pembatalan dokumen "${salesOrder.value.number}". Tindakan ini bersifat permanen.`,
        confirmText: 'Ya, Batalkan Dokumen',
        variant: 'danger',
        requiresReason: true,
        reasonLabel: 'Alasan Pembatalan (min. 5 karakter)',
        reasonPlaceholder: 'Contoh: Pembeli membatalkan pesanan / kesalahan data...',
    });

    if (res && res.confirmed) {
        submit((options) => {
            router.post(`/sales/sales-orders/${salesOrder.value.id}/void`, {
                reason: res.reason,
            }, {
                preserveScroll: true,
                ...options,
            });
        });
    }
}
</script>

<template>
    <AppLayout :title="`Detail SO: ${salesOrder.number}`">
        <Head :title="`Detail SO: ${salesOrder.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Flash & Inline Alerts -->
            <Alert v-if="flashError" variant="danger">
                {{ flashError }}
            </Alert>
            <Alert v-if="flashSuccess" variant="success">
                {{ flashSuccess }}
            </Alert>
            <Alert v-if="pageErrors.customer_id" variant="danger">
                {{ pageErrors.customer_id }}
            </Alert>
            <Alert v-if="pageErrors.status" variant="danger">
                {{ pageErrors.status }}
            </Alert>
            <Alert v-if="pageErrors.form" variant="danger">
                {{ pageErrors.form }}
            </Alert>

            <PageHeader
                :title="salesOrder.number"
                :description="`Dokumen pesanan penjualan untuk pelanggan ${salesOrder.customer?.name || ''}`"
            >
                <template #actions>
                    <div class="flex items-center gap-2">
                        <Link href="/sales/sales-orders">
                            <Button variant="secondary" size="sm">
                                <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Kembali
                            </Button>
                        </Link>

                        <Link
                            v-if="canEditSalesOrder(salesOrder)"
                            :href="`/sales/sales-orders/${salesOrder.id}/edit`"
                        >
                            <Button variant="secondary" size="sm">
                                <Edit class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Edit Draf
                            </Button>
                        </Link>

                        <Button
                            v-if="canApproveSalesOrder(salesOrder)"
                            variant="primary"
                            size="sm"
                            :loading="busy"
                            :disabled="busy"
                            :aria-busy="busy"
                            @click="handleApprove"
                        >
                            <CheckCircle2 class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Setujui SO
                        </Button>

                        <Link
                            v-if="canCreateDOFromSO(salesOrder)"
                            :href="`/sales/delivery-orders/create?sales_order_id=${salesOrder.id}`"
                        >
                            <Button variant="primary" size="sm">
                                <Truck class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Buat Surat Jalan
                            </Button>
                        </Link>

                        <Can v-if="hasBillableItems" permission="sales_invoices.create">
                            <Link :href="`/sales/invoices/create?sales_order_id=${salesOrder.id}`">
                                <Button variant="primary" size="sm">
                                    <FileText class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                    Buat Faktur
                                </Button>
                            </Link>
                        </Can>

                        <Button
                            v-if="canCloseSalesOrder(salesOrder)"
                            variant="secondary"
                            size="sm"
                            :loading="busy"
                            :disabled="busy"
                            :aria-busy="busy"
                            @click="handleClose"
                        >
                            <Lock class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Tutup SO
                        </Button>

                        <Button
                            v-if="canVoidSalesOrder(salesOrder)"
                            variant="danger"
                            size="sm"
                            :loading="busy"
                            :disabled="busy"
                            :aria-busy="busy"
                            @click="handleVoid"
                        >
                            <Ban class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Batalkan SO
                        </Button>
                    </div>
                </template>
            </PageHeader>

            <!-- Informasi Utama -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <Card class="md:col-span-2">
                    <template #header>
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Informasi Pesanan
                        </h2>
                    </template>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Pelanggan:</span>
                            <span class="font-medium text-slate-800 dark:text-slate-200">
                                {{ salesOrder.customer?.code }} - {{ salesOrder.customer?.name }}
                            </span>
                            <div v-if="salesOrder.customer?.address" class="text-xs text-slate-500 mt-1">
                                {{ salesOrder.customer.address }}
                            </div>
                        </div>

                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Gudang Pengiriman:</span>
                            <span class="font-medium text-slate-800 dark:text-slate-200">
                                {{ salesOrder.warehouse?.code }} - {{ salesOrder.warehouse?.name }}
                            </span>
                        </div>

                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Tanggal Pesanan:</span>
                            <span class="font-medium text-slate-800 dark:text-slate-200">
                                <DateDisplay :value="salesOrder.order_date" />
                            </span>
                        </div>

                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Syarat Pembayaran:</span>
                            <span class="font-medium text-slate-800 dark:text-slate-200">
                                {{ salesOrder.payment_term ? `${salesOrder.payment_term.code} - ${salesOrder.payment_term.name} (${salesOrder.payment_term.days} hari)` : 'COD / Tunai' }}
                            </span>
                        </div>

                        <div v-if="salesOrder.notes" class="sm:col-span-2">
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Catatan:</span>
                            <span class="text-slate-700 dark:text-slate-300 whitespace-pre-line text-xs">
                                {{ salesOrder.notes }}
                            </span>
                        </div>
                    </div>
                </Card>

                <Card>
                    <template #header>
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Status & Jejak Dokumen
                        </h2>
                    </template>
                    <div class="space-y-3 text-sm">
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block mb-1">Status:</span>
                            <StatusBadge :status="salesOrder.status" />
                        </div>

                        <div class="pt-2 border-t border-slate-100 dark:border-slate-800 text-xs space-y-1.5 text-slate-600 dark:text-slate-400">
                            <div>
                                <span class="text-slate-500">Dibuat oleh:</span>
                                <span class="font-medium text-slate-700 dark:text-slate-300 ml-1">
                                    {{ salesOrder.creator?.name || '-' }}
                                </span>
                            </div>

                            <div v-if="salesOrder.approver">
                                <span class="text-slate-500">Disetujui oleh:</span>
                                <span class="font-medium text-slate-700 dark:text-slate-300 ml-1">
                                    {{ salesOrder.approver.name }}
                                </span>
                            </div>

                            <div v-if="salesOrder.voider">
                                <span class="text-slate-500">Dibatalkan oleh:</span>
                                <span class="font-medium text-rose-600 ml-1">
                                    {{ salesOrder.voider.name }}
                                </span>
                                <div v-if="salesOrder.void_reason" class="text-rose-500 italic mt-0.5">
                                    "{{ salesOrder.void_reason }}"
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Tabel Barang -->
            <Card>
                <template #header>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                        Daftar Barang yang Dipesan
                    </h2>
                </template>

                <div class="overflow-x-auto -mx-4 sm:mx-0">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-400">
                                <th class="py-2.5 px-3 w-10 text-center">No</th>
                                <th class="py-2.5 px-3">Produk</th>
                                <th class="py-2.5 px-3 text-right">Dipesan</th>
                                <th class="py-2.5 px-3 text-right">Terkirim</th>
                                <th class="py-2.5 px-3 text-right">Sisa</th>
                                <th class="py-2.5 px-3 text-right">Harga Satuan</th>
                                <th class="py-2.5 px-3 text-right">Diskon</th>
                                <th class="py-2.5 px-3">Pajak</th>
                                <th class="py-2.5 px-3 text-right">Total Baris</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr
                                v-for="(it, idx) in salesOrder.items"
                                :key="it.id || idx"
                                class="align-top hover:bg-slate-50/50 dark:hover:bg-slate-800/50"
                            >
                                <td class="py-2.5 px-3 text-center text-xs text-slate-500">
                                    {{ idx + 1 }}
                                </td>
                                <td class="py-2.5 px-3">
                                    <div class="font-medium text-slate-800 dark:text-slate-200">
                                        {{ it.product?.name }}
                                    </div>
                                    <div class="text-xs text-slate-500 font-mono">
                                        {{ it.product?.sku }}
                                        <span v-if="it.product?.base_unit">({{ it.product.base_unit.code }})</span>
                                    </div>
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono text-xs text-slate-700 dark:text-slate-300">
                                    <Quantity :value="it.quantity" />
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono text-xs text-emerald-600 dark:text-emerald-400 font-medium">
                                    <Quantity :value="it.delivered_quantity" />
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono text-xs text-slate-700 dark:text-slate-300">
                                    <Quantity :value="subtractDecimals(it.quantity, it.delivered_quantity)" />
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono text-xs text-slate-700 dark:text-slate-300">
                                    <Rupiah :amount="it.unit_price" />
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono text-xs text-slate-700 dark:text-slate-300">
                                    <Rupiah :amount="it.discount_amount" />
                                </td>
                                <td class="py-2.5 px-3 text-xs text-slate-600 dark:text-slate-400">
                                    {{ it.tax_rate ? `${it.tax_rate.code} (${it.tax_rate.rate}%)` : '-' }}
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono text-xs font-semibold text-slate-800 dark:text-slate-200">
                                    <Rupiah :amount="it.line_total" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Ringkasan Finansial -->
                <div class="mt-4 flex justify-end pt-3 border-t border-slate-100 dark:border-slate-800">
                    <div class="w-72 space-y-2 text-sm bg-slate-50 dark:bg-slate-900/50 p-4 rounded-lg border border-slate-200 dark:border-slate-800">
                        <div class="flex justify-between text-xs text-slate-600 dark:text-slate-400">
                            <span>Subtotal:</span>
                            <span class="font-mono font-medium text-slate-800 dark:text-slate-200">
                                <Rupiah :amount="salesOrder.subtotal" />
                            </span>
                        </div>
                        <div class="flex justify-between text-xs text-slate-600 dark:text-slate-400">
                            <span>Total Diskon:</span>
                            <span class="font-mono font-medium text-slate-800 dark:text-slate-200">
                                <Rupiah :amount="salesOrder.discount_amount" />
                            </span>
                        </div>
                        <div class="flex justify-between text-xs text-slate-600 dark:text-slate-400">
                            <span>Total Pajak:</span>
                            <span class="font-mono font-medium text-slate-800 dark:text-slate-200">
                                <Rupiah :amount="salesOrder.tax_amount" />
                            </span>
                        </div>
                        <div class="flex justify-between text-sm font-bold text-slate-900 dark:text-slate-100 pt-2 border-t border-slate-200 dark:border-slate-700">
                            <span>Total Akhir:</span>
                            <span class="font-mono text-indigo-600 dark:text-indigo-400">
                                <Rupiah :amount="salesOrder.total" />
                            </span>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Riwayat Pengiriman (Delivery Orders) -->
            <Card>
                <template #header>
                    <div class="space-y-0.5">
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Surat Jalan Terkait
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Daftar surat jalan / bukti pengiriman yang dibuat berdasarkan pesanan ini.
                        </p>
                    </div>
                </template>

                <div v-if="salesOrder.delivery_orders && salesOrder.delivery_orders.length > 0" class="overflow-x-auto -mx-4 sm:mx-0">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-400">
                                <th class="py-2.5 px-3">No. Surat Jalan</th>
                                <th class="py-2.5 px-3">Tanggal Pengiriman</th>
                                <th class="py-2.5 px-3 text-center">Status</th>
                                <th class="py-2.5 px-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr
                                v-for="delivery in salesOrder.delivery_orders"
                                :key="delivery.id"
                                class="align-middle hover:bg-slate-50/50 dark:hover:bg-slate-800/50"
                            >
                                <td class="py-2.5 px-3 font-mono text-xs font-semibold">
                                    <Link
                                        :href="`/sales/delivery-orders/${delivery.id}`"
                                        class="text-indigo-600 dark:text-indigo-400 hover:underline"
                                    >
                                        {{ delivery.number }}
                                    </Link>
                                </td>
                                <td class="py-2.5 px-3 text-xs text-slate-700 dark:text-slate-300">
                                    <DateDisplay :value="delivery.delivery_date" />
                                </td>
                                <td class="py-2.5 px-3 text-center">
                                    <StatusBadge :status="delivery.status" />
                                </td>
                                <td class="py-2.5 px-3 text-right">
                                    <Link :href="`/sales/delivery-orders/${delivery.id}`">
                                        <Button variant="secondary" size="xs">
                                            Lihat DO
                                        </Button>
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="text-xs text-slate-500 italic py-4">
                    Belum ada surat jalan pengiriman yang dibuat untuk pesanan penjualan ini.
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
