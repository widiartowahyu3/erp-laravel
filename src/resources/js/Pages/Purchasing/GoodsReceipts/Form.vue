<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { Trash2, Save, ArrowLeft } from 'lucide-vue-next';
import { useFocusFirstField } from '@/composables/useFocusFirstField';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import Select from '@/Components/ui/Select.vue';
import Button from '@/Components/ui/Button.vue';
import IconButton from '@/Components/ui/IconButton.vue';
import Card from '@/Components/ui/Card.vue';
import Alert from '@/Components/ui/Alert.vue';
import Quantity from '@/Components/ui/Quantity.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import { subtractDecimals, compareDecimals, trimDecimal } from '@/Support/decimal';
import { useSubmitLock } from '@/composables/useSubmitLock';

const props = defineProps({
    receipt: {
        type: Object,
        default: null,
    },
    purchaseOrders: {
        type: Array,
        default: () => [],
    },
    selectedPoId: {
        type: [String, Number],
        default: '',
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
});

const today = new Date().toISOString().split('T')[0];

const initialItems = props.receipt?.items && props.receipt.items.length > 0
    ? props.receipt.items.map((it, idx) => ({
        line_no: it.line_no || idx + 1,
        purchase_order_item_id: String(it.purchase_order_item_id),
        product_id: String(it.product_id),
        quantity: trimDecimal(it.quantity || '1'),
        unit_cost: trimDecimal(it.unit_cost || '0'),
        product: it.product,
        purchase_order_item: it.purchase_order_item,
    }))
    : [];

const form = useForm({
    purchase_order_id: props.receipt?.purchase_order_id
        ? String(props.receipt.purchase_order_id)
        : (props.selectedPoId ? String(props.selectedPoId) : ''),
    supplier_id: props.receipt?.supplier_id ? String(props.receipt.supplier_id) : '',
    warehouse_id: props.receipt?.warehouse_id ? String(props.receipt.warehouse_id) : '',
    receipt_date: props.receipt?.receipt_date
        ? String(props.receipt.receipt_date).split('T')[0]
        : today,
    notes: props.receipt?.notes || '',
    items: initialItems,
});

const { formRef } = useFocusFirstField(() => form.errors);

const poOptions = computed(() => [
    { value: '', label: '-- Pilih Pesanan Pembelian (PO) --' },
    ...props.purchaseOrders.map((po) => ({
        value: String(po.id),
        label: `${po.number} (${po.supplier?.name || '-'})`,
    })),
]);

const currentSelectedPo = computed(() => {
    if (props.isEdit) {
        return props.receipt?.purchase_order || null;
    }
    if (!form.purchase_order_id) return null;
    return props.purchaseOrders.find((p) => String(p.id) === String(form.purchase_order_id)) || null;
});

function loadItemsFromSelectedPo() {
    if (props.isEdit || !currentSelectedPo.value) return;

    const po = currentSelectedPo.value;
    const newItems = [];

    form.supplier_id = String(po.supplier_id);
    form.warehouse_id = String(po.warehouse_id);

    if (Array.isArray(po.items)) {
        po.items.forEach((it) => {
            const remaining = subtractDecimals(it.quantity, it.received_quantity, 4);
            if (compareDecimals(remaining, '0', 4) > 0) {
                newItems.push({
                    line_no: newItems.length + 1,
                    purchase_order_item_id: String(it.id),
                    product_id: String(it.product_id),
                    quantity: trimDecimal(remaining),
                    unit_cost: trimDecimal(it.unit_price || '0'),
                    product: it.product,
                    purchase_order_item: it,
                    max_allowed: remaining,
                });
            }
        });
    }

    form.items = newItems;
}

watch(() => form.purchase_order_id, (newVal) => {
    if (!props.isEdit && newVal) {
        loadItemsFromSelectedPo();
    }
});

onMounted(() => {
    if (!props.isEdit && form.purchase_order_id && form.items.length === 0) {
        loadItemsFromSelectedPo();
    }
});

function removeItem(index) {
    if (form.items.length <= 1) return;
    form.items.splice(index, 1);
    form.items.forEach((it, idx) => {
        it.line_no = idx + 1;
    });
}

const { busy, submit: submitWithLock } = useSubmitLock();

function submit() {
    submitWithLock((options) => {
        if (props.isEdit) {
            form.put(`/purchasing/goods-receipts/${props.receipt.id}`, {
                ...options,
            });
        } else {
            form.post('/purchasing/goods-receipts', {
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

        <!-- Informasi Dokumen -->
        <Card>
            <template #header>
                <div class="space-y-0.5">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                        Informasi Penerimaan Barang
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Pilih pesanan pembelian acuan dan tanggal fisik kedatangan barang di gudang.
                    </p>
                </div>
            </template>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div v-if="isEdit" class="space-y-1">
                    <span class="text-xs font-semibold uppercase text-slate-500">No. PO Acuan</span>
                    <div class="text-sm font-semibold text-slate-800 dark:text-slate-100 font-mono">
                        {{ receipt.purchase_order?.number }}
                    </div>
                </div>
                <FormField
                    v-else
                    label="Pesanan Pembelian (PO)"
                    required
                    :error="form.errors.purchase_order_id"
                >
                    <Select
                        v-model="form.purchase_order_id"
                        :options="poOptions"
                        :disabled="form.processing"
                        aria-label="Pilih dokumen pesanan pembelian acuan"
                    />
                </FormField>

                <FormField
                    label="Tanggal Penerimaan Fisik"
                    required
                    :error="form.errors.receipt_date"
                >
                    <TextInput
                        v-model="form.receipt_date"
                        type="date"
                        :disabled="form.processing"
                        aria-label="Tanggal fisik kedatangan barang"
                    />
                </FormField>

                <div v-if="currentSelectedPo" class="space-y-1">
                    <span class="text-xs font-semibold uppercase text-slate-500">Pemasok & Gudang</span>
                    <div class="text-sm text-slate-800 dark:text-slate-100">
                        {{ currentSelectedPo.supplier?.name || receipt?.supplier?.name }}
                    </div>
                    <div class="text-xs text-slate-500">
                        Gudang: {{ currentSelectedPo.warehouse?.name || receipt?.warehouse?.name }}
                    </div>
                </div>

                <div class="md:col-span-2 lg:col-span-3">
                    <FormField
                        label="Catatan / Keterangan Surat Jalan Pemasok"
                        :error="form.errors.notes"
                    >
                        <Textarea
                            v-model="form.notes"
                            :rows="2"
                            placeholder="Contoh: No. Surat Jalan Pemasok: SJ-12345, kondisi kemasan baik..."
                            :disabled="form.processing"
                            aria-label="Catatan surat jalan atau keterangan penerimaan"
                        />
                    </FormField>
                </div>
            </div>
        </Card>

        <!-- Tabel Rincian Barang yang Diterima -->
        <Card>
            <template #header>
                <div class="space-y-0.5">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                        Rincian Barang yang Diterima
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Kuantitas yang diinput akan menambah saldo fisik gudang saat dokumen diposting.
                    </p>
                </div>
            </template>

            <Alert v-if="form.errors.items" variant="danger">
                {{ form.errors.items }}
            </Alert>

            <div v-if="form.items.length > 0" class="overflow-x-auto">
                <table class="w-full text-left text-sm divide-y divide-slate-200 dark:divide-slate-700">
                    <thead class="bg-slate-50 dark:bg-slate-800/60 text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th class="py-3 px-2 w-10 text-center">#</th>
                            <th class="py-3 px-3">Produk</th>
                            <th class="py-3 px-3 text-right">Pesanan PO</th>
                            <th class="py-3 px-3 text-right">Sudah Diterima</th>
                            <th class="py-3 px-3 text-right">Sisa PO</th>
                            <th class="py-3 px-3 w-36 text-right">Qty Diterima Saat Ini</th>
                            <th class="py-3 px-3 text-right">Biaya Satuan (Rp)</th>
                            <th v-if="!isEdit" class="py-3 px-2 w-12 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-for="(item, index) in form.items" :key="index" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40">
                            <td class="py-2.5 px-2 text-center text-xs text-slate-400 font-mono">
                                {{ index + 1 }}
                            </td>
                            <td class="py-2.5 px-3">
                                <div class="font-medium text-slate-800 dark:text-slate-100">
                                    {{ item.product?.name }}
                                </div>
                                <div class="text-xs text-slate-400 font-mono">
                                    {{ item.product?.sku }}
                                </div>
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono text-slate-600 dark:text-slate-400">
                                <Quantity :value="item.purchase_order_item?.quantity || '0'" :unit="item.product?.unit?.code" />
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono text-emerald-600 dark:text-emerald-400">
                                <Quantity :value="item.purchase_order_item?.received_quantity || '0'" :unit="item.product?.unit?.code" />
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono font-medium text-amber-600 dark:text-amber-400">
                                <Quantity
                                    :value="subtractDecimals(item.purchase_order_item?.quantity || '0', item.purchase_order_item?.received_quantity || '0', 4)"
                                    :unit="item.product?.unit?.code"
                                />
                            </td>
                            <td class="py-2.5 px-3">
                                <TextInput
                                    v-model="item.quantity"
                                    type="text"
                                    size="sm"
                                    class="text-right font-mono"
                                    placeholder="1"
                                    :disabled="form.processing"
                                    :aria-label="`Kuantitas penerimaan baris ke-${index + 1}`"
                                />
                                <div v-if="form.errors[`items.${index}.quantity`]" class="text-xs text-rose-500 mt-1 text-right">
                                    {{ form.errors[`items.${index}.quantity`] }}
                                </div>
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono text-slate-700 dark:text-slate-200">
                                <Rupiah :value="item.unit_cost" />
                            </td>
                            <td v-if="!isEdit" class="py-2.5 px-2 text-center">
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
            <div v-else class="text-center py-8 text-slate-400 italic text-sm">
                Pilih pesanan pembelian (PO) terlebih dahulu untuk memuat daftar barang yang siap diterima.
            </div>
        </Card>

        <!-- Aksi Form -->
        <div class="flex items-center justify-end gap-3">
            <Link :href="isEdit ? `/purchasing/goods-receipts/${receipt.id}` : '/purchasing/goods-receipts'">
                <Button variant="secondary" type="button" :disabled="form.processing">
                    <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                    Batal
                </Button>
            </Link>

            <Button
                type="submit"
                variant="primary"
                :loading="busy || form.processing"
                :disabled="busy || form.processing || form.items.length === 0"
                :aria-busy="busy || form.processing"
            >
                <Save class="w-4 h-4 mr-1.5" aria-hidden="true" />
                {{ (busy || form.processing) ? 'Menyimpan...' : (isEdit ? 'Simpan Perubahan Draf' : 'Simpan Draf Penerimaan Barang') }}
            </Button>
        </div>
    </form>
</template>
