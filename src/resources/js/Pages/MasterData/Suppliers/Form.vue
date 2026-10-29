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
    supplier: {
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
    code: props.supplier?.code || '',
    name: props.supplier?.name || '',
    contact_name: props.supplier?.contact_name || '',
    phone: props.supplier?.phone || '',
    email: props.supplier?.email || '',
    address: props.supplier?.address || '',
    tax_id: props.supplier?.tax_id || '',
    payment_term_id: props.supplier?.payment_term_id ? String(props.supplier.payment_term_id) : '',
    is_active: props.supplier ? Boolean(props.supplier.is_active) : true,
});

const termOptions = computed(() => [
    { value: '', label: '-- Pilih Syarat Pembayaran (Opsional) --' },
    ...props.paymentTerms.map((t) => ({
        value: String(t.id),
        label: `${t.code} - ${t.name} (${t.days} hari)`,
    })),
]);

function submit() {
    if (props.isEdit) {
        form.put(`/master/suppliers/${props.supplier.id}`, {
            preserveScroll: true,
        });
    } else {
        form.post('/master/suppliers', {
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
                <!-- Kode Pemasok -->
                <FormField
                    id="supplier-code"
                    label="Kode Pemasok"
                    :error="form.errors.code"
                    help="Maksimal 30 karakter unik, contoh: SUP-001"
                    required
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.code"
                            placeholder="Contoh: SUP-001"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                            class="uppercase"
                            required
                            autofocus
                        />
                    </template>
                </FormField>

                <!-- Nama Pemasok -->
                <FormField
                    id="supplier-name"
                    label="Nama Badan Usaha / Pemasok"
                    :error="form.errors.name"
                    help="Nama resmi perusahaan mitra pemasok"
                    required
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.name"
                            placeholder="Contoh: PT Sumber Pangan Makmur"
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
                    id="supplier-contact"
                    label="Nama Kontak (PIC)"
                    :error="form.errors.contact_name"
                    help="Nama narahubung atau perwakilan sales"
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.contact_name"
                            placeholder="Contoh: Bpk. Hendra Gunawan"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>

                <!-- Nomor Telepon -->
                <FormField
                    id="supplier-phone"
                    label="Nomor Telepon"
                    :error="form.errors.phone"
                    help="Nomor kantor atau nomor seluler aktif"
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.phone"
                            placeholder="Contoh: 021-5551234"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <!-- Email -->
                <FormField
                    id="supplier-email"
                    label="Alamat Email"
                    :error="form.errors.email"
                    help="Email penagihan atau komunikasi resmi"
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.email"
                            type="email"
                            placeholder="Contoh: sales@sumberpangan.co.id"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>

                <!-- NPWP / Tax ID -->
                <FormField
                    id="supplier-tax"
                    label="NPWP / Nomor Pokok Wajib Pajak"
                    :error="form.errors.tax_id"
                    help="Format NPWP 15 atau 16 digit"
                >
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.tax_id"
                            placeholder="Contoh: 01.234.567.8-012.000"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>
            </div>

            <!-- Syarat Pembayaran Default -->
            <FormField
                id="supplier-term"
                label="Syarat Pembayaran Default"
                :error="form.errors.payment_term_id"
                help="Termin pembayaran otomatis yang digunakan pada pesanan pembelian"
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

            <!-- Alamat Kantor / Gudang Pemasok -->
            <FormField
                id="supplier-address"
                label="Alamat Lengkap"
                :error="form.errors.address"
                help="Alamat fisik surat atau kantor mitra pemasok"
            >
                <template #default="{ id, describedBy, hasError }">
                    <Textarea
                        :id="id"
                        v-model="form.address"
                        placeholder="Masukkan alamat lengkap pemasok..."
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
                    label="Status Pemasok Aktif"
                    description="Pemasok aktif dapat dipilih saat pembuatan pesanan pembelian baru"
                />
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                <Link
                    href="/master/suppliers"
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
                    {{ isEdit ? 'Perbarui Pemasok' : 'Simpan Pemasok' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
