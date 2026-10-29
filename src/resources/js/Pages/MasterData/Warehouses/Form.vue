<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import Switch from '@/Components/ui/Switch.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Alert from '@/Components/ui/Alert.vue';

const props = defineProps({
    warehouse: {
        type: Object,
        default: null,
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    code: props.warehouse?.code || '',
    name: props.warehouse?.name || '',
    address: props.warehouse?.address || '',
    is_active: props.warehouse ? Boolean(props.warehouse.is_active) : true,
});

function submit() {
    if (props.isEdit) {
        form.put(`/master/warehouses/${props.warehouse.id}`, {
            preserveScroll: true,
        });
    } else {
        form.post('/master/warehouses', {
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

        <form class="space-y-5" novalidate @submit.prevent="submit">
            <!-- Kode Gudang -->
            <FormField
                id="warehouse-code"
                label="Kode Gudang"
                :error="form.errors.code"
                help="Maksimal 20 karakter unik, contoh: GDU-UTM, GDU-TMR"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.code"
                        placeholder="Contoh: GDU-UTAMA"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        class="uppercase"
                        required
                        autofocus
                    />
                </template>
            </FormField>

            <!-- Nama Gudang -->
            <FormField
                id="warehouse-name"
                label="Nama Gudang"
                :error="form.errors.name"
                help="Nama fasilitas gudang atau lokasi penyimpanan"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.name"
                        placeholder="Contoh: Gudang Distribusi Utama"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        required
                    />
                </template>
            </FormField>

            <!-- Alamat Gudang -->
            <FormField
                id="warehouse-address"
                label="Alamat Gudang"
                :error="form.errors.address"
                help="Alamat fisik lengkap fasilitas gudang"
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
                    label="Status Gudang Aktif"
                    description="Gudang aktif dapat digunakan dalam mutasi persediaan dan dokumen transaksi"
                />
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                <Link
                    href="/master/warehouses"
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
                    {{ isEdit ? 'Perbarui Gudang' : 'Simpan Gudang' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
