<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Edit, CheckCircle2, Ban, ArrowLeft } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatusBadge from '@/Components/ui/StatusBadge.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import Quantity from '@/Components/ui/Quantity.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import Alert from '@/Components/ui/Alert.vue';
import { useActionGuard } from '@/composables/useActionGuard';
import { useSubmitLock } from '@/composables/useSubmitLock';
import { useConfirm } from '@/composables/useConfirm';
import { stockMovementTypeConfig } from '@/config/inventory';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    movements: {
        type: Array,
        default: null,
    },
});

const page = usePage();
const { canEditAdjustment, canPostAdjustment, canVoidAdjustment } = useActionGuard();
const { confirm } = useConfirm();
const { busy, submit } = useSubmitLock();

const breadcrumbs = [
    { label: 'Penyesuaian Stok', href: '/inventory/stock-adjustments' },
    { label: props.item.number },
];

const flashError = computed(() => page.props.flash?.error || null);
const flashSuccess = computed(() => page.props.flash?.success || null);
const pageErrors = computed(() => page.props.errors || {});

async function handlePost() {
    if (busy.value) return;

    const ok = await confirm({
        title: 'Posting Penyesuaian Stok',
        message: `Apakah Anda yakin ingin memposting penyesuaian stok "${props.item.number}"? Saldo persediaan gudang akan segera diperbarui dan dicatat ke mutasi stok. Tindakan ini tidak dapat diedit kembali setelah diposting.`,
        confirmText: 'Ya, Posting Stok',
        variant: 'primary',
    });

    if (ok) {
        submit((options) => {
            router.post(`/inventory/stock-adjustments/${props.item.id}/post`, {}, {
                preserveScroll: true,
                ...options,
            });
        });
    }
}

async function handleVoid() {
    if (busy.value) return;

    const res = await confirm({
        title: 'Batalkan (Void) Penyesuaian Stok',
        message: `Masukkan alasan pembatalan dokumen "${props.item.number}". Jika dokumen sudah diposting, sistem akan membuat mutasi pembalik otomatis.`,
        confirmText: 'Ya, Batalkan Dokumen',
        variant: 'danger',
        requiresReason: true,
        reasonLabel: 'Alasan Pembatalan (min. 5 karakter)',
        reasonPlaceholder: 'Contoh: Terjadi kesalahan hitung fisik opname...',
    });

    if (res && res.confirmed) {
        submit((options) => {
            router.post(`/inventory/stock-adjustments/${props.item.id}/void`, {
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
    <AppLayout :title="`Detail Penyesuaian Stok: ${item.number}`">
        <Head :title="`Detail Penyesuaian Stok: ${item.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Flash & Inline Errors -->
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
                :title="item.number"
                :description="`Dokumen penyesuaian stok fisik gudang ${item.warehouse?.name || ''}`"
            >
                <template #actions>
                    <div class="flex items-center gap-2">
                        <Link href="/inventory/stock-adjustments">
                            <Button variant="secondary" size="sm">
                                <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Kembali
                            </Button>
                        </Link>

                        <Link
                            v-if="canEditAdjustment(item)"
                            :href="`/inventory/stock-adjustments/${item.id}/edit`"
                        >
                            <Button variant="secondary" size="sm">
                                <Edit class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Ubah Draf
                            </Button>
                        </Link>

                        <Button
                            v-if="canPostAdjustment(item)"
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
                            v-if="canVoidAdjustment(item)"
                            variant="danger"
                            size="sm"
                            :loading="busy"
                            :disabled="busy"
                            :aria-busy="busy"
                            @click="handleVoid"
                        >
                            <Ban class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Batalkan (Void)
                        </Button>
                    </div>
                </template>
            </PageHeader>

            <!-- Document Details Card -->
            <Card>
                <template #header>
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            Informasi Dokumen
                        </h3>
                        <StatusBadge :status="item.status" />
                    </div>
                </template>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 text-sm">
                    <div>
                        <span class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                            Nomor Penyesuaian
                        </span>
                        <span class="font-mono font-semibold text-slate-900 dark:text-slate-100">
                            {{ item.number }}
                        </span>
                    </div>

                    <div>
                        <span class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                            Gudang
                        </span>
                        <span class="font-medium text-slate-900 dark:text-slate-100">
                            {{ item.warehouse?.code }} - {{ item.warehouse?.name }}
                        </span>
                    </div>

                    <div>
                        <span class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                            Tanggal Penyesuaian
                        </span>
                        <span class="font-medium text-slate-900 dark:text-slate-100">
                            <DateDisplay :value="item.adjustment_date" format="medium" />
                        </span>
                    </div>

                    <div>
                        <span class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                            Dibuat Oleh
                        </span>
                        <span class="text-slate-900 dark:text-slate-100">
                            {{ item.creator?.name || '-' }}
                            <span v-if="item.created_at" class="text-xs text-slate-400 block">
                                <DateDisplay :value="item.created_at" format="datetime" />
                            </span>
                        </span>
                    </div>

                    <div class="md:col-span-2">
                        <span class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                            Alasan Penyesuaian
                        </span>
                        <span class="text-slate-900 dark:text-slate-100 font-medium">
                            {{ item.reason }}
                        </span>
                    </div>

                    <div class="md:col-span-2">
                        <span class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                            Catatan Tambahan
                        </span>
                        <span class="text-slate-700 dark:text-slate-300">
                            {{ item.notes || '-' }}
                        </span>
                    </div>

                    <!-- Posting Audit Info -->
                    <div v-if="item.posted_by" class="md:col-span-2 p-3 bg-emerald-50/40 dark:bg-emerald-950/20 rounded-lg border border-emerald-200 dark:border-emerald-800/40">
                        <span class="block text-xs font-semibold text-emerald-800 dark:text-emerald-300">
                            Informasi Posting
                        </span>
                        <p class="text-xs text-emerald-700 dark:text-emerald-400 mt-0.5">
                            Diposting oleh <strong>{{ item.poster?.name || '-' }}</strong> pada <DateDisplay :value="item.posted_at" format="datetime" />.
                        </p>
                    </div>

                    <!-- Void Audit Info -->
                    <div v-if="item.voided_by" class="md:col-span-2 p-3 bg-rose-50/40 dark:bg-rose-950/20 rounded-lg border border-rose-200 dark:border-rose-800/40">
                        <span class="block text-xs font-semibold text-rose-800 dark:text-rose-300">
                            Informasi Pembatalan (Void)
                        </span>
                        <p class="text-xs text-rose-700 dark:text-rose-400 mt-0.5">
                            Dibatalkan oleh <strong>{{ item.voider?.name || '-' }}</strong> pada <DateDisplay :value="item.voided_at" format="datetime" />.
                        </p>
                        <p class="text-xs text-rose-800 dark:text-rose-300 mt-1">
                            Alasan: <em>"{{ item.void_reason }}"</em>
                        </p>
                    </div>
                </div>
            </Card>

            <!-- Line Items Table -->
            <Card>
                <template #header>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                        Rincian Barang Penyesuaian
                    </h3>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/75 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-300 uppercase tracking-wider">
                            <tr>
                                <th class="py-3 px-4 w-12 text-center">No</th>
                                <th class="py-3 px-4">SKU</th>
                                <th class="py-3 px-4">Nama Produk</th>
                                <th class="py-3 px-4">Satuan</th>
                                <th class="py-3 px-4 text-right">Saldo Sistem</th>
                                <th class="py-3 px-4 text-right">Fisik (Opname)</th>
                                <th class="py-3 px-4 text-right">Selisih</th>
                                <th class="py-3 px-4 text-right">Biaya Satuan (HPP)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60">
                            <tr
                                v-for="it in item.items"
                                :key="it.id"
                                class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors"
                            >
                                <td class="py-3 px-4 text-center font-mono text-xs text-slate-500">
                                    {{ it.line_no }}
                                </td>
                                <td class="py-3 px-4 font-mono text-xs font-medium text-slate-800 dark:text-slate-200">
                                    {{ it.product?.sku }}
                                </td>
                                <td class="py-3 px-4 font-medium text-slate-900 dark:text-slate-100">
                                    {{ it.product?.name }}
                                </td>
                                <td class="py-3 px-4 font-mono text-xs text-slate-600 dark:text-slate-400">
                                    {{ it.product?.base_unit?.code || '-' }}
                                </td>
                                <td class="py-3 px-4 text-right font-mono text-xs text-slate-600 dark:text-slate-400">
                                    <Quantity :value="it.system_quantity" />
                                </td>
                                <td class="py-3 px-4 text-right font-mono text-xs font-semibold text-slate-900 dark:text-slate-100">
                                    <Quantity :value="it.counted_quantity" />
                                </td>
                                <td class="py-3 px-4 text-right font-mono text-xs font-medium">
                                    <span
                                        :class="[
                                            String(it.difference_quantity).startsWith('-')
                                                ? 'text-rose-600 dark:text-rose-400'
                                                : String(it.difference_quantity) === '0.0000'
                                                    ? 'text-slate-500'
                                                    : 'text-emerald-600 dark:text-emerald-400'
                                        ]"
                                    >
                                        <span v-if="!String(it.difference_quantity).startsWith('-') && String(it.difference_quantity) !== '0.0000'">+</span>
                                        <Quantity :value="it.difference_quantity" />
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <Rupiah :value="it.unit_cost" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <!-- Related Stock Movements (Only if user has permission & data present) -->
            <Card v-if="movements && movements.length > 0">
                <template #header>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                        Mutasi Stok Terkait Dokumen
                    </h3>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/75 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-300 uppercase tracking-wider">
                            <tr>
                                <th class="py-3 px-4">Waktu</th>
                                <th class="py-3 px-4">Tipe Mutasi</th>
                                <th class="py-3 px-4">Produk</th>
                                <th class="py-3 px-4 text-right">Kuantitas</th>
                                <th class="py-3 px-4 text-right">Biaya Satuan</th>
                                <th class="py-3 px-4 text-right">Saldo Akhir</th>
                                <th class="py-3 px-4 text-right">HPP Akhir</th>
                                <th class="py-3 px-4">Dicatat Oleh</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60">
                            <tr
                                v-for="m in movements"
                                :key="m.id"
                                class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors"
                            >
                                <td class="py-3 px-4 whitespace-nowrap text-xs">
                                    <DateDisplay :value="m.movement_date" format="datetime" />
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <Badge
                                        :variant="stockMovementTypeConfig[m.movement_type]?.variant || 'neutral'"
                                        size="sm"
                                    >
                                        {{ stockMovementTypeConfig[m.movement_type]?.label || m.movement_type }}
                                    </Badge>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="font-mono text-xs text-slate-500 dark:text-slate-400 block">
                                        {{ m.product?.sku }}
                                    </span>
                                    <span class="font-medium text-slate-900 dark:text-slate-100">
                                        {{ m.product?.name }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right whitespace-nowrap font-medium">
                                    <span
                                        :class="[
                                            String(m.quantity).startsWith('-')
                                                ? 'text-rose-600 dark:text-rose-400'
                                                : 'text-emerald-600 dark:text-emerald-400'
                                        ]"
                                    >
                                        <span v-if="!String(m.quantity).startsWith('-')">+</span>
                                        <Quantity :value="m.quantity" />
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                    <Rupiah :value="m.unit_cost" />
                                </td>
                                <td class="py-3 px-4 text-right whitespace-nowrap font-medium text-slate-900 dark:text-slate-100">
                                    <Quantity :value="m.balance_after" />
                                </td>
                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                    <Rupiah :value="m.avg_cost_after" />
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-xs text-slate-600 dark:text-slate-400">
                                    {{ m.creator?.name || '-' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
