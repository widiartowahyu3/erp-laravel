<script setup>
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { useFocusFirstField } from '@/composables/useFocusFirstField';
import { useSubmitLock } from '@/composables/useSubmitLock';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import PasswordInput from '@/Components/ui/PasswordInput.vue';
import Checkbox from '@/Components/ui/Checkbox.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Alert from '@/Components/ui/Alert.vue';

const props = defineProps({
    user: {
        type: Object,
        default: null,
    },
    assignableRoles: {
        type: Array,
        default: () => [],
    },
    isSelf: {
        type: Boolean,
        default: false,
    },
    isLastSuperAdmin: {
        type: Boolean,
        default: false,
    },
});

const isEdit = computed(() => props.user !== null);

const form = useForm({
    name: props.user?.name || '',
    email: props.user?.email || '',
    password: '',
    password_confirmation: '',
    roles: props.user ? props.user.roles.map((role) => role.id) : [],
});

const { formRef } = useFocusFirstField(() => form.errors);
const { busy, submit: submitWithLock } = useSubmitLock();

function roleLockReason(role) {
    if (props.isSelf) {
        return 'Anda tidak dapat mengubah peran akun sendiri.';
    }

    if (!role.assignable) {
        return 'Peran ini memuat izin yang tidak Anda miliki.';
    }

    if (props.isLastSuperAdmin && role.name === 'super_admin') {
        return 'Ini Super Administrator aktif yang terakhir.';
    }

    return '';
}

function submit() {
    submitWithLock((options) => {
        if (isEdit.value) {
            form.put(`/admin/users/${props.user.id}`, { ...options, preserveScroll: true });
        } else {
            form.post('/admin/users', { ...options, preserveScroll: true });
        }
    });
}
</script>

<template>
    <Card padding="lg" class="max-w-2xl">
        <Alert v-if="form.errors.form" variant="danger" class="mb-6">
            {{ form.errors.form }}
        </Alert>

        <form ref="formRef" class="space-y-5" novalidate @submit.prevent="submit">
            <FormField id="user-name" label="Nama" :error="form.errors.name" required>
                <template #default="{ id, invalid, describedBy }">
                    <TextInput
                        :id="id"
                        v-model="form.name"
                        name="name"
                        autocomplete="off"
                        :invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </template>
            </FormField>

            <FormField id="user-email" label="Email" :error="form.errors.email" required>
                <template #default="{ id, invalid, describedBy }">
                    <TextInput
                        :id="id"
                        v-model="form.email"
                        name="email"
                        type="email"
                        autocomplete="off"
                        :invalid="invalid"
                        :aria-describedby="describedBy"
                    />
                </template>
            </FormField>

            <template v-if="!isEdit">
                <FormField
                    id="user-password"
                    label="Kata Sandi Awal"
                    :error="form.errors.password"
                    help="Minimal 12 karakter. Sampaikan kepada pengguna lewat jalur yang aman dan minta untuk segera menggantinya."
                    required
                >
                    <template #default="{ id, invalid, describedBy }">
                        <PasswordInput
                            :id="id"
                            v-model="form.password"
                            name="password"
                            autocomplete="new-password"
                            required
                            :invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>

                <FormField id="user-password-confirmation" label="Konfirmasi Kata Sandi" required>
                    <template #default="{ id, invalid, describedBy }">
                        <PasswordInput
                            :id="id"
                            v-model="form.password_confirmation"
                            name="password_confirmation"
                            autocomplete="new-password"
                            required
                            :invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>
            </template>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium text-slate-700 dark:text-slate-300">Peran</legend>
                <p v-if="isSelf" class="text-xs text-slate-500 dark:text-slate-400">
                    Anda tidak dapat mengubah peran akun sendiri.
                </p>
                <p v-if="form.errors.roles" class="text-xs text-rose-600 dark:text-rose-400">
                    {{ form.errors.roles }}
                </p>
                <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                    <Checkbox
                        v-for="role in assignableRoles"
                        :id="`user-role-${role.id}`"
                        :key="role.id"
                        v-model="form.roles"
                        :value="role.id"
                        :label="role.display_name"
                        :description="roleLockReason(role) || role.description || ''"
                        :disabled="roleLockReason(role) !== ''"
                    />
                </div>
            </fieldset>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-700">
                <Link
                    :href="isEdit ? `/admin/users/${user.id}` : '/admin/users'"
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
                    {{ isEdit ? 'Perbarui Pengguna' : 'Simpan Pengguna' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
