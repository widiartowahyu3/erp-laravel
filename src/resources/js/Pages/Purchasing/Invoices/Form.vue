<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Save, ArrowLeft } from 'lucide-vue-next';
import Card from '@/Components/ui/Card.vue';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import Button from '@/Components/ui/Button.vue';
import Alert from '@/Components/ui/Alert.vue';
import Quantity from '@/Components/ui/Quantity.vue';
import { useSubmitLock } from '@/composables/useSubmitLock';
import { useFocusFirstField } from '@/composables/useFocusFirstField';
import { compareDecimals, trimDecimal } from '@/Support/decimal';

const props = defineProps({
    purchaseOrder: {
        type: Object,
        required: true,
    },
    eligibleItems: {
        type: Array,
        default: () => [],
    },
    initial: {
        type: Object,
        required: true,
    },
    invoiceId: {
        type: Number,
        default: null,
    },
});

const rows = ref(props.eligibleItems.map((line) => ({
    ...line,
    billed: compareDecimals(line.quantity, '0') > 0 ? trimDecimal(line.quantity) : '',
})));

const form = useForm({
    purchase_order_id: props.purchaseOrder.id,
    supplier_invoice_number: props.initial.supplierInvoiceNumber || '',
    invoice_date: props.initial.invoiceDate,
    due_date: props.initial.dueDate,
    notes: props.initial.notes || '',
    items: [],
});

const clientError = ref(null);

const { formRef } = useFocusFirstField(() => form.errors);
const { busy, submit: submitWithLock } = useSubmitLock();

const cancelHref = computed(() => (props.invoiceId ? `/purchasing/invoices/${props.invoiceId}` : '/purchasing/invoices'));

function isBlank(row) {
    return row.billed === '' || row.billed === null;
}

function isNumber(row) {
    return /^\d+(\.\d{1,4})?$/.test(String(row.billed).trim());
}

function hasQuantity(row) {
    return !isBlank(row) && isNumber(row) && compareDecimals(row.billed, '0') > 0;
}

function exceedsRemainder(row) {
    return hasQuantity(row) && compareDecimals(row.billed, row.remainingQuantity) > 0;
}

function clientRowError(row) {
    if (!isBlank(row) && !isNumber(row)) {
        return 'Isi dengan angka, maksimal 4 desimal. Gunakan titik sebagai pemisah desimal.';
    }

    return exceedsRemainder(row) ? 'Melebihi sisa yang dapat ditagih.' : null;
}

// Server errors are indexed by submitted line, and blank rows are left out of the submission.
function rowError(row) {
    const index = form.items.findIndex((item) => item.goods_receipt_item_id === row.goodsReceiptItemId);

    if (index === -1) {
        return clientRowError(row);
    }

    return form.errors[`items.${index}.quantity`]
        || form.errors[`items.${index}.goods_receipt_item_id`]
        || clientRowError(row);
}

function validate() {
    if (form.supplier_invoice_number.trim() === '') {
        return 'Isi nomor faktur pemasok.';
    }

    if (rows.value.some(clientRowError)) {
        return 'Periksa kembali kuantitas ditagih pada baris yang ditandai.';
    }

    if (!rows.value.some(hasQuantity)) {
        return 'Isi kuantitas ditagih pada minimal satu baris.';
    }

    if (form.due_date && form.invoice_date && form.due_date < form.invoice_date) {
        return 'Tanggal jatuh tempo tidak boleh sebelum tanggal faktur.';
    }

    return null;
}

function submit() {
    clientError.value = validate();

    if (clientError.value) {
        return;
    }

    form.items = rows.value.filter(hasQuantity).map((row) => ({
        goods_receipt_item_id: row.goodsReceiptItemId,
        quantity: row.billed,
    }));

    submitWithLock((options) => {
        if (props.invoiceId) {
            form.put(`/purchasing/invoices/${props.invoiceId}`, options);
        } else {
            form.post('/purchasing/invoices', options);
        }
    });
}
</script>

<template>
    <form ref="formRef" class="space-y-6" @submit.prevent="submit">
        <Alert v-if="clientError" variant="danger">
            {{ clientError }}
        </Alert>
        <Alert v-if="form.errors.status" variant="danger">
            {{ form.errors.status }}
        </Alert>
        <Alert v-if="form.errors.supplier_id" variant="danger">
            {{ form.errors.supplier_id }}
        </Alert>
        <Alert v-if="form.errors.purchase_order_id" variant="danger">
            {{ form.errors.purchase_order_id }}
        </Alert>
        <Alert v-if="form.errors.form" variant="danger">
            {{ form.errors.form }}
        </Alert>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <Card class="md:col-span-2">
                <template #header>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                        Informasi Faktur
                    </h2>
                </template>

                <div class="space-y-4">
                    <FormField
                        v-slot="{ id, invalid }"
                        label="Nomor Faktur Pemasok"
                        required
                        :error="form.errors.supplier_invoice_number"
                    >
                        <TextInput
                            :id="id"
                            v-model="form.supplier_invoice_number"
                            :invalid="invalid"
                            :disabled="form.processing"
                            :max-length="50"
                        />
                    </FormField>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <FormField
                            v-slot="{ id, invalid }"
                            label="Tanggal Faktur"
                            required
                            :error="form.errors.invoice_date"
                        >
                            <TextInput
                                :id="id"
                                v-model="form.invoice_date"
                                type="date"
                                :invalid="invalid"
                                :disabled="form.processing"
                            />
                        </FormField>

                        <FormField
                            v-slot="{ id, invalid }"
                            label="Jatuh Tempo"
                            required
                            :error="form.errors.due_date"
                        >
                            <TextInput
                                :id="id"
                                v-model="form.due_date"
                                type="date"
                                :invalid="invalid"
                                :disabled="form.processing"
                            />
                        </FormField>
                    </div>

                    <FormField v-slot="{ id, invalid }" label="Catatan" :error="form.errors.notes">
                        <Textarea
                            :id="id"
                            v-model="form.notes"
                            :rows="2"
                            :invalid="invalid"
                            :disabled="form.processing"
                        />
                    </FormField>
                </div>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                        Referensi Pesanan (PO)
                    </h2>
                </template>

                <div class="space-y-3 text-sm">
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block">No. PO:</span>
                        <Link
                            :href="`/purchasing/purchase-orders/${purchaseOrder.id}`"
                            class="font-mono font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                        >
                            {{ purchaseOrder.number }}
                        </Link>
                    </div>

                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block">Pemasok:</span>
                        <span class="font-medium text-slate-800 dark:text-slate-200">
                            {{ purchaseOrder.supplier?.code }} - {{ purchaseOrder.supplier?.name }}
                        </span>
                    </div>

                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block">Termin:</span>
                        <span class="font-medium text-slate-800 dark:text-slate-200">
                            {{ purchaseOrder.termDays }} hari
                        </span>
                    </div>
                </div>
            </Card>
        </div>

        <Card>
            <template #header>
                <div class="space-y-0.5">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                        Barang yang Ditagih
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Harga, diskon, dan pajak dihitung server dari pesanan pembelian. Kosongkan kuantitas untuk baris yang tidak ditagih.
                    </p>
                </div>
            </template>

            <Alert v-if="form.errors.items" variant="danger" class="mb-4">
                {{ form.errors.items }}
            </Alert>

            <div class="overflow-x-auto -mx-4 sm:mx-0">
                <table class="w-full text-left text-sm border-collapse">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-400">
                            <th class="py-2.5 px-3">No. GR</th>
                            <th class="py-2.5 px-3">Produk</th>
                            <th class="py-2.5 px-3 text-right">Kuantitas GR</th>
                            <th class="py-2.5 px-3 text-right">Sisa</th>
                            <th class="py-2.5 px-3 w-44 text-right">Kuantitas Ditagih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="row in rows" :key="row.goodsReceiptItemId" class="align-top">
                            <td class="py-3 px-3 font-mono text-xs">{{ row.goodsReceiptNumber }}</td>
                            <td class="py-3 px-3 text-slate-800 dark:text-slate-200">{{ row.productName }}</td>
                            <td class="py-3 px-3 text-right">
                                <Quantity :value="row.receivedQuantity" />
                            </td>
                            <td class="py-3 px-3 text-right">
                                <Quantity :value="row.remainingQuantity" />
                            </td>
                            <td class="py-3 px-3">
                                <TextInput
                                    v-model="row.billed"
                                    inputmode="decimal"
                                    class="text-right"
                                    :disabled="form.processing"
                                    :aria-label="`Kuantitas ditagih untuk ${row.productName} pada ${row.goodsReceiptNumber}`"
                                />
                                <p v-if="rowError(row)" class="mt-1 text-xs text-red-700 dark:text-red-400">
                                    {{ rowError(row) }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <div class="flex items-center justify-end gap-2">
            <Link :href="cancelHref">
                <Button type="button" variant="secondary">
                    <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                    Batal
                </Button>
            </Link>

            <Button
                type="submit"
                variant="primary"
                :loading="busy"
                :disabled="busy || form.processing"
                :aria-busy="busy"
            >
                <Save class="w-4 h-4 mr-1.5" aria-hidden="true" />
                Simpan Draf
            </Button>
        </div>
    </form>
</template>
