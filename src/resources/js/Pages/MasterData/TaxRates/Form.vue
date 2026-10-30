<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import { useFocusFirstField } from '@/composables/useFocusFirstField';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Switch from '@/Components/ui/Switch.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Alert from '@/Components/ui/Alert.vue';

const props = defineProps({
    taxRate: {
        type: Object,
        default: null,
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    code: props.taxRate?.code || '',
    name: props.taxRate?.name || '',
    rate: props.taxRate?.rate !== undefined ? String(props.taxRate.rate) : '0.00',
    is_active: props.taxRate ? Boolean(props.taxRate.is_active) : true,
});

const { formRef } = useFocusFirstField(() => form.errors);

function submit() {
    if (props.isEdit) {
        form.put(`/master/tax-rates/${props.taxRate.id}`, {
            preserveScroll: true,
        });
    } else {
        form.post('/master/tax-rates', {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Card padding="lg" class="max-w-2xl">
        <Alert
            v-if="form.hasErrors && form.errors.form"
            variant="danger"
            class="mb-6"
        >
            {{ form.errors.form }}
        </Alert>

        <form ref="formRef" class="space-y-5" novalidate @submit.prevent="submit">
            <!-- Kode Tarif Pajak -->
            <FormField
                id="tax-code"
                label="Kode Tarif Pajak"
                :error="form.errors.code"
                help="Maksimal 20 karakter unik, contoh: PPN11, PPH23, NON-PAJAK"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.code"
                        placeholder="Contoh: PPN11"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        class="uppercase"
                        required
                    />
                </template>
            </FormField>

            <!-- Nama Tarif Pajak -->
            <FormField
                id="tax-name"
                label="Nama Tarif Pajak"
                :error="form.errors.name"
                help="Deskripsi nama tarif pajak, contoh: PPN Masukan / Keluaran 11%"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.name"
                        placeholder="Contoh: Pajak Pertambahan Nilai 11%"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        required
                    />
                </template>
            </FormField>

            <!-- Persentase Tarif Pajak -->
            <FormField
                id="tax-rate"
                label="Tarif Pajak (%)"
                :error="form.errors.rate"
                help="Nilai persentase dalam rentang 0.00 hingga 100.00"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.rate"
                        type="text"
                        placeholder="11.00"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        required
                    />
                </template>
            </FormField>

            <!-- Status Aktif -->
            <div class="pt-2">
                <Switch
                    v-model="form.is_active"
                    label="Tarif Pajak Aktif"
                    description="Tarif pajak aktif dapat dipilih saat pembuatan faktur penjualan dan pembelian"
                />
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                <Link
                    href="/master/tax-rates"
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
                    {{ isEdit ? 'Perbarui Tarif' : 'Simpan Tarif' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
