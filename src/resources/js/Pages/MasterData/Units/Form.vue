<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import { useFocusFirstField } from '@/composables/useFocusFirstField';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Alert from '@/Components/ui/Alert.vue';

const props = defineProps({
    unit: {
        type: Object,
        default: null,
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    code: props.unit?.code || '',
    name: props.unit?.name || '',
});

const { formRef } = useFocusFirstField(() => form.errors);

function submit() {
    if (props.isEdit) {
        form.put(`/master/units/${props.unit.id}`, {
            preserveScroll: true,
        });
    } else {
        form.post('/master/units', {
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
            <!-- Kode Satuan -->
            <FormField
                id="unit-code"
                label="Kode Satuan"
                :error="form.errors.code"
                help="Maksimal 10 karakter unik, contoh: PCS, BOX, KG"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.code"
                        placeholder="Contoh: PCS"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        class="uppercase"
                        required
                    />
                </template>
            </FormField>

            <!-- Nama Satuan -->
            <FormField
                id="unit-name"
                label="Nama Satuan"
                :error="form.errors.name"
                help="Nama lengkap satuan ukuran, contoh: Pieces, Karton, Kilogram"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.name"
                        placeholder="Contoh: Pieces"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        required
                    />
                </template>
            </FormField>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                <Link
                    href="/master/units"
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
                    {{ isEdit ? 'Perbarui Satuan' : 'Simpan Satuan' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
