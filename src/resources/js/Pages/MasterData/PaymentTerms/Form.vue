<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Alert from '@/Components/ui/Alert.vue';

const props = defineProps({
    paymentTerm: {
        type: Object,
        default: null,
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    code: props.paymentTerm?.code || '',
    name: props.paymentTerm?.name || '',
    days: props.paymentTerm?.days !== undefined ? Number(props.paymentTerm.days) : 0,
});

function submit() {
    if (props.isEdit) {
        form.put(`/master/payment-terms/${props.paymentTerm.id}`, {
            preserveScroll: true,
        });
    } else {
        form.post('/master/payment-terms', {
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
            <!-- Kode Syarat Pembayaran -->
            <FormField
                id="term-code"
                label="Kode Termin"
                :error="form.errors.code"
                help="Maksimal 20 karakter unik, contoh: COD, NET30, NET60"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.code"
                        placeholder="Contoh: NET30"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        class="uppercase"
                        required
                        autofocus
                    />
                </template>
            </FormField>

            <!-- Nama Syarat Pembayaran -->
            <FormField
                id="term-name"
                label="Nama Termin"
                :error="form.errors.name"
                help="Deskripsi termin pembayaran, contoh: Tempo 30 Hari, Bayar di Tempat (COD)"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.name"
                        placeholder="Contoh: Tempo 30 Hari"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        required
                    />
                </template>
            </FormField>

            <!-- Jumlah Hari (Tempo) -->
            <FormField
                id="term-days"
                label="Jumlah Hari Tempo (Hari)"
                :error="form.errors.days"
                help="Masukkan 0 untuk COD/Tunai seketika, atau jumlah hari jatuh tempo (1 - 3650)"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model.number="form.days"
                        type="number"
                        min="0"
                        max="3650"
                        placeholder="30"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        required
                    />
                </template>
            </FormField>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                <Link
                    href="/master/payment-terms"
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
                    {{ isEdit ? 'Perbarui Termin' : 'Simpan Termin' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
