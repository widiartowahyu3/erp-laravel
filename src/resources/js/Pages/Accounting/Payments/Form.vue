<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Save, ArrowLeft, CheckCircle2 } from 'lucide-vue-next';
import AllocationEditor from '@/Components/accounting/AllocationEditor.vue';
import Card from '@/Components/ui/Card.vue';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import Select from '@/Components/ui/Select.vue';
import Button from '@/Components/ui/Button.vue';
import Alert from '@/Components/ui/Alert.vue';
import { useSubmitLock } from '@/composables/useSubmitLock';
import { useFocusFirstField } from '@/composables/useFocusFirstField';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({
    paymentType: {
        type: String,
        default: 'receipt',
    },
    customers: {
        type: Array,
        default: () => [],
    },
    suppliers: {
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
    selectedSupplierId: {
        type: Number,
        default: null,
    },
    outstandingPurchaseInvoices: {
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

const isDisbursement = props.paymentType === 'disbursement';
const invoiceKey = isDisbursement ? 'purchase_invoice_id' : 'sales_invoice_id';
const savedInvoiceKey = isDisbursement ? 'purchaseInvoiceId' : 'salesInvoiceId';

const form = useForm({
    payment_type: props.paymentType,
    ...(isDisbursement
        ? { supplier_id: props.initial.supplierId ? String(props.initial.supplierId) : (props.selectedSupplierId ? String(props.selectedSupplierId) : '') }
        : { customer_id: props.initial.customerId ? String(props.initial.customerId) : (props.selectedCustomerId ? String(props.selectedCustomerId) : '') }),
    payment_date: props.initial.paymentDate,
    method: props.initial.method || 'bank_transfer',
    account_id: props.initial.accountId ? String(props.initial.accountId) : '',
    amount: props.initial.amount || '',
    reference: props.initial.reference || '',
    notes: props.initial.notes || '',
    allocations: [],
});

const savedByInvoice = ref(Object.fromEntries(props.savedAllocations.map((line) => [line[savedInvoiceKey], line.amount])));

const openInvoices = computed(() => (isDisbursement ? props.outstandingPurchaseInvoices : props.outstandingInvoices));

const droppedAllocations = computed(() => {
    const open = new Set(openInvoices.value.map((invoice) => invoice.id));
    const sameParty = isDisbursement
        ? props.selectedSupplierId === props.initial.supplierId
        : props.selectedCustomerId === props.initial.customerId;

    return sameParty
        ? props.savedAllocations.filter((line) => !open.has(line[savedInvoiceKey])).length
        : 0;
});

const partyOptions = computed(() => (isDisbursement ? props.suppliers : props.customers)
    .map((party) => ({ value: String(party.id), label: `${party.code} - ${party.name}` })));
const partyField = isDisbursement ? 'supplier_id' : 'customer_id';
const partyLabel = isDisbursement ? 'Pemasok' : 'Pelanggan';
const partyWord = partyLabel.toLowerCase();
const accountOptions = computed(() => props.cashBankAccounts.map((a) => ({ value: String(a.id), label: `${a.code} - ${a.name}` })));

const clientError = ref(null);

const { formRef } = useFocusFirstField(() => form.errors);
const { busy, submit: submitWithLock } = useSubmitLock();

const reloadUrl = computed(() => (props.paymentId ? `/accounting/payments/${props.paymentId}/edit` : '/accounting/payments/create'));
const cancelHref = computed(() => (props.paymentId ? `/accounting/payments/${props.paymentId}` : '/accounting/payments'));

function onPartyChange(value) {
    form[partyField] = value;
    savedByInvoice.value = {};

    const query = isDisbursement
        ? { payment_type: 'disbursement', ...(value ? { supplier_id: value } : {}) }
        : (value ? { customer_id: value } : {});

    router.get(reloadUrl.value, query, {
        only: isDisbursement
            ? ['outstandingPurchaseInvoices', 'selectedSupplierId']
            : ['outstandingInvoices', 'selectedCustomerId'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const allocation = ref({
    lines: [],
    amountIsValid: false,
    exceedsAmount: false,
    hasRowProblems: false,
    fullyAllocated: false,
});

const canPost = computed(() => can('payments.post'));

// Server errors are indexed by submitted line, and blank rows are left out of the submission.
const rowErrors = computed(() => {
    const errors = {};

    form.allocations.forEach((line, index) => {
        const message = form.errors[`allocations.${index}.amount`] || form.errors[`allocations.${index}.${invoiceKey}`];

        if (message) {
            errors[line[invoiceKey]] = message;
        }
    });

    return errors;
});

function prepare() {
    clientError.value = null;

    if (allocation.value.hasRowProblems) {
        clientError.value = 'Periksa kembali alokasi pada baris yang ditandai.';

        return false;
    }

    if (!allocation.value.amountIsValid) {
        clientError.value = 'Isi jumlah pembayaran dengan angka, maksimal 2 desimal.';

        return false;
    }

    if (allocation.value.exceedsAmount) {
        clientError.value = 'Jumlah alokasi melebihi jumlah pembayaran.';

        return false;
    }

    form.amount = String(form.amount).trim();
    form.allocations = allocation.value.lines.map((line) => ({ [invoiceKey]: line.invoiceId, amount: line.amount }));

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
    if (!allocation.value.fullyAllocated || !prepare()) {
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
                <FormField v-slot="{ id, invalid }" :label="partyLabel" required :error="form.errors[partyField]">
                    <Select
                        :id="id"
                        :model-value="form[partyField]"
                        :options="partyOptions"
                        :placeholder="`Pilih ${partyWord}`"
                        :invalid="invalid"
                        :disabled="form.processing"
                        @update:model-value="onPartyChange"
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

        <AllocationEditor
            :invoices="openInvoices"
            :amount="form.amount"
            :saved="savedByInvoice"
            :row-errors="rowErrors"
            :error="form.errors.allocations"
            :disabled="form.processing"
            :party-chosen="Boolean(form[partyField])"
            :description="`Faktur terbuka milik ${partyWord}, jatuh tempo paling lama lebih dulu.`"
            :choose-party-message="`Pilih ${partyWord} untuk melihat faktur terbukanya.`"
            :no-invoices-message="`${partyLabel} ini tidak memiliki faktur terbuka.`"
            @change="allocation = $event"
        />

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
                :disabled="busy || form.processing || !allocation.fullyAllocated"
                :aria-busy="busy"
                @click="saveAndPost"
            >
                <CheckCircle2 class="w-4 h-4 mr-1.5" aria-hidden="true" />
                Simpan dan Posting
            </Button>
        </div>
    </form>
</template>
