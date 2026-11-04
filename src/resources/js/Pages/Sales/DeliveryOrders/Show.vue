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
import { mulDecimals, addDecimals } from '@/Support/decimal';

const props = defineProps({
    journal: {
        type: Object,
        default: null,
    },
    item: {
        type: Object,
        default: null,
    },
    deliveryOrder: {
        type: Object,
        default: null,
    },
});

const deliveryOrder = computed(() => props.deliveryOrder || props.item || {});

const page = usePage();
const {
    canEditDeliveryOrder,
    canPostDeliveryOrder,
    canVoidDeliveryOrder,
} = useActionGuard();

const { confirm } = useConfirm();
const { busy, submit } = useSubmitLock();

const breadcrumbs = computed(() => [
    { label: 'Surat Jalan (DO)', href: '/sales/delivery-orders' },
    { label: deliveryOrder.value.number || 'Detail' },
]);

const flashError = computed(() => page.props.flash?.error || null);
const flashSuccess = computed(() => page.props.flash?.success || null);
const pageErrors = computed(() => page.props.errors || {});

const isPosted = computed(() => deliveryOrder.value.status === 'posted');

function lineTotalCost(it) {
    if (!it || !it.unit_cost || !it.quantity) return '0.00';
    return mulDecimals(it.quantity, it.unit_cost, 2);
}

const grandTotalCost = computed(() => {
    if (!deliveryOrder.value?.items?.length) return '0.00';
    return deliveryOrder.value.items.reduce((acc, it) => {
        return addDecimals(acc, lineTotalCost(it), 2);
    }, '0.00');
});

async function handlePost() {
    if (busy.value) return;

    const confirmed = await confirm({
        title: 'Posting Surat Jalan',
        message: `Apakah Anda yakin ingin memposting surat jalan "${deliveryOrder.value.number}"? Tindakan ini akan langsung memotong saldo persediaan barang di gudang "${deliveryOrder.value.warehouse?.name || ''}" dan mencatat harga pokok penjualan (HPP).`,
        confirmText: 'Ya, Posting DO',
        variant: 'primary',
    });

    if (confirmed) {
        submit((options) => {
            router.post(`/sales/delivery-orders/${deliveryOrder.value.id}/post`, {}, {
                preserveScroll: true,
                ...options,
            });
        });
    }
}

async function handleVoid() {
    if (busy.value) return;

    const res = await confirm({
        title: 'Batalkan (Void) Surat Jalan',
        message: `Masukkan alasan pembatalan dokumen "${deliveryOrder.value.number}". Jika dokumen sudah diposting sebelumnya, pemotongan stok akan otomatis dikembalikan ke saldo gudang. Tindakan ini permanen.`,
        confirmText: 'Ya, Batalkan Dokumen',
        variant: 'danger',
        requiresReason: true,
        reasonLabel: 'Alasan Pembatalan (min. 5 karakter)',
        reasonPlaceholder: 'Contoh: Pengiriman dibatalkan / barang retur fisik...',
    });

    if (res && res.confirmed) {
        submit((options) => {
            router.post(`/sales/delivery-orders/${deliveryOrder.value.id}/void`, {
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
    <AppLayout :title="`Detail DO: ${deliveryOrder.number}`">
        <Head :title="`Detail DO: ${deliveryOrder.number}`" />

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
            <Alert v-if="pageErrors.quantity" variant="danger">
                {{ pageErrors.quantity }}
            </Alert>

            <PageHeader
                :title="deliveryOrder.number"
                :description="`Surat jalan pengiriman barang untuk ${deliveryOrder.customer?.name || ''}`"
            >
                <template #actions>
                    <div class="flex items-center gap-2">
                        <Link href="/sales/delivery-orders">
                            <Button variant="secondary" size="sm">
                                <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Kembali
                            </Button>
                        </Link>

                        <Link
                            v-if="canEditDeliveryOrder(deliveryOrder)"
                            :href="`/sales/delivery-orders/${deliveryOrder.id}/edit`"
                        >
                            <Button variant="secondary" size="sm">
                                <Edit class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Edit Draf
                            </Button>
                        </Link>

                        <Button
                            v-if="canPostDeliveryOrder(deliveryOrder)"
                            variant="primary"
                            size="sm"
                            :loading="busy"
                            :disabled="busy"
                            :aria-busy="busy"
                            @click="handlePost"
                        >
                            <CheckCircle2 class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Posting DO
                        </Button>

                        <Button
                            v-if="canVoidDeliveryOrder(deliveryOrder)"
                            variant="danger"
                            size="sm"
                            :loading="busy"
                            :disabled="busy"
                            :aria-busy="busy"
                            @click="handleVoid"
                        >
                            <Ban class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Batalkan DO
                        </Button>
                    </div>
                </template>
            </PageHeader>

            <!-- Informasi Utama -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <Card class="md:col-span-2">
                    <template #header>
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Informasi Pengiriman
                        </h3>
                    </template>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">No. Pesanan Penjualan (SO):</span>
                            <Link
                                v-if="deliveryOrder.sales_order"
                                :href="`/sales/sales-orders/${deliveryOrder.sales_order.id}`"
                                class="font-mono font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                            >
                                {{ deliveryOrder.sales_order.number }}
                            </Link>
                            <span v-else class="text-slate-400">-</span>
                        </div>

                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Tanggal Pengiriman:</span>
                            <span class="font-medium text-slate-800 dark:text-slate-200">
                                <DateDisplay :value="deliveryOrder.delivery_date" />
                            </span>
                        </div>

                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Pelanggan:</span>
                            <span class="font-medium text-slate-800 dark:text-slate-200">
                                {{ deliveryOrder.customer?.code }} - {{ deliveryOrder.customer?.name }}
                            </span>
                        </div>

                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Gudang Asal Barang:</span>
                            <span class="font-medium text-slate-800 dark:text-slate-200">
                                {{ deliveryOrder.warehouse?.code }} - {{ deliveryOrder.warehouse?.name }}
                            </span>
                        </div>

                        <div v-if="deliveryOrder.shipping_address" class="sm:col-span-2">
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Alamat Pengiriman:</span>
                            <span class="text-slate-700 dark:text-slate-300 text-xs">
                                {{ deliveryOrder.shipping_address }}
                            </span>
                        </div>

                        <div v-if="deliveryOrder.notes" class="sm:col-span-2">
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Catatan:</span>
                            <span class="text-slate-700 dark:text-slate-300 whitespace-pre-line text-xs">
                                {{ deliveryOrder.notes }}
                            </span>
                        </div>
                    </div>
                </Card>

                <Card>
                    <template #header>
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Status & Jejak Dokumen
                        </h3>
                    </template>
                    <div class="space-y-3 text-sm">
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block mb-1">Status:</span>
                            <StatusBadge :status="deliveryOrder.status" />
                        </div>

                        <div class="pt-2 border-t border-slate-100 dark:border-slate-800 text-xs space-y-1.5 text-slate-600 dark:text-slate-400">
                            <div>
                                <span class="text-slate-500">Dibuat oleh:</span>
                                <span class="font-medium text-slate-700 dark:text-slate-300 ml-1">
                                    {{ deliveryOrder.creator?.name || '-' }}
                                </span>
                            </div>

                            <div v-if="deliveryOrder.poster">
                                <span class="text-slate-500">Diposting oleh:</span>
                                <span class="font-medium text-emerald-600 ml-1">
                                    {{ deliveryOrder.poster.name }}
                                </span>
                            </div>

                            <div v-if="deliveryOrder.voider">
                                <span class="text-slate-500">Dibatalkan oleh:</span>
                                <span class="font-medium text-rose-600 ml-1">
                                    {{ deliveryOrder.voider.name }}
                                </span>
                                <div v-if="deliveryOrder.void_reason" class="text-rose-500 italic mt-0.5">
                                    "{{ deliveryOrder.void_reason }}"
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Tabel Barang yang Dikirim -->
            <Card>
                <template #header>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                        Rincian Barang yang Dikirim
                    </h3>
                </template>

                <div class="overflow-x-auto -mx-4 sm:mx-0">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-400">
                                <th class="py-2.5 px-3 w-10 text-center">No</th>
                                <th class="py-2.5 px-3">Produk</th>
                                <th class="py-2.5 px-3 text-right">Kuantitas Dikirim</th>
                                <!-- HPP / unit_cost visible ONLY when posted -->
                                <th v-if="isPosted" class="py-2.5 px-3 text-right">HPP Satuan</th>
                                <th v-if="isPosted" class="py-2.5 px-3 text-right">Total HPP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr
                                v-for="(it, idx) in deliveryOrder.items"
                                :key="it.id || idx"
                                class="align-middle hover:bg-slate-50/50 dark:hover:bg-slate-800/50"
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
                                <td class="py-2.5 px-3 text-right font-mono text-xs font-semibold text-slate-800 dark:text-slate-200">
                                    <Quantity :value="it.quantity" />
                                </td>
                                <!-- HPP / unit_cost visible ONLY when posted -->
                                <td v-if="isPosted" class="py-2.5 px-3 text-right font-mono text-xs text-slate-700 dark:text-slate-300">
                                    <Rupiah :amount="it.unit_cost" />
                                </td>
                                <td v-if="isPosted" class="py-2.5 px-3 text-right font-mono text-xs font-semibold text-slate-800 dark:text-slate-200">
                                    <Rupiah :amount="lineTotalCost(it)" />
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="isPosted" class="border-t-2 border-slate-200 dark:border-slate-800">
                            <tr class="font-semibold text-xs text-slate-800 dark:text-slate-200">
                                <td colspan="4" class="py-2.5 px-3 text-right">Total HPP:</td>
                                <td class="py-2.5 px-3 text-right font-mono text-xs font-bold text-slate-900 dark:text-slate-100">
                                    <Rupiah :amount="grandTotalCost" />
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </Card>

            <RelatedJournalCard :journal="journal" />
        </div>
    </AppLayout>
</template>
