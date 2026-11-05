<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Edit, CheckCircle2, Ban, ArrowLeft, FileText, Printer } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Can from '@/Components/ui/Can.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Card from '@/Components/ui/Card.vue';
import RelatedJournalCard from '@/Components/accounting/RelatedJournalCard.vue';
import Button from '@/Components/ui/Button.vue';
import StatusBadge from '@/Components/ui/StatusBadge.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import Quantity from '@/Components/ui/Quantity.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import Alert from '@/Components/ui/Alert.vue';
import { useActionGuard } from '@/composables/useActionGuard';
import { useSubmitLock } from '@/composables/useSubmitLock';
import { useConfirm } from '@/composables/useConfirm';

const props = defineProps({
    journal: {
        type: Object,
        default: null,
    },
    item: {
        type: Object,
        default: null,
    },
    receipt: {
        type: Object,
        default: null,
    },
    hasBillableItems: {
        type: Boolean,
        default: false,
    },
});

const receipt = computed(() => props.receipt || props.item);

const page = usePage();
const { canEditGoodsReceipt, canPostGoodsReceipt, canVoidGoodsReceipt } = useActionGuard();
const { confirm } = useConfirm();
const { busy, submit } = useSubmitLock();

const breadcrumbs = [
    { label: 'Penerimaan Barang', href: '/purchasing/goods-receipts' },
    { label: receipt.value.number },
];

const flashError = computed(() => page.props.flash?.error || null);
const flashSuccess = computed(() => page.props.flash?.success || null);
const pageErrors = computed(() => page.props.errors || {});

async function handlePost() {
    if (busy.value) return;

    const ok = await confirm({
        title: 'Posting Penerimaan Barang',
        message: `Apakah Anda yakin ingin memposting penerimaan barang "${receipt.value.number}"? Kuantitas fisik barang akan langsung menambah stok persediaan gudang dan memperbarui status pesanan pembelian terkait.`,
        confirmText: 'Ya, Posting Stok',
        variant: 'primary',
    });

    if (ok) {
        submit((options) => {
            router.post(`/purchasing/goods-receipts/${receipt.value.id}/post`, {}, {
                preserveScroll: true,
                ...options,
            });
        });
    }
}

async function handleVoid() {
    if (busy.value) return;

    const res = await confirm({
        title: 'Batalkan (Void) Penerimaan Barang',
        message: `Masukkan alasan pembatalan dokumen "${receipt.value.number}". Jika dokumen sudah diposting, sistem akan mengurangi kembali stok fisik gudang dan mengembalikan progres pesanan pembelian.`,
        confirmText: 'Ya, Batalkan Dokumen',
        variant: 'danger',
        requiresReason: true,
        reasonLabel: 'Alasan Pembatalan (min. 5 karakter)',
        reasonPlaceholder: 'Contoh: Terjadi kekeliruan fisik penerimaan / barang dikembalikan...',
    });

    if (res && res.confirmed) {
        submit((options) => {
            router.post(`/purchasing/goods-receipts/${receipt.value.id}/void`, {
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
    <AppLayout :title="`Detail GR: ${receipt.number}`">
        <Head :title="`Detail GR: ${receipt.number}`" />

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
            <Alert v-if="pageErrors.quantity" variant="danger">
                {{ pageErrors.quantity }}
            </Alert>
            <Alert v-if="pageErrors.form" variant="danger">
                {{ pageErrors.form }}
            </Alert>

            <PageHeader
                :title="receipt.number"
                :description="`Dokumen surat penerimaan barang di ${receipt.warehouse?.name || ''}`"
            >
                <template #actions>
                    <div class="flex items-center gap-2">
                        <Link :href="`/purchasing/goods-receipts/${receipt.id}/print`">
                            <Button variant="secondary" size="sm">
                                <Printer class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Cetak
                            </Button>
                        </Link>
                        <Link href="/purchasing/goods-receipts">
                            <Button variant="secondary" size="sm">
                                <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Kembali
                            </Button>
                        </Link>

                        <Link
                            v-if="canEditGoodsReceipt(receipt)"
                            :href="`/purchasing/goods-receipts/${receipt.id}/edit`"
                        >
                            <Button variant="secondary" size="sm">
                                <Edit class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Edit Draf
                            </Button>
                        </Link>

                        <Button
                            v-if="canPostGoodsReceipt(receipt)"
                            variant="primary"
                            size="sm"
                            :loading="busy"
                            :disabled="busy"
                            :aria-busy="busy"
                            @click="handlePost"
                        >
                            <CheckCircle2 class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Posting Stok
                        </Button>

                        <Button
                            v-if="canVoidGoodsReceipt(receipt)"
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

                        <Can v-if="hasBillableItems" permission="purchase_invoices.create">
                            <Link :href="`/purchasing/invoices/create?purchase_order_id=${receipt.purchase_order_id}`">
                                <Button variant="primary" size="sm">
                                    <FileText class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                    Buat Faktur Pembelian
                                </Button>
                            </Link>
                        </Can>
                    </div>
                </template>
            </PageHeader>

            <!-- Informasi Dokumen -->
            <Card>
                <template #header>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                        Informasi Penerimaan
                    </h2>
                </template>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                    <div>
                        <span class="text-xs text-slate-500 block">No. PO Terkait</span>
                        <Link
                            v-if="receipt.purchase_order"
                            :href="`/purchasing/purchase-orders/${receipt.purchase_order.id}`"
                            class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline font-mono"
                        >
                            {{ receipt.purchase_order.number }}
                        </Link>
                        <span v-else class="text-slate-400">-</span>
                    </div>

                    <div>
                        <span class="text-xs text-slate-500 block">Pemasok</span>
                        <span class="font-medium text-slate-800 dark:text-slate-100">
                            {{ receipt.supplier?.name || '-' }}
                        </span>
                        <span class="text-xs text-slate-400 block font-mono">
                            {{ receipt.supplier?.code }}
                        </span>
                    </div>

                    <div>
                        <span class="text-xs text-slate-500 block">Gudang Penerima</span>
                        <span class="font-medium text-slate-800 dark:text-slate-100">
                            {{ receipt.warehouse?.name || '-' }}
                        </span>
                    </div>

                    <div>
                        <span class="text-xs text-slate-500 block">Status Penerimaan</span>
                        <div class="mt-1">
                            <StatusBadge :status="receipt.status" />
                        </div>
                    </div>

                    <div>
                        <span class="text-xs text-slate-500 block">Tanggal Terima</span>
                        <span class="font-medium text-slate-800 dark:text-slate-100">
                            <DateDisplay :value="receipt.receipt_date" />
                        </span>
                    </div>

                    <div v-if="receipt.notes" class="col-span-2 sm:col-span-3">
                        <span class="text-xs text-slate-500 block">Catatan Surat Jalan</span>
                        <span class="text-slate-700 dark:text-slate-300 italic">{{ receipt.notes }}</span>
                    </div>

                    <div v-if="receipt.status === 'void'" class="col-span-2 sm:col-span-4 pt-2 border-t border-rose-100 dark:border-rose-900/30">
                        <span class="text-xs text-rose-500 font-semibold block">Alasan Pembatalan:</span>
                        <span class="text-rose-700 dark:text-rose-400 font-medium">{{ receipt.void_reason || '-' }}</span>
                    </div>
                </div>
            </Card>

            <!-- Tabel Baris Barang -->
            <Card>
                <template #header>
                    <div class="space-y-0.5">
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Rincian Barang yang Diterima
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Kuantitas fisik yang dicatat masuk ke persediaan gudang beserta nilai biaya perolehan.
                        </p>
                    </div>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm divide-y divide-slate-200 dark:divide-slate-700">
                        <thead class="bg-slate-50 dark:bg-slate-800/60 text-xs font-semibold uppercase text-slate-500">
                            <tr>
                                <th class="py-3 px-2 w-10 text-center">#</th>
                                <th class="py-3 px-3">Produk</th>
                                <th class="py-3 px-3 text-right">Qty Diterima</th>
                                <th class="py-3 px-3 text-right">Biaya Satuan Perolehan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                            <tr v-for="it in receipt.items" :key="it.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40">
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
                                <td class="py-2.5 px-3 text-right font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                                    <Quantity :value="it.quantity" :unit="it.product?.unit?.code" />
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono font-medium text-slate-800 dark:text-slate-100">
                                    <Rupiah :value="it.unit_cost" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <RelatedJournalCard :journal="journal" />
        </div>
    </AppLayout>
</template>
