<script setup>
import { computed } from 'vue';
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
import Quantity from '@/Components/ui/Quantity.vue';
import { subtractDecimals } from '@/Support/decimal';
import { useSubmitLock } from '@/composables/useSubmitLock';

const props = defineProps({
    adjustment: {
        type: Object,
        default: null,
    },
    warehouses: {
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

const initialItems = props.adjustment?.items && props.adjustment.items.length > 0
    ? props.adjustment.items.map((it, idx) => ({
        line_no: it.line_no || idx + 1,
        product_id: String(it.product_id),
        system_quantity: String(it.system_quantity || '0.0000'),
        counted_quantity: String(it.counted_quantity || '0.0000'),
        unit_cost: String(it.unit_cost || '0.0000'),
    }))
    : [
        {
            line_no: 1,
            product_id: '',
            system_quantity: '0.0000',
            counted_quantity: '0.0000',
            unit_cost: '0.0000',
        },
    ];

const form = useForm({
    warehouse_id: props.adjustment?.warehouse_id ? String(props.adjustment.warehouse_id) : '',
    adjustment_date: props.adjustment?.adjustment_date
        ? String(props.adjustment.adjustment_date).split('T')[0]
        : today,
    reason: props.adjustment?.reason || '',
    notes: props.adjustment?.notes || '',
    items: initialItems,
});

const { formRef } = useFocusFirstField(() => form.errors);

const warehouseOptions = computed(() => [
    { value: '', label: '-- Pilih Fasilitas Gudang --' },
    ...props.warehouses.map((w) => ({
        value: String(w.id),
        label: `${w.code} - ${w.name}`,
    })),
]);

const productOptions = computed(() => [
    { value: '', label: '-- Pilih Produk Dagang --' },
    ...props.products.map((p) => ({
        value: String(p.id),
        label: `${p.sku} - ${p.name}`,
    })),
]);

function addItem() {
    form.items.push({
        line_no: form.items.length + 1,
        product_id: '',
        system_quantity: '0.0000',
        counted_quantity: '0.0000',
        unit_cost: '0.0000',
    });
}

function removeItem(index) {
    if (form.items.length <= 1) return;
    form.items.splice(index, 1);
    form.items.forEach((it, idx) => {
        it.line_no = idx + 1;
    });
}

const { busy, submit: submitWithLock } = useSubmitLock();

function calculateDifference(item) {
    return subtractDecimals(item.counted_quantity || '0.0000', item.system_quantity || '0.0000', 4);
}

function submit() {
    submitWithLock((options) => {
        if (props.isEdit) {
            form.put(`/inventory/stock-adjustments/${props.adjustment.id}`, {
                preserveScroll: true,
                ...options,
            });
        } else {
            form.post('/inventory/stock-adjustments', {
                preserveScroll: true,
                ...options,
            });
        }
    });
}
</script>

<template>
    <div class="space-y-6">
        <Alert
            v-if="form.hasErrors && form.errors.form"
            variant="danger"
            class="mb-6"
        >
            {{ form.errors.form }}
        </Alert>

        <Alert
            v-if="form.hasErrors && form.errors.status"
            variant="danger"
            class="mb-6"
        >
            {{ form.errors.status }}
        </Alert>

        <form ref="formRef" class="space-y-6" novalidate @submit.prevent="submit">
            <!-- Header Information Card -->
            <Card>
                <template #header>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                        Informasi Header Dokumen
                    </h3>
                </template>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <FormField
                        id="adjustment-warehouse"
                        label="Lokasi Gudang"
                        :error="form.errors.warehouse_id"
                        required
                    >
                        <template #default="{ id, describedBy, hasError }">
                            <Select
                                :id="id"
                                v-model="form.warehouse_id"
                                :options="warehouseOptions"
                                :invalid="hasError"
                                :aria-describedby="describedBy"
                                required
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="adjustment-date"
                        label="Tanggal Penyesuaian"
                        :error="form.errors.adjustment_date"
                        required
                    >
                        <template #default="{ id, describedBy, hasError }">
                            <TextInput
                                :id="id"
                                v-model="form.adjustment_date"
                                type="date"
                                :invalid="hasError"
                                :aria-describedby="describedBy"
                                required
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="adjustment-reason"
                        label="Alasan Penyesuaian"
                        :error="form.errors.reason"
                        help="Contoh: Stok opname rutin bulanan, barang rusak, dsb (maks. 255 karakter)"
                        required
                        class="md:col-span-2"
                    >
                        <template #default="{ id, describedBy, hasError }">
                            <TextInput
                                :id="id"
                                v-model="form.reason"
                                placeholder="Masukkan alasan penyesuaian fisik..."
                                :invalid="hasError"
                                :aria-describedby="describedBy"
                                maxlength="255"
                                required
                            />
                        </template>
                    </FormField>

                    <FormField
                        id="adjustment-notes"
                        label="Catatan Tambahan"
                        :error="form.errors.notes"
                        help="Catatan operasional internal (opsional, maks. 1000 karakter)"
                        class="md:col-span-2"
                    >
                        <template #default="{ id, describedBy, hasError }">
                            <Textarea
                                :id="id"
                                v-model="form.notes"
                                placeholder="Tambahkan catatan khusus bila diperlukan..."
                                :invalid="hasError"
                                :aria-describedby="describedBy"
                                rows="3"
                                maxlength="1000"
                            />
                        </template>
                    </FormField>
                </div>
            </Card>

            <!-- Line Items Card -->
            <Card>
                <template #header>
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                Rincian Barang Penyesuaian
                            </h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                Catat kuantitas hasil hitung fisik (stock opname) untuk setiap produk.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            @click="addItem"
                        >
                            <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Tambah Baris
                        </Button>
                    </div>
                </template>

                <Alert
                    v-if="form.errors.items && typeof form.errors.items === 'string'"
                    variant="danger"
                    class="mb-4"
                >
                    {{ form.errors.items }}
                </Alert>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/75 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-300 uppercase tracking-wider">
                            <tr>
                                <th class="py-3 px-3 w-12 text-center">No</th>
                                <th class="py-3 px-3 min-w-[240px]">Produk <span class="text-rose-500">*</span></th>
                                <th class="py-3 px-3 w-32 text-right">Saldo Sistem</th>
                                <th class="py-3 px-3 w-36 text-right">Fisik (Opname) <span class="text-rose-500">*</span></th>
                                <th class="py-3 px-3 w-28 text-right">Selisih</th>
                                <th class="py-3 px-3 w-40 text-right">Biaya Satuan (HPP)</th>
                                <th class="py-3 px-3 w-12 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60">
                            <tr
                                v-for="(item, idx) in form.items"
                                :key="idx"
                                class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors"
                            >
                                <td class="py-2.5 px-3 text-center text-xs font-mono text-slate-500">
                                    {{ item.line_no }}
                                </td>

                                <td class="py-2.5 px-3">
                                    <Select
                                        v-model="item.product_id"
                                        :options="productOptions"
                                        :aria-label="`Pilih produk baris ${item.line_no}`"
                                        :invalid="Boolean(form.errors[`items.${idx}.product_id`])"
                                        required
                                    />
                                    <p
                                        v-if="form.errors[`items.${idx}.product_id`]"
                                        class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                                    >
                                        {{ form.errors[`items.${idx}.product_id`] }}
                                    </p>
                                </td>

                                <td class="py-2.5 px-3 text-right font-mono text-xs text-slate-600 dark:text-slate-400">
                                    <Quantity :value="item.system_quantity" />
                                </td>

                                <td class="py-2.5 px-3">
                                    <TextInput
                                        v-model="item.counted_quantity"
                                        type="text"
                                        inputmode="decimal"
                                        :aria-label="`Jumlah fisik baris ${item.line_no}`"
                                        class="text-right font-mono text-xs"
                                        :invalid="Boolean(form.errors[`items.${idx}.counted_quantity`])"
                                        required
                                    />
                                    <p
                                        v-if="form.errors[`items.${idx}.counted_quantity`]"
                                        class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                                    >
                                        {{ form.errors[`items.${idx}.counted_quantity`] }}
                                    </p>
                                </td>

                                <td class="py-2.5 px-3 text-right font-mono text-xs font-medium">
                                    <span
                                        :class="[
                                            calculateDifference(item).startsWith('-')
                                                ? 'text-rose-600 dark:text-rose-400'
                                                : calculateDifference(item) === '0.0000'
                                                    ? 'text-slate-500'
                                                    : 'text-emerald-600 dark:text-emerald-400'
                                        ]"
                                    >
                                        <span v-if="!calculateDifference(item).startsWith('-') && calculateDifference(item) !== '0.0000'">+</span>
                                        {{ calculateDifference(item) }}
                                    </span>
                                </td>

                                <td class="py-2.5 px-3">
                                    <TextInput
                                        v-model="item.unit_cost"
                                        type="text"
                                        inputmode="decimal"
                                        :aria-label="`Biaya satuan baris ${item.line_no}`"
                                        class="text-right font-mono text-xs"
                                        :invalid="Boolean(form.errors[`items.${idx}.unit_cost`])"
                                        :placeholder="!calculateDifference(item).startsWith('-') && calculateDifference(item) !== '0.0000' ? 'Wajib (> 0)' : '0.0000'"
                                    />
                                    <p
                                        v-if="form.errors[`items.${idx}.unit_cost`]"
                                        class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                                    >
                                        {{ form.errors[`items.${idx}.unit_cost`] }}
                                    </p>
                                </td>

                                <td class="py-2.5 px-3 text-center">
                                    <IconButton
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        aria-label="Hapus Baris"
                                        :disabled="form.items.length <= 1"
                                        @click="removeItem(idx)"
                                    >
                                        <Trash2 class="w-4 h-4 text-rose-600 dark:text-rose-400" aria-hidden="true" />
                                    </IconButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <!-- Actions Bar -->
            <div class="flex items-center justify-between pt-2">
                <Link href="/inventory/stock-adjustments">
                    <Button type="button" variant="secondary">
                        <ArrowLeft class="w-4 h-4 mr-1.5" aria-hidden="true" />
                        Kembali
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
                    {{ (busy || form.processing) ? 'Menyimpan...' : (isEdit ? 'Perbarui Draf' : 'Simpan Draf') }}
                </Button>
            </div>
        </form>
    </div>
</template>
