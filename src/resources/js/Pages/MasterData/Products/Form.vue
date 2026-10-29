<script setup>
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import Select from '@/Components/ui/Select.vue';
import Switch from '@/Components/ui/Switch.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Alert from '@/Components/ui/Alert.vue';

const props = defineProps({
    product: {
        type: Object,
        default: null,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    units: {
        type: Array,
        default: () => [],
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    sku: props.product?.sku || '',
    name: props.product?.name || '',
    category_id: props.product?.category_id ? String(props.product.category_id) : '',
    base_unit_id: props.product?.base_unit_id ? String(props.product.base_unit_id) : '',
    barcode: props.product?.barcode || '',
    description: props.product?.description || '',
    track_stock: props.product ? Boolean(props.product.track_stock) : true,
    selling_price: props.product?.selling_price !== undefined ? String(props.product.selling_price) : '0.00',
    min_stock: props.product?.min_stock !== undefined ? String(props.product.min_stock) : '0.0000',
    is_active: props.product ? Boolean(props.product.is_active) : true,
});

const categoryOptions = computed(() => [
    { value: '', label: '-- Pilih Kategori Produk --' },
    ...props.categories.map((c) => ({
        value: String(c.id),
        label: `${c.code} - ${c.name}`,
    })),
]);

const unitOptions = computed(() => [
    { value: '', label: '-- Pilih Satuan Dasar --' },
    ...props.units.map((u) => ({
        value: String(u.id),
        label: `${u.code} - ${u.name}`,
    })),
]);

function submit() {
    if (props.isEdit) {
        form.put(`/master/products/${props.product.id}`, {
            preserveScroll: true,
        });
    } else {
        form.post('/master/products', {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Card padding="lg" class="max-w-3xl">
        <Alert
            v-if="form.hasErrors && form.errors.form"
            variant="danger"
            class="mb-6"
        >
            {{ form.errors.form }}
        </Alert>

        <form class="space-y-5" novalidate @submit.prevent="submit">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <!-- SKU / Kode Produk -->
                <FormField
                    id="product-sku"
                    label="SKU / Kode Produk"
                    :error="form.errors.sku"
                    help="Maksimal 50 karakter unik, contoh: PRD-001, BEV-001"
                    required
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.sku"
                            placeholder="Contoh: PRD-001"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                            class="uppercase"
                            required
                            autofocus
                        />
                    </template>
                </FormField>

                <!-- Barcode -->
                <FormField
                    id="product-barcode"
                    label="Kode Barcode (EAN/UPC)"
                    :error="form.errors.barcode"
                    help="Nomor barcode pemindai fisik (opsional)"
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.barcode"
                            placeholder="Contoh: 8991234567890"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>
            </div>

            <!-- Nama Produk -->
            <FormField
                id="product-name"
                label="Nama Produk Dagang"
                :error="form.errors.name"
                help="Nama resmi produk komersial barang dagang"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.name"
                        placeholder="Contoh: Kopi Bubuk Arabika Premium 250g"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        required
                    />
                </template>
            </FormField>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <!-- Kategori Produk -->
                <FormField
                    id="product-category"
                    label="Kategori Produk"
                    :error="form.errors.category_id"
                    help="Pilih kelompok kategori produk terkait"
                    required
                >
                    <template #default="{ id, describedBy, hasError }">
                        <Select
                            :id="id"
                            v-model="form.category_id"
                            :options="categoryOptions"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                            required
                        />
                    </template>
                </FormField>

                <!-- Satuan Dasar -->
                <FormField
                    id="product-unit"
                    label="Satuan Dasar (Base Unit)"
                    :error="form.errors.base_unit_id"
                    help="Satuan kuantitas terkecil produk di persediaan"
                    required
                >
                    <template #default="{ id, describedBy, hasError }">
                        <Select
                            :id="id"
                            v-model="form.base_unit_id"
                            :options="unitOptions"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                            required
                        />
                    </template>
                </FormField>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <!-- Harga Jual Standar -->
                <FormField
                    id="product-price"
                    label="Harga Jual Standar (IDR)"
                    :error="form.errors.selling_price"
                    help="Harga jual sebelum pajak atau diskon (Rp)"
                    required
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.selling_price"
                            type="text"
                            placeholder="0.00"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                            required
                        />
                    </template>
                </FormField>

                <!-- Batas Minimum Stok -->
                <FormField
                    id="product-min-stock"
                    label="Minimum Stok Peringatan"
                    :error="form.errors.min_stock"
                    help="Ambang batas minimal stok sebelum pengadaan kembali"
                    required
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.min_stock"
                            type="text"
                            placeholder="0.0000"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                            required
                        />
                    </template>
                </FormField>
            </div>

            <!-- Deskripsi Produk -->
            <FormField
                id="product-description"
                label="Deskripsi / Catatan Produk"
                :error="form.errors.description"
                help="Spesifikasi teknis, kemasan, atau catatan operasional"
            >
                <template #default="{ id, describedBy, hasError }">
                    <Textarea
                        :id="id"
                        v-model="form.description"
                        placeholder="Masukkan catatan spesifikasi barang..."
                        rows="3"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                    />
                </template>
            </FormField>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 pt-2">
                <!-- Lacak Stok -->
                <Switch
                    v-model="form.track_stock"
                    label="Lacak Persediaan (Track Stock)"
                    description="Pantau saldo stok dan mutasi pergudangan barang ini"
                />

                <!-- Status Aktif -->
                <Switch
                    v-model="form.is_active"
                    label="Status Produk Aktif"
                    description="Produk aktif dapat diperjualbelikan pada modul transaksi"
                />
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                <Link
                    href="/master/products"
                    class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 transition-colors"
                >
                    Batal
                </Link>

                <Button
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing"
                >
                    {{ isEdit ? 'Perbarui Produk' : 'Simpan Produk' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
