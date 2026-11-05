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
import Rupiah from '@/Components/ui/Rupiah.vue';
import Alert from '@/Components/ui/Alert.vue';
import { useActionGuard } from '@/composables/useActionGuard';
import { useSubmitLock } from '@/composables/useSubmitLock';
import { useConfirm } from '@/composables/useConfirm';
import { compareMoney, subtractMoney, sumMoney } from '@/Support/money';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    journal: {
        type: Object,
        default: null,
    },
});

const page = usePage();
const { canEditPayment, canPostPayment, canVoidPayment } = useActionGuard();
const { confirm } = useConfirm();
const { busy, submit } = useSubmitLock();

const breadcrumbs = computed(() => [
    { label: 'Pembayaran Kas/Bank', href: '/accounting/payments' },
    { label: props.item.number },
]);

const isDisbursement = computed(() => props.item.paymentType === 'disbursement');
const party = computed(() => (isDisbursement.value ? props.item.supplier : props.item.customer));
const partyLabel = computed(() => (isDisbursement.value ? 'Pemasok' : 'Pelanggan'));

const flashError = computed(() => page.props.flash?.error || null);
const flashSuccess = computed(() => page.props.flash?.success || null);
const pageErrors = computed(() => page.props.errors || {});

const allocated = computed(() => sumMoney(props.item.allocations.map((line) => line.amount)));
const unallocated = computed(() => subtractMoney(props.item.amount, allocated.value));
const fullyAllocated = computed(() => compareMoney(allocated.value, props.item.amount) === 0);

async function handlePost() {
    if (busy.value || !fullyAllocated.value) return;

    const confirmed = await confirm({
        title: 'Posting Pembayaran',
        message: `Posting pembayaran "${props.item.number}"? Sisa tagihan faktur langsung berkurang dan jurnal kas/bank dibuat.`,
        confirmText: 'Ya, Posting Pembayaran',
        variant: 'primary',
    });

    if (confirmed) {
        submit((options) => {
            router.post(`/accounting/payments/${props.item.id}/post`, {}, {
                preserveScroll: true,
                ...options,
            });
        });
    }
}

async function handleVoid() {
    if (busy.value) return;

    const effect = props.item.status === 'posted'
        ? 'Sisa tagihan faktur yang dialokasikan akan kembali bertambah, statusnya dihitung ulang, dan jurnal pembalik dibuat.'
        : 'Draf ini belum memengaruhi faktur.';

    const result = await confirm({
        title: 'Batalkan Pembayaran',
        message: `Masukkan alasan pembatalan pembayaran "${props.item.number}". ${effect} Tindakan ini permanen.`,
        confirmText: 'Ya, Batalkan Pembayaran',
        variant: 'danger',
        requiresReason: true,
        reasonLabel: 'Alasan Pembatalan',
        reasonPlaceholder: 'Contoh: Salah transfer',
    });

    if (result && result.confirmed) {
        submit((options) => {
            router.post(`/accounting/payments/${props.item.id}/void`, {
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
    <AppLayout :title="`Detail Pembayaran: ${item.number}`">
        <Head :title="`Detail Pembayaran: ${item.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <Alert v-if="flashError" variant="danger">{{ flashError }}</Alert>
            <Alert v-if="flashSuccess" variant="success">{{ flashSuccess }}</Alert>
            <Alert v-if="pageErrors.status" variant="danger">{{ pageErrors.status }}</Alert>
            <Alert v-if="pageErrors.allocations" variant="danger">{{ pageErrors.allocations }}</Alert>
            <Alert v-if="pageErrors.customer_id" variant="danger">{{ pageErrors.customer_id }}</Alert>
            <Alert v-if="pageErrors.supplier_id" variant="danger">{{ pageErrors.supplier_id }}</Alert>
            <Alert v-if="pageErrors.account_id" variant="danger">{{ pageErrors.account_id }}</Alert>
            <Alert v-if="pageErrors.reason" variant="danger">{{ pageErrors.reason }}</Alert>
            <Alert v-if="pageErrors.form" variant="danger">{{ pageErrors.form }}</Alert>

            <PageHeader
                :title="item.number"
                :description="`${isDisbursement ? 'Pembayaran ke pemasok' : 'Penerimaan dari pelanggan'} ${party?.name || ''}`"
            >
                <template #actions>
                    <div class="flex flex-wrap items-center gap-2">
                        <Link href="/accounting/payments">
                            <Button variant="secondary" size="sm">
                                <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Kembali
                            </Button>
                        </Link>

                        <Link v-if="canEditPayment(item)" :href="`/accounting/payments/${item.id}/edit`">
                            <Button variant="secondary" size="sm">
                                <Edit class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Ubah
                            </Button>
                        </Link>

                        <Button
                            v-if="canPostPayment(item)"
                            variant="primary"
                            size="sm"
                            :loading="busy"
                            :disabled="busy || !fullyAllocated"
                            :aria-busy="busy"
                            :aria-describedby="fullyAllocated ? undefined : 'post-blocked-note'"
                            @click="handlePost"
                        >
                            <CheckCircle2 class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Posting
                        </Button>

                        <Button
                            v-if="canVoidPayment(item)"
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

            <p
                v-if="canPostPayment(item) && !fullyAllocated"
                id="post-blocked-note"
                class="text-sm text-slate-600 dark:text-slate-300"
            >
                Pembayaran belum bisa diposting karena masih ada <Rupiah :value="unallocated" /> yang belum dialokasikan.
            </p>

            <Alert v-if="item.status === 'void' && item.voidReason" variant="warning">
                Dibatalkan: {{ item.voidReason }}
            </Alert>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <Card class="md:col-span-2">
                    <template #header>
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Informasi Pembayaran</h2>
                    </template>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Status:</span>
                            <StatusBadge :status="item.status" />
                        </div>
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">{{ partyLabel }}:</span>
                            <span class="font-medium text-slate-800 dark:text-slate-200">
                                {{ party?.code }} - {{ party?.name }}
                            </span>
                        </div>
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Tanggal:</span>
                            <DateDisplay :value="item.paymentDate" />
                        </div>
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Metode:</span>
                            <span class="text-slate-800 dark:text-slate-200">{{ item.methodLabel }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Akun Kas/Bank:</span>
                            <span class="text-slate-800 dark:text-slate-200">{{ item.account?.code }} - {{ item.account?.name }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Jumlah:</span>
                            <span class="font-semibold text-slate-900 dark:text-slate-100"><Rupiah :value="item.amount" /></span>
                        </div>
                        <div v-if="item.reference">
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Referensi:</span>
                            <span class="text-slate-800 dark:text-slate-200">{{ item.reference }}</span>
                        </div>
                        <div v-if="item.notes" class="sm:col-span-2">
                            <span class="text-xs text-slate-500 dark:text-slate-400 block">Catatan:</span>
                            <span class="text-slate-800 dark:text-slate-200 whitespace-pre-line">{{ item.notes }}</span>
                        </div>
                    </div>
                </Card>

                <RelatedJournalCard v-if="item.status !== 'draft'" :journal="journal" />
            </div>

            <Card>
                <template #header>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Alokasi ke Faktur</h2>
                </template>

                <p v-if="item.allocations.length === 0" class="text-sm text-slate-600 dark:text-slate-400">
                    Belum ada alokasi.
                </p>

                <div v-else class="overflow-x-auto -mx-4 sm:mx-0">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-400">
                                <th class="py-2.5 px-3">No. Faktur</th>
                                <th class="py-2.5 px-3 text-right">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="line in item.allocations" :key="line.id">
                                <td class="py-3 px-3 font-mono text-xs">
                                    <Link
:href="isDisbursement ? `/purchasing/invoices/${line.purchaseInvoiceId}` : `/sales/invoices/${line.salesInvoiceId}`"
                                        class="text-indigo-600 dark:text-indigo-400 hover:underline"
                                    >
                                        {{ line.invoiceNumber }}
                                    </Link>
                                </td>
                                <td class="py-3 px-3 text-right"><Rupiah :value="line.amount" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
