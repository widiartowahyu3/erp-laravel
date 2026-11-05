<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Save, ArrowLeft, CheckCircle2, Wand2 } from 'lucide-vue-next';
import Card from '@/Components/ui/Card.vue';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import Select from '@/Components/ui/Select.vue';
import Button from '@/Components/ui/Button.vue';
import Alert from '@/Components/ui/Alert.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import { useSubmitLock } from '@/composables/useSubmitLock';
import { useFocusFirstField } from '@/composables/useFocusFirstField';
import { usePermission } from '@/composables/usePermission';
import { compareMoney, isValidMoney, subtractMoney, sumMoney } from '@/Support/money';

const props = defineProps({
    customers: {
        type: Array,
        default: () => [],
    },
    cashBankAccounts: {
        type: Array,
        default: () => [],
    },
    methods: {
        type: Array,
        default: () => [],
    },
    selectedCustomerId: {
        type: Number,
        default: null,
    },
    outstandingInvoices: {
        type: Array,
        default: () => [],
    },
    initial: {
        type: Object,
        required: true,
    },
    paymentId: {
        type: Number,
        default: null,
    },
    savedAllocations: {
        type: Array,
        default: () => [],
    },
});

const { can } = usePermission();

const form = useForm({
    customer_id: props.initial.customerId ? String(props.initial.customerId) : (props.selectedCustomerId ? String(props.selectedCustomerId) : ''),
    payment_date: props.initial.paymentDate,
    method: props.initial.method || 'bank_transfer',
    account_id: props.initial.accountId ? String(props.initial.accountId) : '',
    amount: props.initial.amount || '',
    reference: props.initial.reference || '',
    notes: props.initial.notes || '',
    allocations: [],
});

const allocationByInvoice = new Map(props.savedAllocations.map((line) => [line.salesInvoiceId, line.amount]));

function buildRows(invoices) {
    return invoices.map((invoice) => ({ ...invoice, allocation: allocationByInvoice.get(invoice.id) ?? '' }));
}

const rows = ref(buildRows(props.outstandingInvoices));

watch(() => props.outstandingInvoices, (invoices) => {
    rows.value = buildRows(invoices);
});

const droppedAllocations = computed(() => {
    const open = new Set(props.outstandingInvoices.map((invoice) => invoice.id));

    return props.selectedCustomerId === props.initial.customerId
        ? props.savedAllocations.filter((line) => !open.has(line.salesInvoiceId)).length
        : 0;
});

const customerOptions = computed(() => props.customers.map((c) => ({ value: String(c.id), label: `${c.code} - ${c.name}` })));
const accountOptions = computed(() => props.cashBankAccounts.map((a) => ({ value: String(a.id), label: `${a.code} - ${a.name}` })));

const clientError = ref(null);

const { formRef } = useFocusFirstField(() => form.errors);
const { busy, submit: submitWithLock } = useSubmitLock();

const reloadUrl = computed(() => (props.paymentId ? `/accounting/payments/${props.paymentId}/edit` : '/accounting/payments/create'));
const cancelHref = computed(() => (props.paymentId ? `/accounting/payments/${props.paymentId}` : '/accounting/payments'));

function onCustomerChange(value) {
    form.customer_id = value;
    allocationByInvoice.clear();

    router.get(reloadUrl.value, value ? { customer_id: value } : {}, {
        only: ['outstandingInvoices', 'selectedCustomerId'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clean(value) {
    return String(value ?? '').trim();
}

const amountIsValid = computed(() => clean(form.amount) !== '' && isValidMoney(clean(form.amount)));
const amountValue = computed(() => (amountIsValid.value ? clean(form.amount) : '0.00'));

function rowProblem(row) {
    const value = clean(row.allocation);

    if (value === '') {
        return null;
    }

    if (!isValidMoney(value)) {
        return 'Isi dengan angka, maksimal 2 desimal. Gunakan titik sebagai pemisah desimal.';
    }

    return compareMoney(value, row.outstanding) > 0 ? 'Melebihi sisa tagihan.' : null;
}

const allocated = computed(() => sumMoney(rows.value
    .map((row) => clean(row.allocation))
    .filter((value) => value !== '' && isValidMoney(value))));

const unallocated = computed(() => subtractMoney(amountValue.value, allocated.value));

const hasRowProblems = computed(() => rows.value.some((row) => rowProblem(row) !== null));

const fullyAllocated = computed(() => amountIsValid.value
    && compareMoney(amountValue.value, '0') > 0
    && compareMoney(allocated.value, amountValue.value) === 0
    && !hasRowProblems.value);

const canPost = computed(() => can('payments.post'));

// Oldest due date first, which is the order the server sends. Blank once the amount runs out.
function allocateAutomatically() {
    let remaining = amountValue.value;

    for (const row of rows.value) {
        if (compareMoney(remaining, '0') <= 0) {
            row.allocation = '';
            continue;
        }

        const share = compareMoney(remaining, row.outstanding) >= 0 ? row.outstanding : remaining;

        row.allocation = share;
        remaining = subtractMoney(remaining, share);
    }
}

// Server errors are indexed by submitted line, and blank rows are left out of the submission.
function rowError(row) {
    const index = form.allocations.findIndex((line) => line.sales_invoice_id === row.id);

    if (index !== -1) {
        const serverError = form.errors[`allocations.${index}.amount`] || form.errors[`allocations.${index}.sales_invoice_id`];

        if (serverError) {
            return serverError;
        }
    }

    return rowProblem(row);
}

function prepare() {
    clientError.value = null;

    if (hasRowProblems.value) {
        clientError.value = 'Periksa kembali alokasi pada baris yang ditandai.';

        return false;
    }

    if (!amountIsValid.value) {
        clientError.value = 'Isi jumlah pembayaran dengan angka, maksimal 2 desimal.';

        return false;
    }

    if (compareMoney(allocated.value, amountValue.value) > 0) {
        clientError.value = 'Jumlah alokasi melebihi jumlah pembayaran.';

        return false;
    }

    form.amount = clean(form.amount);
    form.allocations = rows.value
        .filter((row) => clean(row.allocation) !== '' && compareMoney(clean(row.allocation), '0') > 0)
        .map((row) => ({ sales_invoice_id: row.id, amount: clean(row.allocation) }));

    return true;
}

function send(options) {
    if (props.paymentId) {
        form.put(`/accounting/payments/${props.paymentId}`, { ...options, preserveScroll: true });
    } else {
        form.post('/accounting/payments', { ...options, preserveScroll: true });
    }
}

function saveDraft() {
    if (prepare()) {
        submitWithLock((options) => send(options));
    }
}

function saveAndPost() {
    if (!fullyAllocated.value || !prepare()) {
        return;
    }

    submitWithLock((options) => send({
        ...options,
        onSuccess: (page) => {
            router.post(`/accounting/payments/${page.props.item.id}/post`, {}, { preserveScroll: true });
        },
    }));
}

</script>

<template>
    <form ref="formRef" class="space-y-6" @submit.prevent="saveDraft">
        <Alert v-if="clientError" variant="danger">{{ clientError }}</Alert>
        <Alert v-if="form.errors.status" variant="danger">{{ form.errors.status }}</Alert>
        <Alert v-if="form.errors.payment_type" variant="danger">{{ form.errors.payment_type }}</Alert>
        <Alert v-if="form.errors.form" variant="danger">{{ form.errors.form }}</Alert>
        <Alert v-if="droppedAllocations > 0" variant="warning">
            {{ droppedAllocations }} alokasi tersimpan merujuk faktur yang tidak lagi terbuka dan akan hilang saat draf disimpan.
        </Alert>

        <Card>
            <template #header>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Informasi Pembayaran</h2>
            </template>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <FormField v-slot="{ id, invalid }" label="Pelanggan" required :error="form.errors.customer_id">
                    <Select
                        :id="id"
                        :model-value="form.customer_id"
                        :options="customerOptions"
                        placeholder="Pilih pelanggan"
                        :invalid="invalid"
                        :disabled="form.processing"
                        @update:model-value="onCustomerChange"
                    />
                </FormField>

                <FormField v-slot="{ id, invalid }" label="Tanggal Pembayaran" required :error="form.errors.payment_date">
                    <TextInput :id="id" v-model="form.payment_date" type="date" :invalid="invalid" :disabled="form.processing" />
                </FormField>

                <FormField v-slot="{ id, invalid }" label="Metode" required :error="form.errors.method">
                    <Select :id="id" v-model="form.method" :options="methods" :invalid="invalid" :disabled="form.processing" />
                </FormField>

                <FormField v-slot="{ id, invalid }" label="Akun Kas/Bank" required :error="form.errors.account_id">
                    <Select
                        :id="id"
                        v-model="form.account_id"
                        :options="accountOptions"
                        placeholder="Pilih akun"
                        :invalid="invalid"
                        :disabled="form.processing"
                    />
                </FormField>

                <FormField v-slot="{ id, invalid }" label="Jumlah" required :error="form.errors.amount">
                    <TextInput :id="id" v-model="form.amount" inputmode="decimal" :invalid="invalid" :disabled="form.processing" />
                </FormField>

                <FormField v-slot="{ id, invalid }" label="Referensi" :error="form.errors.reference">
                    <TextInput :id="id" v-model="form.reference" :invalid="invalid" :disabled="form.processing" />
                </FormField>

                <div class="md:col-span-2 lg:col-span-3">
                    <FormField v-slot="{ id, invalid }" label="Catatan" :error="form.errors.notes">
                        <Textarea :id="id" v-model="form.notes" :rows="2" :invalid="invalid" :disabled="form.processing" />
                    </FormField>
                </div>
            </div>
        </Card>

        <Card>
            <template #header>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="space-y-0.5">
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Alokasi ke Faktur</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Faktur terbuka milik pelanggan, jatuh tempo paling lama lebih dulu.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="rows.length === 0 || !amountIsValid || form.processing"
                        @click="allocateAutomatically"
                    >
                        <Wand2 class="w-4 h-4 mr-1.5" aria-hidden="true" />
                        Alokasikan otomatis
                    </Button>
                </div>
            </template>

            <Alert v-if="form.errors.allocations" variant="danger" class="mb-4">{{ form.errors.allocations }}</Alert>

            <p v-if="!form.customer_id" class="text-sm text-slate-600 dark:text-slate-400">
                Pilih pelanggan untuk melihat faktur terbukanya.
            </p>
            <p v-else-if="rows.length === 0" class="text-sm text-slate-600 dark:text-slate-400">
                Pelanggan ini tidak memiliki faktur terbuka.
            </p>

            <div v-else class="overflow-x-auto -mx-4 sm:mx-0">
                <table class="w-full text-left text-sm border-collapse">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-400">
                            <th class="py-2.5 px-3">No. Faktur</th>
                            <th class="py-2.5 px-3">Jatuh Tempo</th>
                            <th class="py-2.5 px-3 text-right">Total</th>
                            <th class="py-2.5 px-3 text-right">Sisa</th>
                            <th class="py-2.5 px-3 w-44 text-right">Alokasi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="row in rows" :key="row.id" class="align-top">
                            <td class="py-3 px-3 font-mono text-xs">{{ row.number }}</td>
                            <td class="py-3 px-3"><DateDisplay :value="row.dueDate" /></td>
                            <td class="py-3 px-3 text-right"><Rupiah :value="row.total" /></td>
                            <td class="py-3 px-3 text-right"><Rupiah :value="row.outstanding" /></td>
                            <td class="py-3 px-3">
                                <TextInput
                                    v-model="row.allocation"
                                    inputmode="decimal"
                                    class="text-right"
                                    :disabled="form.processing"
                                    :aria-label="`Alokasi untuk faktur ${row.number}`"
                                />
                                <p v-if="rowError(row)" class="mt-1 text-xs text-red-700 dark:text-red-400">{{ rowError(row) }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <dl class="mt-4 ml-auto max-w-xs space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-600 dark:text-slate-400">Dialokasikan</dt>
                    <dd data-testid="allocated"><Rupiah :value="allocated" /></dd>
                </div>
                <div class="flex justify-between font-semibold">
                    <dt class="text-slate-900 dark:text-slate-100">Sisa belum dialokasikan</dt>
                    <dd data-testid="unallocated"><Rupiah :value="unallocated" /></dd>
                </div>
            </dl>
        </Card>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <Link :href="cancelHref">
                <Button type="button" variant="secondary">
                    <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                    Batal
                </Button>
            </Link>

            <Button
                type="submit"
                variant="secondary"
                :loading="busy"
                :disabled="busy || form.processing"
                :aria-busy="busy"
            >
                <Save class="w-4 h-4 mr-1.5" aria-hidden="true" />
                Simpan Draf
            </Button>

            <Button
                v-if="canPost"
                type="button"
                variant="primary"
                data-testid="post-button"
                :loading="busy"
                :disabled="busy || form.processing || !fullyAllocated"
                :aria-busy="busy"
                @click="saveAndPost"
            >
                <CheckCircle2 class="w-4 h-4 mr-1.5" aria-hidden="true" />
                Simpan dan Posting
            </Button>
        </div>
    </form>
</template>
