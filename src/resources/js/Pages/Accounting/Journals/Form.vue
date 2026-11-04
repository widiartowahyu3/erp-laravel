<script setup>
import { computed } from 'vue';
import { useForm, Link, router } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { useFocusFirstField } from '@/composables/useFocusFirstField';
import { useSubmitLock } from '@/composables/useSubmitLock';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Select from '@/Components/ui/Select.vue';
import Button from '@/Components/ui/Button.vue';
import IconButton from '@/Components/ui/IconButton.vue';
import Card from '@/Components/ui/Card.vue';
import Alert from '@/Components/ui/Alert.vue';
import { compareMoney, isValidMoney, subtractMoney, sumMoney } from '@/Support/money';
import { trimDecimal } from '@/Support/decimal';

const props = defineProps({
    journal: {
        type: Object,
        default: null,
    },
    accounts: {
        type: Array,
        default: () => [],
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
});

const MIN_LINES = 2;

const today = new Date().toISOString().split('T')[0];

function blankLine() {
    return { account_id: '', debit: '', credit: '', description: '' };
}

function initialAmount(value) {
    const trimmed = trimDecimal(value || '0');

    return trimmed === '0' ? '' : trimmed;
}

const form = useForm({
    journal_date: props.journal?.journal_date ? String(props.journal.journal_date).split('T')[0] : today,
    description: props.journal?.description || '',
    lines: props.journal?.lines?.length
        ? props.journal.lines.map((line) => ({
            account_id: String(line.account_id),
            debit: initialAmount(line.debit),
            credit: initialAmount(line.credit),
            description: line.description || '',
        }))
        : [blankLine(), blankLine()],
});

const { formRef } = useFocusFirstField(() => form.errors);
const { busy, submit: submitWithLock } = useSubmitLock();

const accountOptions = computed(() => [
    { value: '', label: '-- Pilih Akun --' },
    ...props.accounts.map((account) => ({ value: String(account.id), label: `${account.code} - ${account.name}` })),
]);

const amountsValid = computed(() => form.lines.every((line) => isValidMoney(line.debit) && isValidMoney(line.credit)));

const totalDebit = computed(() => (amountsValid.value ? sumMoney(form.lines.map((line) => line.debit)) : null));
const totalCredit = computed(() => (amountsValid.value ? sumMoney(form.lines.map((line) => line.credit)) : null));

const difference = computed(() => (amountsValid.value ? subtractMoney(totalDebit.value, totalCredit.value) : null));

const isBalanced = computed(() => (
    amountsValid.value
    && compareMoney(totalDebit.value, totalCredit.value) === 0
    && compareMoney(totalDebit.value, '0') > 0
));

function setDebit(line, value) {
    line.debit = value;
    if (value !== '') line.credit = '';
}

function setCredit(line, value) {
    line.credit = value;
    if (value !== '') line.debit = '';
}

function addLine() {
    form.lines.push(blankLine());
}

function removeLine(index) {
    if (form.lines.length > MIN_LINES) {
        form.lines.splice(index, 1);
    }
}

function send(options) {
    if (props.isEdit) {
        form.put(`/accounting/journals/${props.journal.id}`, { ...options, preserveScroll: true });
    } else {
        form.post('/accounting/journals', { ...options, preserveScroll: true });
    }
}

function save() {
    submitWithLock((options) => send(options));
}

function saveAndPost() {
    if (!isBalanced.value) return;

    submitWithLock((options) => send({
        ...options,
        onSuccess: (page) => {
            router.post(`/accounting/journals/${page.props.item.id}/post`, {}, { preserveScroll: true });
        },
    }));
}
</script>

<template>
    <form ref="formRef" class="space-y-6" novalidate @submit.prevent="save">
        <Alert v-if="form.errors.status" variant="danger">
            {{ form.errors.status }}
        </Alert>
        <Alert v-if="form.errors.form" variant="danger">
            {{ form.errors.form }}
        </Alert>

        <Card>
            <template #header>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Informasi Jurnal</h2>
            </template>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <FormField id="journal-date" label="Tanggal Jurnal" :error="form.errors.journal_date" required>
                    <template #default="{ id, describedBy, hasError }">
                        <TextInput
                            :id="id"
                            v-model="form.journal_date"
                            type="date"
                            :invalid="hasError"
                            :aria-describedby="describedBy"
                            required
                        />
                    </template>
                </FormField>

                <div class="md:col-span-2">
                    <FormField id="journal-description" label="Deskripsi" :error="form.errors.description" required>
                        <template #default="{ id, describedBy, hasError }">
                            <TextInput
                                :id="id"
                                v-model="form.description"
                                placeholder="Contoh: Setoran modal awal"
                                :invalid="hasError"
                                :aria-describedby="describedBy"
                                required
                            />
                        </template>
                    </FormField>
                </div>
            </div>
        </Card>

        <Card>
            <template #header>
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Baris Jurnal</h2>
                    <Button type="button" variant="secondary" size="sm" @click="addLine">
                        <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                        Tambah Baris
                    </Button>
                </div>
            </template>

            <Alert v-if="form.errors.lines" variant="danger" class="mb-4">
                {{ form.errors.lines }}
            </Alert>

            <div class="overflow-x-auto -mx-4 sm:mx-0">
                <table class="w-full text-left text-sm border-collapse">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-400">
                            <th class="py-2 px-3 min-w-[220px]">Akun</th>
                            <th class="py-2 px-3 w-40 text-right">Debit</th>
                            <th class="py-2 px-3 w-40 text-right">Kredit</th>
                            <th class="py-2 px-3 min-w-[180px]">Keterangan</th>
                            <th class="py-2 px-3 w-12"><span class="sr-only">Hapus</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="(line, index) in form.lines" :key="index" class="align-top" data-testid="journal-line">
                            <td class="py-2 px-3">
                                <Select
                                    v-model="line.account_id"
                                    :options="accountOptions"
                                    :invalid="Boolean(form.errors[`lines.${index}.account_id`])"
                                    :aria-label="`Akun baris ${index + 1}`"
                                />
                                <p v-if="form.errors[`lines.${index}.account_id`]" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                                    {{ form.errors[`lines.${index}.account_id`] }}
                                </p>
                            </td>
                            <td class="py-2 px-3">
                                <TextInput
                                    :model-value="line.debit"
                                    inputmode="decimal"
                                    class="text-right font-mono"
                                    :invalid="Boolean(form.errors[`lines.${index}.debit`])"
                                    :aria-label="`Debit baris ${index + 1}`"
                                    @update:model-value="setDebit(line, $event)"
                                />
                                <p v-if="form.errors[`lines.${index}.debit`]" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                                    {{ form.errors[`lines.${index}.debit`] }}
                                </p>
                            </td>
                            <td class="py-2 px-3">
                                <TextInput
                                    :model-value="line.credit"
                                    inputmode="decimal"
                                    class="text-right font-mono"
                                    :invalid="Boolean(form.errors[`lines.${index}.credit`])"
                                    :aria-label="`Kredit baris ${index + 1}`"
                                    @update:model-value="setCredit(line, $event)"
                                />
                                <p v-if="form.errors[`lines.${index}.credit`]" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                                    {{ form.errors[`lines.${index}.credit`] }}
                                </p>
                            </td>
                            <td class="py-2 px-3">
                                <TextInput v-model="line.description" :aria-label="`Keterangan baris ${index + 1}`" />
                            </td>
                            <td class="py-2 px-3 text-center">
                                <IconButton
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    title="Hapus Baris"
                                    :aria-label="`Hapus baris ${index + 1}`"
                                    :disabled="form.lines.length <= MIN_LINES"
                                    @click="removeLine(index)"
                                >
                                    <Trash2 class="w-4 h-4 text-rose-600 dark:text-rose-400" />
                                </IconButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <dl class="mt-4 flex flex-col items-end gap-1 pt-3 border-t border-slate-100 dark:border-slate-800 text-sm">
                <div class="flex w-72 justify-between">
                    <dt class="text-slate-600 dark:text-slate-400">Total Debit</dt>
                    <dd class="font-mono" data-testid="total-debit">{{ totalDebit ?? '-' }}</dd>
                </div>
                <div class="flex w-72 justify-between">
                    <dt class="text-slate-600 dark:text-slate-400">Total Kredit</dt>
                    <dd class="font-mono" data-testid="total-credit">{{ totalCredit ?? '-' }}</dd>
                </div>
                <div class="flex w-72 justify-between font-semibold">
                    <dt class="text-slate-800 dark:text-slate-200">Selisih</dt>
                    <dd class="font-mono" :class="isBalanced ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" data-testid="difference">
                        {{ difference ?? 'Nilai tidak valid' }}
                    </dd>
                </div>
            </dl>
        </Card>

        <div class="flex items-center justify-end gap-3">
            <Link
                href="/accounting/journals"
                class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300"
            >
                Batal
            </Link>

            <Button
                type="submit"
                variant="secondary"
                :loading="form.processing || busy"
                :disabled="form.processing || busy"
                :aria-busy="form.processing || busy"
                data-testid="save-button"
            >
                Simpan Draf
            </Button>

            <Button
                type="button"
                variant="primary"
                :loading="form.processing || busy"
                :disabled="form.processing || busy || !isBalanced"
                :aria-busy="form.processing || busy"
                data-testid="post-button"
                @click="saveAndPost"
            >
                Simpan dan Posting
            </Button>
        </div>
    </form>
</template>
