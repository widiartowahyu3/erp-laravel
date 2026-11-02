<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Save, ArrowLeft, Trash2 } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Card from '@/Components/ui/Card.vue';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import Button from '@/Components/ui/Button.vue';
import IconButton from '@/Components/ui/IconButton.vue';
import Alert from '@/Components/ui/Alert.vue';
import Quantity from '@/Components/ui/Quantity.vue';
import { useSubmitLock } from '@/composables/useSubmitLock';
import { useFocusFirstField } from '@/composables/useFocusFirstField';
import { trimDecimal } from '@/Support/decimal';

const props = defineProps({
    item: {
        type: Object,
        default: null,
    },
    deliveryOrder: {
        type: Object,
        default: null,
    },
});

const currentDo = computed(() => props.item || props.deliveryOrder || {});

const preparedItems = (currentDo.value.items || []).map((it) => ({
    sales_order_item_id: String(it.sales_order_item_id),
    product_id: String(it.product_id),
    quantity: trimDecimal(it.quantity || '1'),
    product: it.product,
    so_item: it.sales_order_item,
}));

const form = useForm({
    delivery_date: currentDo.value.delivery_date
        ? String(currentDo.value.delivery_date).split('T')[0]
        : '',
    shipping_address: currentDo.value.shipping_address || '',
    notes: currentDo.value.notes || '',
    items: preparedItems,
});

const { formRef } = useFocusFirstField(() => form.errors);
const { busy, submit: submitWithLock } = useSubmitLock();

const breadcrumbs = computed(() => [
    { label: 'Surat Jalan (DO)', href: '/sales/delivery-orders' },
    { label: currentDo.value.number || 'Detail', href: `/sales/delivery-orders/${currentDo.value.id}` },
    { label: 'Edit Draf' },
]);

function removeItem(index) {
    if (form.items.length <= 1) return;
    form.items.splice(index, 1);
}

function submit() {
    submitWithLock((options) => {
        form.put(`/sales/delivery-orders/${currentDo.value.id}`, {
            ...options,
        });
    });
}
</script>

<template>
    <AppLayout :title="`Edit DO: ${currentDo.number}`">
        <Head :title="`Edit DO: ${currentDo.number}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader
                :title="`Edit DO: ${currentDo.number}`"
                description="Perbarui informasi pengiriman fisik atau kuantitas barang sebelum surat jalan diposting."
            />

            <form ref="formRef" class="space-y-6" @submit.prevent="submit">
                <Alert v-if="form.errors.status" variant="danger">
                    {{ form.errors.status }}
                </Alert>
                <Alert v-if="form.errors.form" variant="danger">
                    {{ form.errors.form }}
                </Alert>

                <!-- Informasi Header DO -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <Card class="md:col-span-2">
                        <template #header>
                            <div class="space-y-0.5">
                                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    Informasi Pengiriman
                                </h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Tanggal pengiriman fisik dan alamat tujuan pengiriman.
                                </p>
                            </div>
                        </template>

                        <div class="space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <FormField
                                    label="Tanggal Pengiriman"
                                    required
                                    :error="form.errors.delivery_date"
                                >
                                    <TextInput
                                        v-model="form.delivery_date"
                                        type="date"
                                        :disabled="form.processing"
                                        aria-label="Tanggal pengiriman"
                                    />
                                </FormField>
                            </div>

                            <FormField
                                label="Alamat Pengiriman"
                                :error="form.errors.shipping_address"
                            >
                                <Textarea
                                    v-model="form.shipping_address"
                                    rows="2"
                                    placeholder="Alamat lengkap penerima pengiriman barang..."
                                    :disabled="form.processing"
                                    aria-label="Alamat pengiriman barang"
                                />
                            </FormField>

                            <FormField
                                label="Catatan Pengiriman"
                                :error="form.errors.notes"
                            >
                                <Textarea
                                    v-model="form.notes"
                                    rows="2"
                                    placeholder="Catatan untuk ekspedisi, driver, atau penerima..."
                                    :disabled="form.processing"
                                    aria-label="Catatan pengiriman"
                                />
                            </FormField>
                        </div>
                    </Card>

                    <!-- Info Referensi SO -->
                    <Card>
                        <template #header>
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                Referensi Pesanan (SO)
                            </h3>
                        </template>
                        <div class="space-y-3 text-sm">
                            <div>
                                <span class="text-xs text-slate-500 block">No. SO:</span>
                                <Link
                                    v-if="currentDo.sales_order"
                                    :href="`/sales/sales-orders/${currentDo.sales_order.id}`"
                                    class="font-mono font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                                >
                                    {{ currentDo.sales_order.number }}
                                </Link>
                                <span v-else class="text-slate-400">-</span>
                            </div>

                            <div>
                                <span class="text-xs text-slate-500 block">Pelanggan:</span>
                                <span class="font-medium text-slate-800 dark:text-slate-200">
                                    {{ currentDo.customer?.code }} - {{ currentDo.customer?.name }}
                                </span>
                            </div>

                            <div>
                                <span class="text-xs text-slate-500 block">Gudang Asal:</span>
                                <span class="font-medium text-slate-800 dark:text-slate-200">
                                    {{ currentDo.warehouse?.code }} - {{ currentDo.warehouse?.name }}
                                </span>
                            </div>
                        </div>
                    </Card>
                </div>

                <!-- Tabel Barang yang Dikirim -->
                <Card>
                    <template #header>
                        <div class="space-y-0.5">
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                Barang yang Akan Dikirim
                            </h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Perbarui kuantitas barang yang dikirim pada pengiriman ini.
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
                                    <th class="py-2.5 px-3 w-10 text-center">No</th>
                                    <th class="py-2.5 px-3">Produk</th>
                                    <th class="py-2.5 px-3 text-right">Dipesan (SO)</th>
                                    <th class="py-2.5 px-3 w-40 text-right">Qty Dikirim Ini</th>
                                    <th class="py-2.5 px-3 w-12 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr
                                    v-for="(lineItem, index) in form.items"
                                    :key="lineItem.sales_order_item_id"
                                    class="align-top"
                                >
                                    <td class="py-3 px-3 text-center text-xs text-slate-500 pt-4">
                                        {{ index + 1 }}
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <div class="font-medium text-slate-800 dark:text-slate-200">
                                            {{ lineItem.product?.name }}
                                        </div>
                                        <div class="text-xs text-slate-500 font-mono">
                                            {{ lineItem.product?.sku }}
                                            <span v-if="lineItem.product?.base_unit">({{ lineItem.product.base_unit.code }})</span>
                                        </div>
                                        <div v-if="form.errors[`items.${index}.product_id`]" class="text-xs text-rose-500 mt-1">
                                            {{ form.errors[`items.${index}.product_id`] }}
                                        </div>
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono text-xs text-slate-600 dark:text-slate-400 pt-4">
                                        <Quantity :value="lineItem.so_item?.quantity" />
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <TextInput
                                            v-model="lineItem.quantity"
                                            type="text"
                                            size="sm"
                                            class="text-right font-mono font-semibold"
                                            placeholder="1"
                                            :disabled="form.processing"
                                            :aria-label="`Kuantitas kirim baris ke-${index + 1}`"
                                        />
                                        <div v-if="form.errors[`items.${index}.quantity`]" class="text-xs text-rose-500 mt-1">
                                            {{ form.errors[`items.${index}.quantity`] }}
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-3 text-center pt-3">
                                        <IconButton
                                            variant="danger"
                                            size="sm"
                                            :disabled="form.items.length <= 1 || form.processing"
                                            title="Hapus Baris Pengiriman"
                                            :aria-label="`Hapus baris pengiriman ke-${index + 1}`"
                                            @click="removeItem(index)"
                                        >
                                            <Trash2 class="w-4 h-4" />
                                        </IconButton>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <!-- Actions -->
                <div class="flex items-center justify-end gap-3 pt-4">
                    <Link :href="`/sales/delivery-orders/${currentDo.id}`">
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
                        Simpan Perubahan
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
