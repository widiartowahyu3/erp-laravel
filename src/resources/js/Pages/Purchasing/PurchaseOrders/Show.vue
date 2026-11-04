<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Edit, CheckCircle2, PackageCheck, Lock, Ban, ArrowLeft } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
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
import { purchaseOrderStatusConfig, goodsReceiptStatusConfig } from '@/config/purchasing';
import { subtractDecimals } from '@/Support/decimal';

const props = defineProps({
    item: {
        type: Object,
        default: null,
    },
    order: {
        type: Object,
        default: null,
    },
});

const order = computed(() => props.order || props.item);

const page = usePage();
const {
    canEditPurchaseOrder,
    canApprovePurchaseOrder,
    canVoidPurchaseOrder,
    canClosePurchaseOrder,
    canCreateReceiptFromPO,
} = useActionGuard();

const { confirm } = useConfirm();
const { busy, submit } = useSubmitLock();

const breadcrumbs = [
    { label: 'Pesanan Pembelian', href: '/purchasing/purchase-orders' },
    { label: order.value.number },
];

const flashError = computed(() => page.props.flash?.error || null);
const flashSuccess = computed(() => page.props.flash?.success || null);
const pageErrors = computed(() => page.props.errors || {});

async function handleApprove() {
    if (busy.value) return;

    const ok = await confirm({
        title: 'Setujui Pesanan Pembelian',
        message: `Apakah Anda yakin ingin menyetujui pesanan pembelian "${order.value.number}"? Dokumen yang disetujui dapat mulai diproses penerimaan barangnya di gudang.`,
        confirmText: 'Ya, Setujui PO',
        variant: 'primary',
    });

    if (ok) {
        submit((options) => {
            router.post(`/purchasing/purchase-orders/${order.value.id}/approve`, {}, {
                preserveScroll: true,
                ...options,
            });
        });
    }
}

async function handleClose() {
    if (busy.value) return;

    const ok = await confirm({
        title: 'Tutup Pesanan Pembelian',
        message: `Apakah Anda yakin ingin menutup pesanan pembelian "${order.value.number}"? Pesanan yang ditutup tidak dapat menerima barang tambahan lagi.`,
        confirmText: 'Ya, Tutup PO',
        variant: 'warning',
    });

    if (ok) {
        submit((options) => {
            router.post(`/purchasing/purchase-orders/${order.value.id}/close`, {}, {
                preserveScroll: true,
                ...options,
            });
        });
    }
}

async function handleVoid() {
    if (busy.value) return;

    const res = await confirm({
        title: 'Batalkan (Void) Pesanan Pembelian',
        message: `Masukkan alasan pembatalan dokumen "${order.value.number}". Tindakan ini bersifat permanen.`,
        confirmText: 'Ya, Batalkan Dokumen',
        variant: 'danger',
        requiresReason: true,
        reasonLabel: 'Alasan Pembatalan (min. 5 karakter)',
        reasonPlaceholder: 'Contoh: Pemasok kehabisan stok barang / dibatalkan pembeli...',
    });

    if (res && res.confirmed) {
        submit((options) => {
            router.post(`/purchasing/purchase-orders/${order.value.id}/void`, {
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
    <AppLayout :title="`Detail PO: ${order.number}`">
        <Head :title="`Detail PO: ${order.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Flash & Inline Alerts -->
            <Alert v-if="flashError" variant="danger">
                {{ flashError }}
            </Alert>
            <Alert v-if="flashSuccess" variant="success">
                {{ flashSuccess }}
            </Alert>
            <Alert v-if="pageErrors.status" variant="danger">
                {{ pageErrors.status }}
            </Alert>
            <Alert v-if="pageErrors.form" variant="danger">
                {{ pageErrors.form }}
            </Alert>

            <PageHeader
                :title="order.number"
                :description="`Dokumen pesanan pembelian ke ${order.supplier?.name || ''}`"
            >
                <template #actions>
                    <div class="flex items-center gap-2">
                        <Link href="/purchasing/purchase-orders">
                            <Button variant="secondary" size="sm">
                                <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Kembali
                            </Button>
                        </Link>

                        <Link
                            v-if="canEditPurchaseOrder(order)"
                            :href="`/purchasing/purchase-orders/${order.id}/edit`"
                        >
                            <Button variant="secondary" size="sm">
                                <Edit class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Edit Draf
                            </Button>
                        </Link>

                        <Button
                            v-if="canApprovePurchaseOrder(order)"
                            variant="primary"
                            size="sm"
                            :loading="busy"
                            :disabled="busy"
                            :aria-busy="busy"
                            @click="handleApprove"
                        >
                            <CheckCircle2 class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Setujui PO
                        </Button>

                        <Link
                            v-if="canCreateReceiptFromPO(order)"
                            :href="`/purchasing/goods-receipts/create?purchase_order_id=${order.id}`"
                        >
                            <Button variant="primary" size="sm">
                                <PackageCheck class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Buat Penerimaan Barang
                            </Button>
                        </Link>

                        <Button
                            v-if="canClosePurchaseOrder(order)"
                            variant="secondary"
                            size="sm"
                            :loading="busy"
                            :disabled="busy"
                            :aria-busy="busy"
                            @click="handleClose"
                        >
                            <Lock class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Tutup PO
                        </Button>

                        <Button
                            v-if="canVoidPurchaseOrder(order)"
                            variant="danger"
                            size="sm"
                            :loading="busy"
                            :disabled="busy"
                            :aria-busy="busy"
                            @click="handleVoid"
                        >
                            <Ban class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Batalkan
                        </Button>
                    </div>
                </template>
            </PageHeader>

            <!-- Informasi Dokumen -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <Card class="md:col-span-2">
                    <template #header>
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Informasi Pengadaan
                        </h3>
                    </template>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                        <div>
                            <span class="text-xs text-slate-500 block">Pemasok</span>
                            <span class="font-medium text-slate-800 dark:text-slate-100">
                                {{ order.supplier?.name || '-' }}
                            </span>
                            <span class="text-xs text-slate-400 block font-mono">
                                {{ order.supplier?.code }}
                            </span>
                        </div>

                        <div>
                            <span class="text-xs text-slate-500 block">Gudang Penerima</span>
                            <span class="font-medium text-slate-800 dark:text-slate-100">
                                {{ order.warehouse?.name || '-' }}
                            </span>
                        </div>

                        <div>
                            <span class="text-xs text-slate-500 block">Status Pesanan</span>
                            <div class="mt-1">
                                <StatusBadge
                                    :status="order.status"
                                    :label="purchaseOrderStatusConfig[order.status]?.label || order.status"
                                    :variant="purchaseOrderStatusConfig[order.status]?.variant || 'neutral'"
                                />
                            </div>
                        </div>

                        <div>
                            <span class="text-xs text-slate-500 block">Tanggal Pesanan</span>
                            <span class="font-medium text-slate-800 dark:text-slate-100">
                                <DateDisplay :value="order.order_date" />
                            </span>
                        </div>

                        <div>
                            <span class="text-xs text-slate-500 block">Estimasi Kedatangan</span>
                            <span class="font-medium text-slate-800 dark:text-slate-100">
                                <DateDisplay v-if="order.expected_date" :value="order.expected_date" />
                                <span v-else class="text-slate-400 italic">Tidak ditentukan</span>
                            </span>
                        </div>

                        <div>
                            <span class="text-xs text-slate-500 block">Syarat Pembayaran</span>
                            <span class="font-medium text-slate-800 dark:text-slate-100">
                                {{ order.payment_term?.name ? `${order.payment_term.code} (${order.payment_term.days} hari)` : 'COD / Tunai' }}
                            </span>
                        </div>

                        <div v-if="order.notes" class="col-span-2 sm:col-span-3 pt-2 border-t border-slate-100 dark:border-slate-800">
                            <span class="text-xs text-slate-500 block">Catatan / Instruksi Pengiriman</span>
                            <span class="text-slate-700 dark:text-slate-300 italic">{{ order.notes }}</span>
                        </div>

                        <div v-if="order.status === 'void'" class="col-span-2 sm:col-span-3 pt-2 border-t border-rose-100 dark:border-rose-900/30">
                            <span class="text-xs text-rose-500 font-semibold block">Alasan Pembatalan:</span>
                            <span class="text-rose-700 dark:text-rose-400 font-medium">{{ order.void_reason || '-' }}</span>
                        </div>
                    </div>
                </Card>

                <!-- Ringkasan Finansial -->
                <Card>
                    <template #header>
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Ringkasan Biaya
                        </h3>
                    </template>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between text-slate-600 dark:text-slate-300">
                            <span>Subtotal Kotor:</span>
                            <span class="font-mono font-medium"><Rupiah :value="order.subtotal" /></span>
                        </div>
                        <div class="flex justify-between text-slate-600 dark:text-slate-300">
                            <span>Total Diskon:</span>
                            <span class="font-mono font-medium text-rose-600">- <Rupiah :value="order.discount_amount" /></span>
                        </div>
                        <div class="flex justify-between text-slate-600 dark:text-slate-300">
                            <span>Pajak (PPN):</span>
                            <span class="font-mono font-medium text-emerald-600">+ <Rupiah :value="order.tax_amount" /></span>
                        </div>
                        <div class="pt-3 border-t border-slate-200 dark:border-slate-700 flex justify-between font-bold text-base text-slate-900 dark:text-slate-100">
                            <span>Total Tagihan:</span>
                            <span class="font-mono text-indigo-600 dark:text-indigo-400"><Rupiah :value="order.total" /></span>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Tabel Baris Barang -->
            <Card>
                <template #header>
                    <div class="space-y-0.5">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Daftar Barang Pesanan
                        </h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Rincian kuantitas pesanan, pemenuhan barang yang sudah diterima, dan sisa pesanan.
                        </p>
                    </div>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm divide-y divide-slate-200 dark:divide-slate-700">
                        <thead class="bg-slate-50 dark:bg-slate-800/60 text-xs font-semibold uppercase text-slate-500">
                            <tr>
                                <th class="py-3 px-2 w-10 text-center">#</th>
                                <th class="py-3 px-3">Produk</th>
                                <th class="py-3 px-3 text-right">Qty Pesanan</th>
                                <th class="py-3 px-3 text-right">Diterima</th>
                                <th class="py-3 px-3 text-right">Sisa Qty</th>
                                <th class="py-3 px-3 text-right">Harga Satuan</th>
                                <th class="py-3 px-3 text-right">Diskon</th>
                                <th class="py-3 px-3">Pajak</th>
                                <th class="py-3 px-3 text-right">Total Baris</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                            <tr v-for="it in order.items" :key="it.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40">
                                <td class="py-2.5 px-2 text-center text-xs text-slate-400 font-mono">
                                    {{ it.line_no }}
                                </td>
                                <td class="py-2.5 px-3">
                                    <div class="font-medium text-slate-800 dark:text-slate-100">
                                        {{ it.product?.name }}
                                    </div>
                                    <div class="text-xs text-slate-400 font-mono">
                                        {{ it.product?.sku }}
                                    </div>
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono font-medium">
                                    <Quantity :value="it.quantity" :unit="it.product?.unit?.code" />
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono text-emerald-600 dark:text-emerald-400">
                                    <Quantity :value="it.received_quantity" :unit="it.product?.unit?.code" />
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono font-semibold" :class="subtractDecimals(it.quantity, it.received_quantity, 4) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400'">
                                    <Quantity :value="subtractDecimals(it.quantity, it.received_quantity, 4)" :unit="it.product?.unit?.code" />
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono">
                                    <Rupiah :value="it.unit_price" />
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono text-rose-500">
                                    <Rupiah :value="it.discount_amount" />
                                </td>
                                <td class="py-2.5 px-3 text-xs text-slate-600 dark:text-slate-300">
                                    {{ it.tax_rate ? `${it.tax_rate.name} (${it.tax_rate.rate}%)` : '-' }}
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono font-medium text-slate-800 dark:text-slate-100">
                                    <Rupiah :value="it.line_total" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <!-- Riwayat Penerimaan Barang (GR) -->
            <Card>
                <template #header>
                    <div class="space-y-0.5">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Riwayat Penerimaan Barang (GR)
                        </h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Daftar seluruh dokumen surat penerimaan barang yang bersumber dari pesanan pembelian ini.
                        </p>
                    </div>
                </template>

                <div v-if="order.goods_receipts && order.goods_receipts.length > 0" class="overflow-x-auto">
                    <table class="w-full text-left text-sm divide-y divide-slate-200 dark:divide-slate-700">
                        <thead class="bg-slate-50 dark:bg-slate-800/60 text-xs font-semibold uppercase text-slate-500">
                            <tr>
                                <th class="py-3 px-3">No. Penerimaan (GR)</th>
                                <th class="py-3 px-3">Tanggal Terima</th>
                                <th class="py-3 px-3">Gudang</th>
                                <th class="py-3 px-3 text-center">Status</th>
                                <th class="py-3 px-3">Catatan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                            <tr v-for="gr in order.goods_receipts" :key="gr.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40">
                                <td class="py-2.5 px-3 font-mono font-semibold">
                                    <Link
                                        :href="`/purchasing/goods-receipts/${gr.id}`"
                                        class="text-indigo-600 dark:text-indigo-400 hover:underline"
                                    >
                                        {{ gr.number }}
                                    </Link>
                                </td>
                                <td class="py-2.5 px-3">
                                    <DateDisplay :value="gr.receipt_date" />
                                </td>
                                <td class="py-2.5 px-3 text-slate-700 dark:text-slate-200">
                                    {{ gr.warehouse?.name || '-' }}
                                </td>
                                <td class="py-2.5 px-3 text-center">
                                    <StatusBadge
                                        :status="gr.status"
                                        :label="goodsReceiptStatusConfig[gr.status]?.label || gr.status"
                                        :variant="goodsReceiptStatusConfig[gr.status]?.variant || 'neutral'"
                                    />
                                </td>
                                <td class="py-2.5 px-3 text-slate-500 italic text-xs">
                                    {{ gr.notes || '-' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="text-center py-6 text-slate-400 italic text-sm">
                    Belum ada surat penerimaan barang (GR) yang diterbitkan untuk pesanan ini.
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
