<script setup>
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { useFocusFirstField } from '@/composables/useFocusFirstField';
import { useSubmitLock } from '@/composables/useSubmitLock';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Select from '@/Components/ui/Select.vue';
import Switch from '@/Components/ui/Switch.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import Alert from '@/Components/ui/Alert.vue';

const props = defineProps({
    account: {
        type: Object,
        default: null,
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
    isUsed: {
        type: Boolean,
        default: false,
    },
    isMapped: {
        type: Boolean,
        default: false,
    },
    parents: {
        type: Array,
        default: () => [],
    },
    types: {
        type: Array,
        default: () => [],
    },
    normalBalances: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    code: props.account?.code || '',
    name: props.account?.name || '',
    type: props.account?.type || '',
    normal_balance: props.account?.normal_balance || '',
    parent_id: props.account?.parent_id ? String(props.account.parent_id) : '',
    is_postable: props.account ? Boolean(props.account.is_postable) : true,
    is_active: props.account ? Boolean(props.account.is_active) : true,
});

const { formRef } = useFocusFirstField(() => form.errors);
const { busy, submit: submitWithLock } = useSubmitLock();

const parentOptions = computed(() => [
    { value: '', label: '-- Tanpa Induk --' },
    ...props.parents.map((parent) => ({ value: String(parent.id), label: `${parent.code} - ${parent.name}` })),
]);

const typeOptions = computed(() => [
    { value: '', label: '-- Pilih Tipe --' },
    ...props.types.map((type) => ({ value: type.value, label: type.label })),
]);

const normalBalanceOptions = computed(() => [
    { value: '', label: '-- Pilih Saldo Normal --' },
    ...props.normalBalances.map((balance) => ({ value: balance.value, label: balance.label })),
]);

const lockedHelp = computed(() => {
    if (props.isUsed) return 'Terkunci karena akun sudah dipakai pada jurnal.';
    return undefined;
});

const mappedHelp = computed(() => {
    if (props.isMapped) return 'Terkunci karena akun dipetakan pada konfigurasi akuntansi.';
    return undefined;
});

function submit() {
    submitWithLock((options) => {
        if (props.isEdit) {
            form.put(`/accounting/accounts/${props.account.id}`, { ...options, preserveScroll: true });
        } else {
            form.post('/accounting/accounts', { ...options, preserveScroll: true });
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
            <FormField
                id="account-code"
                label="Kode Akun"
                :error="form.errors.code"
                :help="lockedHelp || mappedHelp"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.code"
                        placeholder="Contoh: 1103"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        :disabled="isUsed || isMapped"
                        class="uppercase"
                        required
                    />
                </template>
            </FormField>

            <FormField id="account-name" label="Nama Akun" :error="form.errors.name" required>
                <template #default="{ id, describedBy, hasError }">
                    <TextInput
                        :id="id"
                        v-model="form.name"
                        placeholder="Contoh: Kas Kecil"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        required
                    />
                </template>
            </FormField>

            <FormField id="account-type" label="Tipe" :error="form.errors.type" :help="lockedHelp" required>
                <template #default="{ id, describedBy, hasError }">
                    <Select
                        :id="id"
                        v-model="form.type"
                        :options="typeOptions"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        :disabled="isUsed"
                    />
                </template>
            </FormField>

            <FormField
                id="account-normal-balance"
                label="Saldo Normal"
                :error="form.errors.normal_balance"
                :help="lockedHelp"
                required
            >
                <template #default="{ id, describedBy, hasError }">
                    <Select
                        :id="id"
                        v-model="form.normal_balance"
                        :options="normalBalanceOptions"
                        :invalid="hasError"
                        :aria-describedby="describedBy"
                        :disabled="isUsed"
                    />
                </template>
            </FormField>

            <FormField
                id="account-parent"
                label="Akun Induk"
                :error="form.errors.parent_id"
                help="Hanya akun induk yang dapat dipilih."
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

            <div class="space-y-3 pt-2">
                <Switch
                    v-model="form.is_postable"
                    label="Dapat Diposting"
                    description="Nonaktifkan untuk akun induk yang hanya mengelompokkan akun lain."
                    :disabled="isUsed || isMapped"
                />
                <p v-if="form.errors.is_postable" class="text-xs text-rose-600 dark:text-rose-400">
                    {{ form.errors.is_postable }}
                </p>

                <Switch
                    v-model="form.is_active"
                    label="Akun Aktif"
                    description="Akun nonaktif tidak dapat dipakai pada jurnal baru."
                    :disabled="isMapped"
                />
                <p v-if="form.errors.is_active" class="text-xs text-rose-600 dark:text-rose-400">
                    {{ form.errors.is_active }}
                </p>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                <Link
                    href="/accounting/accounts"
                    class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300"
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
                    {{ isEdit ? 'Perbarui Akun' : 'Simpan Akun' }}
                </Button>
            </div>
        </form>
    </Card>
</template>
