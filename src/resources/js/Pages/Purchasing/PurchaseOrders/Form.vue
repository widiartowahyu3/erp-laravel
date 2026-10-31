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
import { addDecimals, calculatePurchaseOrderItem } from '@/Support/decimal';
import { useSubmitLock } from '@/composables/useSubmitLock';

const props = defineProps({
    order: {
        type: Object,
        default: null,
    },
    suppliers: {
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
        quantity: String(it.quantity || '1.0000'),
        unit_price: String(it.unit_price || '0.0000'),
        discount_amount: String(it.discount_amount || '0.00'),
        tax_rate_id: it.tax_rate_id ? String(it.tax_rate_id) : '',
    }))
    : [
        {
            line_no: 1,
            product_id: '',
            quantity: '1.0000',
            unit_price: '0.0000',
            discount_amount: '0.00',
            tax_rate_id: '',
        },
    ];

const form = useForm({
    supplier_id: props.order?.supplier_id ? String(props.order.supplier_id) : '',
    warehouse_id: props.order?.warehouse_id ? String(props.order.warehouse_id) : '',
    order_date: props.order?.order_date
        ? String(props.order.order_date).split('T')[0]
        : today,
    expected_date: props.order?.expected_date
        ? String(props.order.expected_date).split('T')[0]
        : '',
    payment_term_id: props.order?.payment_term_id ? String(props.order.payment_term_id) : '',
    notes: props.order?.notes || '',
    items: initialItems,
});

const { formRef } = useFocusFirstField(() => form.errors);

const supplierOptions = computed(() => [
    { value: '', label: '-- Pilih Mitra Pemasok --' },
    ...props.suppliers.map((s) => ({
        value: String(s.id),
        label: `${s.code} - ${s.name}`,
    })),
]);

const warehouseOptions = computed(() => [
    { value: '', label: '-- Pilih Fasilitas Gudang --' },
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
    { value: '', label: '-- Pilih Produk Dagang --' },
    ...props.products.map((p) => ({
        value: String(p.id),
        label: `${p.sku} - ${p.name}`,
    })),
]);

// Auto-fill payment_term_id when supplier changes and term not explicitly chosen
watch(() => form.supplier_id, (newSupplierId) => {
    if (!newSupplierId || props.isEdit) return;
    const sup = props.suppliers.find((s) => String(s.id) === String(newSupplierId));
    if (sup && sup.payment_term_id && !form.payment_term_id) {
        form.payment_term_id = String(sup.payment_term_id);
    }
});

function addItem() {
    form.items.push({
        line_no: form.items.length + 1,
        product_id: '',
        quantity: '1.0000',
        unit_price: '0.0000',
        discount_amount: '0.00',
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
        return calculatePurchaseOrderItem(it.quantity, it.unit_price, it.discount_amount, taxRatePercent);
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
            form.put(`/purchasing/purchase-orders/${props.order.id}`, {
                ...options,
            });
        } else {
            form.post('/purchasing/purchase-orders', {
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

        <!-- Informasi Utama PO -->
        <Card>
            <template #title>Informasi Pesanan Pembelian</template>
            <template #description>Identitas mitra pemasok, lokasi penerimaan gudang, dan termin transaksi.</template>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <FormField
                    label="Mitra Pemasok"
                    required
                    :error="form.errors.supplier_id"
                >
                    <Select
                        v-model="form.supplier_id"
                        :options="supplierOptions"
                        :disabled="form.processing"
                        aria-label="Pilih mitra pemasok"
                    />
                </FormField>

                <FormField
                    label="Gudang Tujuan"
                    required
                    :error="form.errors.warehouse_id"
                >
                    <Select
                        v-model="form.warehouse_id"
                        :options="warehouseOptions"
                        :disabled="form.processing"
                        aria-label="Pilih gudang tujuan penerimaan barang"
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
                        aria-label="Tanggal pesanan dibuat"
                    />
                </FormField>

                <FormField
                    label="Estimasi Kedatangan"
                    :error="form.errors.expected_date"
                >
                    <TextInput
                        v-model="form.expected_date"
                        type="date"
                        :disabled="form.processing"
                        aria-label="Estimasi tanggal kedatangan barang"
                    />
                </FormField>

                <div class="md:col-span-2 lg:col-span-3">
                    <FormField
                        label="Catatan / Instruksi Pengiriman"
                        :error="form.errors.notes"
                    >
                        <Textarea
                            v-model="form.notes"
                            :rows="2"
                            placeholder="Catatan tambahan untuk pemasok atau bagian logistik gudang..."
                            :disabled="form.processing"
                            aria-label="Catatan atau instruksi pesanan"
                        />
                    </FormField>
                </div>
            </div>
        </Card>

        <!-- Daftar Baris Item PO -->
        <Card>
            <template #title>Rincian Barang yang Dipesan</template>
            <template #description>Pilih barang dagang, kuantitas, harga penawaran satuan, diskon, dan tarif pajak.</template>
            <template #actions>
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
            </template>

            <Alert v-if="form.errors.items" variant="danger">
                {{ form.errors.items }}
            </Alert>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm divide-y divide-slate-200 dark:divide-slate-700">
                    <thead class="bg-slate-50 dark:bg-slate-800/60 text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th class="py-3 px-2 w-10 text-center">#</th>
                            <th class="py-3 px-3 min-w-[200px]">Barang Dagang</th>
                            <th class="py-3 px-3 w-32 text-right">Kuantitas</th>
                            <th class="py-3 px-3 w-36 text-right">Harga Satuan (Rp)</th>
                            <th class="py-3 px-3 w-32 text-right">Diskon (Rp)</th>
                            <th class="py-3 px-3 w-36">Pajak</th>
                            <th class="py-3 px-3 w-36 text-right">Subtotal (Rp)</th>
                            <th class="py-3 px-2 w-12 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-for="(item, index) in form.items" :key="index" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40">
                            <td class="py-2.5 px-2 text-center text-xs text-slate-400 font-mono">
                                {{ index + 1 }}
                            </td>
                            <td class="py-2.5 px-3">
                                <Select
                                    v-model="item.product_id"
                                    :options="productOptions"
                                    size="sm"
                                    :disabled="form.processing"
                                    :aria-label="`Pilih produk baris ke-${index + 1}`"
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
                                    placeholder="1.0000"
                                    :disabled="form.processing"
                                    :aria-label="`Kuantitas barang baris ke-${index + 1}`"
                                />
                                <div v-if="form.errors[`items.${index}.quantity`]" class="text-xs text-rose-500 mt-1 text-right">
                                    {{ form.errors[`items.${index}.quantity`] }}
                                </div>
                            </td>
                            <td class="py-2.5 px-3">
                                <TextInput
                                    v-model="item.unit_price"
                                    type="text"
                                    size="sm"
                                    class="text-right font-mono"
                                    placeholder="0.0000"
                                    :disabled="form.processing"
                                    :aria-label="`Harga satuan barang baris ke-${index + 1}`"
                                />
                                <div v-if="form.errors[`items.${index}.unit_price`]" class="text-xs text-rose-500 mt-1 text-right">
                                    {{ form.errors[`items.${index}.unit_price`] }}
                                </div>
                            </td>
                            <td class="py-2.5 px-3">
                                <TextInput
                                    v-model="item.discount_amount"
                                    type="text"
                                    size="sm"
                                    class="text-right font-mono"
                                    placeholder="0.00"
                                    :disabled="form.processing"
                                    :aria-label="`Diskon baris ke-${index + 1}`"
                                />
                                <div v-if="form.errors[`items.${index}.discount_amount`] || form.errors[`items.${index}.discount_percent`]" class="text-xs text-rose-500 mt-1 text-right">
                                    {{ form.errors[`items.${index}.discount_amount`] || form.errors[`items.${index}.discount_percent`] }}
                                </div>
                            </td>
                            <td class="py-2.5 px-3">
                                <Select
                                    v-model="item.tax_rate_id"
                                    :options="taxRateOptions"
                                    size="sm"
                                    :disabled="form.processing"
                                    :aria-label="`Tarif pajak baris ke-${index + 1}`"
                                />
                                <div v-if="form.errors[`items.${index}.tax_rate_id`]" class="text-xs text-rose-500 mt-1">
                                    {{ form.errors[`items.${index}.tax_rate_id`] }}
                                </div>
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono text-sm font-medium text-slate-800 dark:text-slate-100">
                                <Rupiah :value="calculatedLines[index]?.lineTotal || '0.00'" />
                            </td>
                            <td class="py-2.5 px-2 text-center">
                                <IconButton
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="form.items.length <= 1 || form.processing"
                                    :aria-label="`Hapus baris ke-${index + 1}`"
                                    title="Hapus baris"
                                    @click="removeItem(index)"
                                >
                                    <Trash2 class="w-4 h-4 text-rose-500" aria-hidden="true" />
                                </IconButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Ringkasan Realtime -->
            <div class="mt-6 pt-4 border-t border-slate-200 dark:border-slate-700 flex justify-end">
                <div class="w-full sm:w-72 space-y-2 text-sm">
                    <div class="flex justify-between text-slate-600 dark:text-slate-300">
                        <span>Subtotal Kotor:</span>
                        <span class="font-mono font-medium"><Rupiah :value="calculatedSummary.subtotal" /></span>
                    </div>
                    <div class="flex justify-between text-slate-600 dark:text-slate-300">
                        <span>Total Diskon:</span>
                        <span class="font-mono font-medium text-rose-600">- <Rupiah :value="calculatedSummary.discount" /></span>
                    </div>
                    <div class="flex justify-between text-slate-600 dark:text-slate-300">
                        <span>Pajak (PPN/PPh):</span>
                        <span class="font-mono font-medium text-emerald-600">+ <Rupiah :value="calculatedSummary.tax" /></span>
                    </div>
                    <div class="pt-2 border-t border-slate-300 dark:border-slate-600 flex justify-between font-bold text-base text-slate-900 dark:text-slate-100">
                        <span>Grand Total:</span>
                        <span class="font-mono text-indigo-600 dark:text-indigo-400"><Rupiah :value="calculatedSummary.total" /></span>
                    </div>
                </div>
            </div>
        </Card>

        <!-- Aksi Form -->
        <div class="flex items-center justify-end gap-3">
            <Link :href="isEdit ? `/purchasing/purchase-orders/${order.id}` : '/purchasing/purchase-orders'">
                <Button variant="secondary" type="button" :disabled="form.processing">
                    <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                    Batal
                </Button>
            </Link>

            <Button
                type="submit"
                variant="primary"
                :loading="busy || form.processing"
                :disabled="busy || form.processing"
                :aria-busy="busy || form.processing"
            >
                <Save class="w-4 h-4 mr-1.5" aria-hidden="true" />
                {{ (busy || form.processing) ? 'Menyimpan...' : (isEdit ? 'Simpan Perubahan Draf' : 'Buat Pesanan Pembelian') }}
            </Button>
        </div>
    </form>
</template>
