<script setup>
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { useFocusFirstField } from '@/composables/useFocusFirstField';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import Select from '@/Components/ui/Select.vue';
import Switch from '@/Components/ui/Switch.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Alert from '@/Components/ui/Alert.vue';

const props = defineProps({
    customer: {
        type: Object,
        default: null,
    },
    paymentTerms: {
        type: Array,
        default: () => [],
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    code: props.customer?.code || '',
    name: props.customer?.name || '',
    contact_name: props.customer?.contact_name || '',
    phone: props.customer?.phone || '',
    email: props.customer?.email || '',
    address: props.customer?.address || '',
    tax_id: props.customer?.tax_id || '',
    credit_limit: props.customer?.credit_limit !== undefined ? String(props.customer.credit_limit) : '0.00',
    payment_term_id: props.customer?.payment_term_id ? String(props.customer.payment_term_id) : '',
    is_active: props.customer ? Boolean(props.customer.is_active) : true,
});

const { formRef } = useFocusFirstField(() => form.errors);

const termOptions = computed(() => [
    { value: '', label: '-- Pilih Syarat Pembayaran (Opsional) --' },
    ...props.paymentTerms.map((t) => ({
        value: String(t.id),
        label: `${t.code} - ${t.name} (${t.days} hari)`,
    })),
]);

function submit() {
    if (props.isEdit) {
        form.put(`/master/customers/${props.customer.id}`, {
            preserveScroll: true,
        });
    } else {
        form.post('/master/customers', {
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

        <form ref="formRef" class="space-y-5" novalidate @submit.prevent="submit">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <!-- Kode Pelanggan -->
                <FormField
                    id="customer-code"
                    label="Kode Pelanggan"
                    :error="form.errors.code"
                    help="Maksimal 30 karakter unik, contoh: CUST-001"
                    required
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.code"
                            placeholder="Contoh: CUST-001"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                            class="uppercase"
                            required
                        />
                    </template>
                </FormField>

                <!-- Nama Pelanggan -->
                <FormField
                    id="customer-name"
                    label="Nama Pelanggan / Perusahaan"
                    :error="form.errors.name"
                    help="Nama lengkap pelanggan retail atau institusi"
                    required
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.name"
                            placeholder="Contoh: CV Maju Bersama"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                            required
                        />
                    </template>
                </FormField>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <!-- Kontak Person -->
                <FormField
                    id="customer-contact"
                    label="Nama Kontak (PIC)"
                    :error="form.errors.contact_name"
                    help="Nama narahubung pembeli atau perwakilan toko"
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.contact_name"
                            placeholder="Contoh: Ibu Rina Melati"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>

                <!-- Nomor Telepon -->
                <FormField
                    id="customer-phone"
                    label="Nomor Telepon"
                    :error="form.errors.phone"
                    help="Nomor kantor atau nomor WhatsApp aktif"
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.phone"
                            placeholder="Contoh: 0812-3456-7890"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <!-- Email -->
                <FormField
                    id="customer-email"
                    label="Alamat Email"
                    :error="form.errors.email"
                    help="Email pengiriman faktur dan konfirmasi pesanan"
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.email"
                            type="email"
                            placeholder="Contoh: billing@majubersama.com"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>

                <!-- NPWP / Tax ID -->
                <FormField
                    id="customer-tax"
                    label="NPWP / Nomor Pokok Wajib Pajak"
                    :error="form.errors.tax_id"
                    help="Format NPWP 15 atau 16 digit"
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.tax_id"
                            placeholder="Contoh: 02.345.678.9-013.000"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <!-- Limit Kredit -->
                <FormField
                    id="customer-limit"
                    label="Batas Kredit (Credit Limit - IDR)"
                    :error="form.errors.credit_limit"
                    help="Plafon maksimal piutang yang diperbolehkan (Rp)"
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.credit_limit"
                            type="text"
                            placeholder="0.00"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>

                <!-- Syarat Pembayaran -->
                <FormField
                    id="customer-term"
                    label="Syarat Pembayaran Default"
                    :error="form.errors.payment_term_id"
                    help="Termin pembayaran otomatis yang digunakan pada pesanan penjualan"
                >
                    <template #default="{ id, describedBy, hasError }">
                        <Select
                            :id="id"
                            v-model="form.payment_term_id"
                            :options="termOptions"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>
            </div>

            <!-- Alamat Kirim / Kantor -->
            <FormField
                id="customer-address"
                label="Alamat Pengiriman / Penagihan"
                :error="form.errors.address"
                help="Alamat fisik tujuan pengiriman barang atau faktur"
            >
                <template #default="{ id, describedBy, hasError }">
                    <Textarea
                        :id="id"
                        v-model="form.address"
                        placeholder="Masukkan alamat lengkap..."
                        rows="3"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                    />
                </template>
            </FormField>

            <!-- Status Aktif -->
            <div class="pt-2">
                <Switch
                    v-model="form.is_active"
                    label="Status Pelanggan Aktif"
                    description="Pelanggan aktif dapat bertransaksi pada modul penjualan dan pengiriman pesanan"
                />
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                <Link
                    href="/master/customers"
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
                    {{ isEdit ? 'Perbarui Pelanggan' : 'Simpan Pelanggan' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
