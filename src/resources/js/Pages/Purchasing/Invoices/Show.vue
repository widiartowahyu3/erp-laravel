<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Edit, CheckCircle2, Ban, ArrowLeft } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
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
import { compareDecimals } from '@/Support/decimal';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    journal: {
        type: Object,
        default: null,
    },
    allocations: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { canEditPurchaseInvoice, canPostPurchaseInvoice, canVoidPurchaseInvoice } = useActionGuard();
const { confirm } = useConfirm();
const { busy, submit } = useSubmitLock();

const breadcrumbs = computed(() => [
    { label: 'Faktur Pembelian (PI)', href: '/purchasing/invoices' },
    { label: props.item.number },
]);

const flashError = computed(() => page.props.flash?.error || null);
const flashSuccess = computed(() => page.props.flash?.success || null);
const pageErrors = computed(() => page.props.errors || {});

const hasPayments = computed(() => compareDecimals(props.item.paidAmount, '0', 2) > 0);

async function handlePost() {
    if (busy.value) return;

    const confirmed = await confirm({
        title: 'Posting Faktur',
        message: `Posting faktur "${props.item.number}"? Nilai dihitung ulang dari pesanan pembelian dan jurnal piutang langsung dibuat.`,
        confirmText: 'Ya, Posting Faktur',
        variant: 'primary',
    });

    if (confirmed) {
        submit((options) => {
            router.post(`/purchasing/invoices/${props.item.id}/post`, {}, {
                preserveScroll: true,
                ...options,
            });
        });
    }
}

async function handleVoid() {
    if (busy.value || hasPayments.value) return;

    const result = await confirm({
        title: 'Batalkan Faktur',
        message: `Masukkan alasan pembatalan faktur "${props.item.number}". Faktur yang sudah diposting otomatis dibuatkan jurnal pembalik. Tindakan ini permanen.`,
        confirmText: 'Ya, Batalkan Faktur',
        variant: 'danger',
        requiresReason: true,
        reasonLabel: 'Alasan Pembatalan',
        reasonPlaceholder: 'Contoh: Salah tagih',
    });

    if (result && result.confirmed) {
        submit((options) => {
            router.post(`/purchasing/invoices/${props.item.id}/void`, {
                reason: result.reason,
            }, {
                preserveScroll: true,
                ...options,
            });
        });
    }
}
</script>

<template>
    <AppLayout :title="`Detail Faktur: ${item.number}`">
        <Head :title="`Detail Faktur: ${item.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <Alert v-if="flashError" variant="danger">{{ flashError }}</Alert>
            <Alert v-if="flashSuccess" variant="success">{{ flashSuccess }}</Alert>
            <Alert v-if="pageErrors.status" variant="danger">{{ pageErrors.status }}</Alert>
            <Alert v-if="pageErrors.items" variant="danger">{{ pageErrors.items }}</Alert>
            <Alert v-if="pageErrors.supplier_id" variant="danger">{{ pageErrors.supplier_id }}</Alert>
            <Alert v-if="pageErrors.purchase_order_id" variant="danger">{{ pageErrors.purchase_order_id }}</Alert>
            <Alert v-if="pageErrors.reason" variant="danger">{{ pageErrors.reason }}</Alert>
            <Alert v-if="pageErrors.form" variant="danger">{{ pageErrors.form }}</Alert>

            <PageHeader
                :title="item.number"
                :description="`Tagihan dari ${item.supplier?.name || ''}, nomor pemasok ${item.supplierInvoiceNumber}`"
            >
                <template #actions>
                    <div class="flex flex-wrap items-center gap-2">
                        <Link href="/purchasing/invoices">
                            <Button variant="secondary" size="sm">
                                <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Kembali
                            </Button>
                        </Link>

                        <Link v-if="canEditPurchaseInvoice(item)" :href="`/purchasing/invoices/${item.id}/edit`">
                            <Button variant="secondary" size="sm">
                                <Edit class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Ubah
                            </Button>
                        </Link>

                        <Button
                            v-if="canPostPurchaseInvoice(item)"
                            variant="primary"
                            size="sm"
                            :loading="busy"
                            :disabled="busy"
                            :aria-busy="busy"
                            @click="handlePost"
                        >
                            <CheckCircle2 class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Posting
                        </Button>

                        <Button
                            v-if="canVoidPurchaseInvoice(item)"
                            variant="danger"
                            size="sm"
                            :loading="busy"
                            :disabled="busy || hasPayments"
                            :aria-busy="busy"
                            :aria-describedby="hasPayments ? 'void-blocked-note' : undefined"
                            @click="handleVoid"
                        >
                            <Ban class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Batalkan
                        </Button>
                    </div>
                </template>
            </PageHeader>

            <p
                v-if="canVoidPurchaseInvoice(item) && hasPayments"
                id="void-blocked-note"
                class="text-sm text-slate-600 dark:text-slate-300"
            >
                Faktur ini sudah menerima pembayaran. Silakan batalkan pembayaran terlebih dahulu.
            </p>

            <Alert v-if="item.status === 'void' && item.voidReason" variant="warning">
                Dibatalkan: {{ item.voidReason }}
            </Alert>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <Card class="md:col-span-2">
                    <template #header>
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Informasi Faktur
                        </h2>
                    </template>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Status:</span>
                            <StatusBadge :status="item.status" />
                        </div>
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Pemasok:</span>
                            <span class="font-medium text-slate-800 dark:text-slate-200">
                                {{ item.supplier?.code }} - {{ item.supplier?.name }}
                            </span>
                        </div>
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">No. Faktur Pemasok:</span>
                            <span class="font-mono font-medium text-slate-800 dark:text-slate-200">{{ item.supplierInvoiceNumber }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Tanggal Faktur:</span>
                            <DateDisplay :value="item.invoiceDate" />
                        </div>
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Jatuh Tempo:</span>
                            <DateDisplay :value="item.dueDate" />
                            <span v-if="item.isOverdue" class="block text-xs font-medium text-red-700 dark:text-red-400">
                                Lewat tempo
                            </span>
                        </div>
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">No. Pesanan Pembelian (SO):</span>
                            <Link
                                v-if="item.purchaseOrder"
                                :href="`/purchasing/purchase-orders/${item.purchaseOrder.id}`"
                                class="font-mono font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                            >
                                {{ item.purchaseOrder.number }}
                            </Link>
                        </div>
                        <div v-if="item.notes" class="sm:col-span-2">
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Catatan:</span>
                            <span class="text-slate-800 dark:text-slate-200 whitespace-pre-line">{{ item.notes }}</span>
                        </div>
                    </div>
                </Card>

                <div class="space-y-6">
                    <Card>
                        <template #header>
                            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Ringkasan</h2>
                        </template>

                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-slate-600 dark:text-slate-400">Subtotal</dt>
                                <dd><Rupiah :value="item.subtotal" /></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-slate-600 dark:text-slate-400">Diskon</dt>
                                <dd><Rupiah :value="item.discountAmount" /></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-slate-600 dark:text-slate-400">Pajak</dt>
                                <dd><Rupiah :value="item.taxAmount" /></dd>
                            </div>
                            <div class="flex justify-between font-semibold border-t border-slate-200 dark:border-slate-800 pt-2">
                                <dt class="text-slate-900 dark:text-slate-100">Total</dt>
                                <dd><Rupiah :value="item.total" /></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-slate-600 dark:text-slate-400">Dibayar</dt>
                                <dd><Rupiah :value="item.paidAmount" /></dd>
                            </div>
                            <div class="flex justify-between font-semibold">
                                <dt class="text-slate-900 dark:text-slate-100">Sisa</dt>
                                <dd><Rupiah :value="item.outstanding" /></dd>
                            </div>
                        </dl>
                    </Card>

                    <RelatedJournalCard v-if="item.status !== 'draft'" :journal="journal" />
                </div>
            </div>

            <Card>
                <template #header>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Rincian Barang</h2>
                </template>

                <div class="overflow-x-auto -mx-4 sm:mx-0">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-400">
                                <th class="py-2.5 px-3 w-10 text-center">No</th>
                                <th class="py-2.5 px-3">Barang</th>
                                <th class="py-2.5 px-3">No. GR</th>
                                <th class="py-2.5 px-3 text-right">Kuantitas</th>
                                <th class="py-2.5 px-3 text-right">Harga</th>
                                <th class="py-2.5 px-3 text-right">Diskon</th>
                                <th class="py-2.5 px-3 text-right">Pajak</th>
                                <th class="py-2.5 px-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="line in item.items" :key="line.id">
                                <td class="py-3 px-3 text-center">{{ line.lineNo }}</td>
                                <td class="py-3 px-3 text-slate-800 dark:text-slate-200">{{ line.description }}</td>
                                <td class="py-3 px-3 font-mono text-xs">
                                    <Link
                                        v-if="line.goodsReceiptId"
                                        :href="`/purchasing/goods-receipts/${line.goodsReceiptId}`"
                                        class="text-indigo-600 dark:text-indigo-400 hover:underline"
                                    >
                                        {{ line.goodsReceiptNumber }}
                                    </Link>
                                </td>
                                <td class="py-3 px-3 text-right"><Quantity :value="line.quantity" /></td>
                                <td class="py-3 px-3 text-right"><Rupiah :value="line.unitPrice" /></td>
                                <td class="py-3 px-3 text-right"><Rupiah :value="line.discountAmount" /></td>
                                <td class="py-3 px-3 text-right"><Rupiah :value="line.taxAmount" /></td>
                                <td class="py-3 px-3 text-right"><Rupiah :value="line.lineTotal" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Alokasi Pembayaran</h2>
                </template>

                <p v-if="allocations.length === 0" class="text-sm text-slate-600 dark:text-slate-400">
                    Belum ada pembayaran untuk faktur ini.
                </p>

                <div v-else class="overflow-x-auto -mx-4 sm:mx-0">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-400">
                                <th class="py-2.5 px-3">No. Pembayaran</th>
                                <th class="py-2.5 px-3">Tanggal</th>
                                <th class="py-2.5 px-3">Status</th>
                                <th class="py-2.5 px-3 text-right">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="allocation in allocations" :key="allocation.paymentId">
                                <td class="py-3 px-3 font-mono text-xs">
                                    <Link
                                        :href="`/accounting/payments/${allocation.paymentId}`"
                                        class="text-indigo-600 dark:text-indigo-400 hover:underline"
                                    >
                                        {{ allocation.number }}
                                    </Link>
                                </td>
                                <td class="py-3 px-3"><DateDisplay :value="allocation.paymentDate" /></td>
                                <td class="py-3 px-3"><StatusBadge :status="allocation.status" /></td>
                                <td class="py-3 px-3 text-right"><Rupiah :value="allocation.amount" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
