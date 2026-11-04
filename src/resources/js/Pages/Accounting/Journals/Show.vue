<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Edit, CheckCircle2, Ban, Undo2 } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import StatusBadge from '@/Components/ui/StatusBadge.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import Badge from '@/Components/ui/Badge.vue';
import Alert from '@/Components/ui/Alert.vue';
import { usePermission } from '@/composables/usePermission';
import { useSubmitLock } from '@/composables/useSubmitLock';
import { useConfirm } from '@/composables/useConfirm';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    sourceLabel: {
        type: String,
        default: null,
    },
    sourceUrl: {
        type: String,
        default: null,
    },
    isAutomatic: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const { can } = usePermission();
const { confirm } = useConfirm();
const { busy, submit } = useSubmitLock();

const breadcrumbs = computed(() => [
    { label: 'Jurnal', href: '/accounting/journals' },
    { label: props.item.number },
]);

const isDraft = computed(() => props.item.status === 'draft');
const isReversal = computed(() => props.item.reversal_of_id !== null && props.item.reversal_of_id !== undefined);
const isReversed = computed(() => Boolean(props.item.reversal));

const canEdit = computed(() => isDraft.value && can('journals.update'));
const canPost = computed(() => isDraft.value && can('journals.post'));
const canVoid = computed(() => isDraft.value && can('journals.void'));
const canReverse = computed(() => (
    props.item.status === 'posted'
    && !props.isAutomatic
    && !isReversal.value
    && !isReversed.value
    && can('journals.reverse')
));

const errors = computed(() => page.props.errors || {});
const flashError = computed(() => page.props.flash?.error || null);
const flashSuccess = computed(() => page.props.flash?.success || null);

async function handlePost() {
    if (busy.value) return;

    const ok = await confirm({
        title: 'Posting Jurnal',
        message: `Posting jurnal "${props.item.number}"? Jurnal yang sudah diposting tidak dapat diubah.`,
        confirmText: 'Ya, Posting',
        variant: 'primary',
    });

    if (ok) {
        submit((options) => {
            router.post(`/accounting/journals/${props.item.id}/post`, {}, { preserveScroll: true, ...options });
        });
    }
}

async function handleVoid() {
    if (busy.value) return;

    const res = await confirm({
        title: 'Batalkan Draf Jurnal',
        message: `Masukkan alasan pembatalan draf "${props.item.number}".`,
        confirmText: 'Ya, Batalkan Draf',
        variant: 'danger',
        requiresReason: true,
        reasonLabel: 'Alasan Pembatalan',
        reasonPlaceholder: 'Contoh: Salah input...',
    });

    if (res && res.confirmed) {
        submit((options) => {
            router.post(`/accounting/journals/${props.item.id}/void`, { reason: res.reason }, { preserveScroll: true, ...options });
        });
    }
}

async function handleReverse() {
    if (busy.value) return;

    const res = await confirm({
        title: 'Balik Jurnal',
        message: `Jurnal pembalik menukar debit dan kredit dari jurnal "${props.item.number}". Masukkan alasannya (1-200 karakter).`,
        confirmText: 'Ya, Balik Jurnal',
        variant: 'warning',
        requiresReason: true,
        reasonLabel: 'Alasan Pembalikan',
        reasonPlaceholder: 'Contoh: Salah akun...',
    });

    if (res && res.confirmed) {
        submit((options) => {
            router.post(`/accounting/journals/${props.item.id}/reverse`, { reason: res.reason }, { preserveScroll: true, ...options });
        });
    }
}
</script>

<template>
    <AppLayout :title="`Jurnal ${item.number}`">
        <Head :title="`Jurnal ${item.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <Alert v-if="flashError" variant="danger">{{ flashError }}</Alert>
            <Alert v-if="flashSuccess" variant="success">{{ flashSuccess }}</Alert>
            <Alert v-if="errors.status" variant="danger">{{ errors.status }}</Alert>
            <Alert v-if="errors.lines" variant="danger">{{ errors.lines }}</Alert>
            <Alert v-if="errors.reason" variant="danger">{{ errors.reason }}</Alert>

            <PageHeader :title="item.number" :description="item.description">
                <template #actions>
                    <div class="flex items-center gap-2">
                        <Link v-if="canEdit" :href="`/accounting/journals/${item.id}/edit`">
                            <Button variant="secondary" size="sm">
                                <Edit class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Ubah
                            </Button>
                        </Link>

                        <Button v-if="canPost" variant="primary" size="sm" :loading="busy" :disabled="busy" :aria-busy="busy" @click="handlePost">
                            <CheckCircle2 class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Posting
                        </Button>

                        <Button v-if="canVoid" variant="danger" size="sm" :loading="busy" :disabled="busy" :aria-busy="busy" @click="handleVoid">
                            <Ban class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Batalkan Draf
                        </Button>

                        <Button v-if="canReverse" variant="secondary" size="sm" :loading="busy" :disabled="busy" :aria-busy="busy" @click="handleReverse">
                            <Undo2 class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Balik
                        </Button>
                    </div>
                </template>
            </PageHeader>

            <Alert v-if="isAutomatic" variant="info">
                Jurnal otomatis tidak dapat dibalik atau dibatalkan langsung, batalkan dokumen sumbernya.
            </Alert>

            <Card>
                <template #header>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Informasi Jurnal</h2>
                </template>

                <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Tanggal</dt>
                        <dd class="text-slate-800 dark:text-slate-200"><DateDisplay :value="item.journal_date" /></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Status</dt>
                        <dd class="flex flex-wrap items-center gap-1">
                            <StatusBadge :status="item.status" />
                            <Badge v-if="isReversal" variant="info">Pembalik</Badge>
                            <Badge v-if="isReversed" variant="warning">Sudah dibalik</Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Sumber</dt>
                        <dd class="text-slate-800 dark:text-slate-200">
                            <Link v-if="sourceUrl" :href="sourceUrl" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                {{ sourceLabel }}
                            </Link>
                            <span v-else>{{ sourceLabel || 'Jurnal Manual' }}</span>
                        </dd>
                    </div>
                    <div v-if="item.reversal_of">
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Jurnal Asal</dt>
                        <dd>
                            <Link :href="`/accounting/journals/${item.reversal_of.id}`" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                {{ item.reversal_of.number }}
                            </Link>
                        </dd>
                    </div>
                    <div v-if="item.reversal">
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Jurnal Pembalik</dt>
                        <dd>
                            <Link :href="`/accounting/journals/${item.reversal.id}`" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                {{ item.reversal.number }}
                            </Link>
                        </dd>
                    </div>
                    <div v-if="item.void_reason">
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Alasan Pembatalan</dt>
                        <dd class="text-slate-800 dark:text-slate-200">{{ item.void_reason }}</dd>
                    </div>
                </dl>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Baris Jurnal</h2>
                </template>

                <div class="overflow-x-auto -mx-4 sm:mx-0">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-400">
                                <th class="py-2.5 px-3">Akun</th>
                                <th class="py-2.5 px-3">Keterangan</th>
                                <th class="py-2.5 px-3 text-right">Debit</th>
                                <th class="py-2.5 px-3 text-right">Kredit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="line in item.lines" :key="line.id" data-testid="journal-line">
                                <td class="py-2.5 px-3 text-slate-800 dark:text-slate-200">
                                    <span class="font-mono text-xs">{{ line.account?.code }}</span>
                                    {{ line.account?.name }}
                                </td>
                                <td class="py-2.5 px-3 text-xs text-slate-600 dark:text-slate-400">{{ line.description || '-' }}</td>
                                <td class="py-2.5 px-3 text-right font-mono text-xs"><Rupiah :value="line.debit" /></td>
                                <td class="py-2.5 px-3 text-right font-mono text-xs"><Rupiah :value="line.credit" /></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-slate-200 dark:border-slate-800 font-semibold">
                                <td class="py-2.5 px-3 text-slate-900 dark:text-slate-100" colspan="2">Total</td>
                                <td class="py-2.5 px-3 text-right font-mono text-xs"><Rupiah :value="item.total_debit" /></td>
                                <td class="py-2.5 px-3 text-right font-mono text-xs"><Rupiah :value="item.total_credit" /></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
