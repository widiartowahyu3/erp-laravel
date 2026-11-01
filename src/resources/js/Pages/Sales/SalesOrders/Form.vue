<script setup>
import { computed, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { Plus, Trash2, Save, ArrowLeft } from 'lucide-vue-next';
import { useFocusFirstField } from '@/composables/useFocusFirstField';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import Select from '@/Components/ui/Select.vue';
import Button from '@/Components/ui/Button.vue';
import IconButton from '@/Components/ui/IconButton.vue';
import Card from '@/Components/ui/Card.vue';
import Alert from '@/Components/ui/Alert.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import { addDecimals, calculateSalesOrderItem, trimDecimal } from '@/Support/decimal';
import { useSubmitLock } from '@/composables/useSubmitLock';

const props = defineProps({
    order: {
        type: Object,
        default: null,
    },
    customers: {
        type: Array,
        default: () => [],
    },
    warehouses: {
        type: Array,
        default: () => [],
    },
    paymentTerms: {
        type: Array,
        default: () => [],
    },
    taxRates: {
        type: Array,
        default: () => [],
    },
    products: {
        type: Array,
        default: () => [],
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
});

const today = new Date().toISOString().split('T')[0];

const initialItems = props.order?.items && props.order.items.length > 0
    ? props.order.items.map((it, idx) => ({
        line_no: it.line_no || idx + 1,
        product_id: String(it.product_id),
        quantity: trimDecimal(it.quantity || '1'),
        unit_price: trimDecimal(it.unit_price || '0'),
        discount_amount: trimDecimal(it.discount_amount || '0'),
        tax_rate_id: it.tax_rate_id ? String(it.tax_rate_id) : '',
    }))
    : [
        {
            line_no: 1,
            product_id: '',
            quantity: '1',
            unit_price: '0',
            discount_amount: '0',
            tax_rate_id: '',
        },
    ];

const form = useForm({
    customer_id: props.order?.customer_id ? String(props.order.customer_id) : '',
    warehouse_id: props.order?.warehouse_id ? String(props.order.warehouse_id) : '',
    order_date: props.order?.order_date
        ? String(props.order.order_date).split('T')[0]
        : today,
    payment_term_id: props.order?.payment_term_id ? String(props.order.payment_term_id) : '',
    notes: props.order?.notes || '',
    items: initialItems,
});

const { formRef } = useFocusFirstField(() => form.errors);

const customerOptions = computed(() => [
    { value: '', label: '-- Pilih Pelanggan --' },
    ...props.customers.map((c) => ({
        value: String(c.id),
        label: `${c.code} - ${c.name}`,
    })),
]);

const warehouseOptions = computed(() => [
    { value: '', label: '-- Pilih Gudang --' },
    ...props.warehouses.map((w) => ({
        value: String(w.id),
        label: `${w.code} - ${w.name}`,
    })),
]);

const paymentTermOptions = computed(() => [
    { value: '', label: '-- Tanpa Termin Khusus (COD/Tunai) --' },
    ...props.paymentTerms.map((pt) => ({
        value: String(pt.id),
        label: `${pt.code} - ${pt.name} (${pt.days} hari)`,
    })),
]);

const taxRateOptions = computed(() => [
    { value: '', label: 'Tanpa Pajak (0%)' },
    ...props.taxRates.map((tr) => ({
        value: String(tr.id),
        label: `${tr.name} (${tr.rate}%)`,
    })),
]);

const productOptions = computed(() => [
    { value: '', label: '-- Pilih Produk --' },
    ...props.products.map((p) => ({
        value: String(p.id),
        label: `${p.sku} - ${p.name}`,
    })),
]);

// Auto-fill payment_term_id when customer changes and term not explicitly chosen
watch(() => form.customer_id, (newCustomerId) => {
    if (!newCustomerId || props.isEdit) return;
    const cust = props.customers.find((c) => String(c.id) === String(newCustomerId));
    if (cust && cust.payment_term_id && !form.payment_term_id) {
        form.payment_term_id = String(cust.payment_term_id);
    }
});

function onProductChange(item, newProductId = null) {
    const pid = newProductId || item.product_id;
    if (!pid) return;
    const prod = props.products.find((p) => String(p.id) === String(pid));
    if (prod && (item.unit_price === '0.0000' || item.unit_price === '0' || !item.unit_price)) {
        item.unit_price = trimDecimal(prod.selling_price || '0');
    }
}

function addItem() {
    form.items.push({
        line_no: form.items.length + 1,
        product_id: '',
        quantity: '1',
        unit_price: '0',
        discount_amount: '0',
        tax_rate_id: '',
    });
}

function removeItem(index) {
    if (form.items.length <= 1) return;
    form.items.splice(index, 1);
    form.items.forEach((it, idx) => {
        it.line_no = idx + 1;
    });
}

// Compute live totals
const taxRatesMap = computed(() => {
    const map = {};
    props.taxRates.forEach((tr) => {
        map[String(tr.id)] = String(tr.rate);
    });
    return map;
});

const calculatedLines = computed(() => {
    return form.items.map((it) => {
        const taxRatePercent = it.tax_rate_id ? (taxRatesMap.value[String(it.tax_rate_id)] || '0') : '0';
        return calculateSalesOrderItem(it.quantity, it.unit_price, it.discount_amount, taxRatePercent);
    });
});

const calculatedSummary = computed(() => {
    let subtotal = '0.00';
    let discount = '0.00';
    let tax = '0.00';
    let total = '0.00';

    calculatedLines.value.forEach((line) => {
        subtotal = addDecimals(subtotal, line.lineGross, 2);
        discount = addDecimals(discount, line.discountAmount, 2);
        tax = addDecimals(tax, line.taxAmount, 2);
        total = addDecimals(total, line.lineTotal, 2);
    });

    return {
        subtotal,
        discount,
        tax,
        total,
    };
});

const { busy, submit: submitWithLock } = useSubmitLock();

function submit() {
    submitWithLock((options) => {
        if (props.isEdit) {
            form.put(`/sales/sales-orders/${props.order.id}`, {
                ...options,
            });
        } else {
            form.post('/sales/sales-orders', {
                ...options,
            });
        }
    });
}
</script>

<template>
    <form ref="formRef" class="space-y-6" @submit.prevent="submit">
        <Alert v-if="form.errors.status" variant="danger">
            {{ form.errors.status }}
        </Alert>
        <Alert v-if="form.errors.form" variant="danger">
            {{ form.errors.form }}
        </Alert>

        <!-- Informasi Utama SO -->
        <Card>
            <template #title>Informasi Pesanan Penjualan</template>
            <template #description>Identitas pelanggan, lokasi gudang pengiriman, dan termin transaksi.</template>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <FormField
                    label="Pelanggan"
                    required
                    :error="form.errors.customer_id"
                >
                    <Select
                        v-model="form.customer_id"
                        :options="customerOptions"
                        :disabled="form.processing"
                        aria-label="Pilih pelanggan"
                    />
                </FormField>

                <FormField
                    label="Gudang"
                    required
                    :error="form.errors.warehouse_id"
                >
                    <Select
                        v-model="form.warehouse_id"
                        :options="warehouseOptions"
                        :disabled="form.processing"
                        aria-label="Pilih gudang pengiriman"
                    />
                </FormField>

                <FormField
                    label="Syarat Pembayaran"
                    :error="form.errors.payment_term_id"
                >
                    <Select
                        v-model="form.payment_term_id"
                        :options="paymentTermOptions"
                        :disabled="form.processing"
                        aria-label="Pilih syarat pembayaran"
                    />
                </FormField>

                <FormField
                    label="Tanggal Pesanan"
                    required
                    :error="form.errors.order_date"
                >
                    <TextInput
                        v-model="form.order_date"
                        type="date"
                        :disabled="form.processing"
                        aria-label="Tanggal pesanan penjualan"
                    />
                </FormField>

                <div class="md:col-span-2 lg:col-span-2">
                    <FormField
                        label="Catatan / Keterangan"
                        :error="form.errors.notes"
                    >
                        <Textarea
                            v-model="form.notes"
                            rows="2"
                            placeholder="Catatan tambahan untuk pesanan ini..."
                            :disabled="form.processing"
                            aria-label="Catatan pesanan penjualan"
                        />
                    </FormField>
                </div>
            </div>
        </Card>

        <!-- Baris Rincian Barang -->
        <Card>
            <template #title>Rincian Barang yang Dipesan</template>
            <template #description>Tambahkan produk yang dipesan oleh pelanggan beserta kuantitas, harga, diskon, dan pajak.</template>

            <Alert v-if="form.errors.items" variant="danger" class="mb-4">
                {{ form.errors.items }}
            </Alert>

            <div class="overflow-x-auto -mx-4 sm:mx-0">
                <table class="w-full text-left text-sm border-collapse">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-400">
                            <th class="py-2 px-3 w-10 text-center">No</th>
                            <th class="py-2 px-3 min-w-[200px]">Produk</th>
                            <th class="py-2 px-3 w-32 text-right">Kuantitas</th>
                            <th class="py-2 px-3 w-36 text-right">Harga Satuan</th>
                            <th class="py-2 px-3 w-32 text-right">Diskon</th>
                            <th class="py-2 px-3 w-40">Pajak</th>
                            <th class="py-2 px-3 w-36 text-right">Total Baris</th>
                            <th class="py-2 px-3 w-12 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="(item, index) in form.items" :key="index" class="align-top">
                            <td class="py-3 px-3 text-center text-xs text-slate-500 pt-4">
                                {{ index + 1 }}
                            </td>
                            <td class="py-2.5 px-3">
                                <Select
                                    v-model="item.product_id"
                                    :options="productOptions"
                                    size="sm"
                                    :disabled="form.processing"
                                    :aria-label="`Pilih produk baris ke-${index + 1}`"
                                    @update:model-value="onProductChange(item, $event)"
                                />
                                <div v-if="form.errors[`items.${index}.product_id`]" class="text-xs text-rose-500 mt-1">
                                    {{ form.errors[`items.${index}.product_id`] }}
                                </div>
                            </td>
                            <td class="py-2.5 px-3">
                                <TextInput
                                    v-model="item.quantity"
                                    type="text"
                                    size="sm"
                                    class="text-right font-mono"
                                    placeholder="1"
                                    :disabled="form.processing"
                                    :aria-label="`Kuantitas barang baris ke-${index + 1}`"
                                />
                                <div v-if="form.errors[`items.${index}.quantity`]" class="text-xs text-rose-500 mt-1">
                                    {{ form.errors[`items.${index}.quantity`] }}
                                </div>
                            </td>
                            <td class="py-2.5 px-3">
                                <TextInput
                                    v-model="item.unit_price"
                                    type="text"
                                    size="sm"
                                    class="text-right font-mono"
                                    placeholder="0"
                                    :disabled="form.processing"
                                    :aria-label="`Harga satuan barang baris ke-${index + 1}`"
                                />
                                <div v-if="form.errors[`items.${index}.unit_price`]" class="text-xs text-rose-500 mt-1">
                                    {{ form.errors[`items.${index}.unit_price`] }}
                                </div>
                            </td>
                            <td class="py-2.5 px-3">
                                <TextInput
                                    v-model="item.discount_amount"
                                    type="text"
                                    size="sm"
                                    class="text-right font-mono"
                                    placeholder="0"
                                    :disabled="form.processing"
                                    :aria-label="`Diskon baris ke-${index + 1}`"
                                />
                                <div v-if="form.errors[`items.${index}.discount_amount`]" class="text-xs text-rose-500 mt-1">
                                    {{ form.errors[`items.${index}.discount_amount`] }}
                                </div>
                            </td>
                            <td class="py-2.5 px-3">
                                <Select
                                    v-model="item.tax_rate_id"
                                    :options="taxRateOptions"
                                    size="sm"
                                    :disabled="form.processing"
                                    :aria-label="`Pajak baris ke-${index + 1}`"
                                />
                                <div v-if="form.errors[`items.${index}.tax_rate_id`]" class="text-xs text-rose-500 mt-1">
                                    {{ form.errors[`items.${index}.tax_rate_id`] }}
                                </div>
                            </td>
                            <td class="py-3 px-3 text-right font-mono text-xs pt-4 font-semibold text-slate-800 dark:text-slate-200">
                                <Rupiah :amount="calculatedLines[index]?.lineTotal || '0.00'" />
                            </td>
                            <td class="py-2.5 px-3 text-center pt-3">
                                <IconButton
                                    variant="danger"
                                    size="sm"
                                    :disabled="form.items.length <= 1 || form.processing"
                                    title="Hapus Baris"
                                    :aria-label="`Hapus baris ke-${index + 1}`"
                                    @click="removeItem(index)"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </IconButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex justify-between items-center pt-2 border-t border-slate-100 dark:border-slate-800">
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    :disabled="form.processing"
                    @click="addItem"
                >
                    <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                    Tambah Baris Barang
                </Button>

                <!-- Ringkasan Nilai Pesanan -->
                <div class="w-72 space-y-2 text-sm bg-slate-50 dark:bg-slate-900/50 p-4 rounded-lg border border-slate-200 dark:border-slate-800">
                    <div class="flex justify-between text-xs text-slate-600 dark:text-slate-400">
                        <span>Subtotal (Bruto):</span>
                        <span class="font-mono font-medium text-slate-800 dark:text-slate-200">
                            <Rupiah :amount="calculatedSummary.subtotal" />
                        </span>
                    </div>
                    <div class="flex justify-between text-xs text-slate-600 dark:text-slate-400">
                        <span>Diskon Baris:</span>
                        <span class="font-mono font-medium text-slate-800 dark:text-slate-200">
                            <Rupiah :amount="calculatedSummary.discount" />
                        </span>
                    </div>
                    <div class="flex justify-between text-xs text-slate-600 dark:text-slate-400">
                        <span>Pajak (PPN):</span>
                        <span class="font-mono font-medium text-slate-800 dark:text-slate-200">
                            <Rupiah :amount="calculatedSummary.tax" />
                        </span>
                    </div>
                    <div class="flex justify-between text-sm font-bold text-slate-900 dark:text-slate-100 pt-2 border-t border-slate-200 dark:border-slate-700">
                        <span>Total Akhir:</span>
                        <span class="font-mono text-indigo-600 dark:text-indigo-400">
                            <Rupiah :amount="calculatedSummary.total" />
                        </span>
                    </div>
                </div>
            </div>
        </Card>

        <!-- Actions -->
        <div class="flex items-center justify-end gap-3 pt-4">
            <Link :href="isEdit ? `/sales/sales-orders/${order.id}` : '/sales/sales-orders'">
                <Button
                    type="button"
                    variant="secondary"
                    :disabled="form.processing"
                >
                    <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                    Batal
                </Button>
            </Link>

            <Button
                type="submit"
                variant="primary"
                :loading="form.processing || busy"
                :disabled="form.processing || busy"
                :aria-busy="form.processing || busy"
            >
                <Save class="w-4 h-4 mr-1.5" aria-hidden="true" />
                {{ isEdit ? 'Simpan Perubahan' : 'Simpan Draf Pesanan' }}
            </Button>
        </div>
    </form>
</template>
