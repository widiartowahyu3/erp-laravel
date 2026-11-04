<script setup>
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { useFocusFirstField } from '@/composables/useFocusFirstField';
import { useSubmitLock } from '@/composables/useSubmitLock';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Alert from '@/Components/ui/Alert.vue';
import PermissionMatrix from '@/Components/admin/PermissionMatrix.vue';

const props = defineProps({
    role: {
        type: Object,
        default: null,
    },
    selectedPermissions: {
        type: Array,
        default: () => [],
    },
    permissionMatrix: {
        type: Array,
        required: true,
    },
    grantablePermissions: {
        type: Array,
        default: () => [],
    },
});

const isEdit = computed(() => props.role !== null);

const form = useForm({
    display_name: props.role?.display_name || '',
    description: props.role?.description || '',
    permissions: [...props.selectedPermissions],
});

const { formRef } = useFocusFirstField(() => form.errors);
const { busy, submit: submitWithLock } = useSubmitLock();

function submit() {
    submitWithLock((options) => {
        if (isEdit.value) {
            form.put(`/admin/roles/${props.role.id}`, { ...options, preserveScroll: true });
        } else {
            form.post('/admin/roles', { ...options, preserveScroll: true });
        }
    });
}
</script>

<template>
    <form ref="formRef" class="space-y-6" novalidate @submit.prevent="submit">
        <Alert v-if="form.errors.form" variant="danger">
            {{ form.errors.form }}
        </Alert>

        <Card padding="lg" class="max-w-2xl">
            <div class="space-y-5">
                <FormField id="role-display-name" label="Nama Peran" :error="form.errors.display_name" required>
                    <template #default="{ id, invalid, describedBy }">
                        <TextInput
                            :id="id"
                            v-model="form.display_name"
                            name="display_name"
                            placeholder="Contoh: Staf Master Data (Lihat Saja)"
                            :invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>

                <FormField id="role-description" label="Deskripsi" :error="form.errors.description">
                    <template #default="{ id, invalid, describedBy }">
                        <Textarea
                            :id="id"
                            v-model="form.description"
                            name="description"
                            :rows="2"
                            :invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>
            </div>
        </Card>

        <div class="space-y-3">
            <p class="text-xs text-slate-600 dark:text-slate-300">
                Mencentang aksi selain Lihat otomatis mencentang Lihat pada sumber yang sama. Kotak yang nonaktif berada di luar izin Anda.
            </p>
            <Alert v-if="form.errors.permissions" variant="danger">
                {{ form.errors.permissions }}
            </Alert>
            <PermissionMatrix
                v-model="form.permissions"
                :modules="permissionMatrix"
                :grantable="grantablePermissions"
            />
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link
                :href="isEdit ? `/admin/roles/${role.id}` : '/admin/roles'"
                class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium rounded-lg text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800"
            >
                Batal
            </Link>

            <Button
                type="submit"
                variant="primary"
                :loading="form.processing || busy"
                :disabled="form.processing || busy"
                :aria-busy="form.processing || busy"
            >
                {{ isEdit ? 'Perbarui Peran' : 'Simpan Peran' }}
            </Button>
        </div>
    </form>
</template>
