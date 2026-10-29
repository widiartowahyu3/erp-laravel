<script setup>
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Select from '@/Components/ui/Select.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Alert from '@/Components/ui/Alert.vue';

const props = defineProps({
    category: {
        type: Object,
        default: null,
    },
    parentCategories: {
        type: Array,
        default: () => [],
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    code: props.category?.code || '',
    name: props.category?.name || '',
    parent_id: props.category?.parent_id ? String(props.category.parent_id) : '',
});

const parentOptions = computed(() => [
    { value: '', label: '-- Tanpa Induk (Kategori Utama) --' },
    ...props.parentCategories.map((c) => ({
        value: String(c.id),
        label: `${c.code} - ${c.name}`,
    })),
]);

function submit() {
    if (props.isEdit) {
        form.put(`/master/product-categories/${props.category.id}`, {
            preserveScroll: true,
        });
    } else {
        form.post('/master/product-categories', {
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
            <!-- Kode Kategori -->
            <FormField
                id="category-code"
                label="Kode Kategori"
                :error="form.errors.code"
                help="Maksimal 30 karakter unik, contoh: ELEK, FASH, MAKN"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.code"
                        placeholder="Contoh: ELEK"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        class="uppercase"
                        required
                        autofocus
                    />
                </template>
            </FormField>

            <!-- Nama Kategori -->
            <FormField
                id="category-name"
                label="Nama Kategori"
                :error="form.errors.name"
                help="Nama lengkap kategori produk, contoh: Elektronik & Gadget"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.name"
                        placeholder="Contoh: Elektronik & Gadget"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        required
                    />
                </template>
            </FormField>

            <!-- Kategori Induk -->
            <FormField
                id="category-parent"
                label="Kategori Induk (Opsional)"
                :error="form.errors.parent_id"
                help="Pilih kategori induk jika kategori ini merupakan sub-kategori (maksimal 5 kedalaman)"
            >
                <template #default="{ id, describedBy, hasError }">
                    <Select
                        :id="id"
                        v-model="form.parent_id"
                        :options="parentOptions"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                    />
                </template>
            </FormField>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                <Link
                    href="/master/product-categories"
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
                    {{ isEdit ? 'Perbarui Kategori' : 'Simpan Kategori' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
