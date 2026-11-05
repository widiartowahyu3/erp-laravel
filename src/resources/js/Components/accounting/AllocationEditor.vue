<script setup>
import { computed, ref, watch } from 'vue';
import { Wand2 } from 'lucide-vue-next';
import Card from '@/Components/ui/Card.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Button from '@/Components/ui/Button.vue';
import Alert from '@/Components/ui/Alert.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import { compareMoney, isValidMoney, subtractMoney, sumMoney } from '@/Support/money';

const props = defineProps({
    invoices: {
        type: Array,
        default: () => [],
    },
    amount: {
        type: [String, Number],
        default: '',
    },
    // Amounts already saved, keyed by invoice id.
    saved: {
        type: Object,
        default: () => ({}),
    },
    // Server messages keyed by invoice id.
    rowErrors: {
        type: Object,
        default: () => ({}),
    },
    error: {
        type: String,
        default: null,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    partyChosen: {
        type: Boolean,
        default: false,
    },
    choosePartyMessage: {
        type: String,
        default: '',
    },
    noInvoicesMessage: {
        type: String,
        default: '',
    },
    description: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['change']);

function buildRows(invoices) {
    return invoices.map((invoice) => ({ ...invoice, allocation: props.saved[invoice.id] ?? '' }));
}

const rows = ref(buildRows(props.invoices));

watch(() => props.invoices, (invoices) => {
    rows.value = buildRows(invoices);
});

function clean(value) {
    return String(value ?? '').trim();
}

const amountIsValid = computed(() => clean(props.amount) !== '' && isValidMoney(clean(props.amount)));
const amountValue = computed(() => (amountIsValid.value ? clean(props.amount) : '0.00'));

function rowProblem(row) {
    const value = clean(row.allocation);

    if (value === '') {
        return null;
    }

    if (!isValidMoney(value)) {
        return 'Isi dengan angka, maksimal 2 desimal. Gunakan titik sebagai pemisah desimal.';
    }

    return compareMoney(value, row.outstanding) > 0 ? 'Melebihi sisa tagihan.' : null;
}

const allocated = computed(() => sumMoney(rows.value
    .map((row) => clean(row.allocation))
    .filter((value) => value !== '' && isValidMoney(value))));

const unallocated = computed(() => subtractMoney(amountValue.value, allocated.value));

const hasRowProblems = computed(() => rows.value.some((row) => rowProblem(row) !== null));

const fullyAllocated = computed(() => amountIsValid.value
    && compareMoney(amountValue.value, '0') > 0
    && compareMoney(allocated.value, amountValue.value) === 0
    && !hasRowProblems.value);

const lines = computed(() => rows.value
    .filter((row) => clean(row.allocation) !== '' && isValidMoney(clean(row.allocation)) && compareMoney(clean(row.allocation), '0') > 0)
    .map((row) => ({ invoiceId: row.id, amount: clean(row.allocation) })));

// Synchronous so the parent never acts on a state older than what the user sees.
watch(
    () => ({
        lines: lines.value,
        allocated: allocated.value,
        amountIsValid: amountIsValid.value,
        exceedsAmount: compareMoney(allocated.value, amountValue.value) > 0,
        hasRowProblems: hasRowProblems.value,
        fullyAllocated: fullyAllocated.value,
    }),
    (state) => emit('change', state),
    { immediate: true, flush: 'sync' }
);

// Oldest due date first, which is the order the server sends. Blank once the amount runs out.
function allocateAutomatically() {
    let remaining = amountValue.value;

    for (const row of rows.value) {
        if (compareMoney(remaining, '0') <= 0) {
            row.allocation = '';
            continue;
        }

        const share = compareMoney(remaining, row.outstanding) >= 0 ? row.outstanding : remaining;

        row.allocation = share;
        remaining = subtractMoney(remaining, share);
    }
}

function rowError(row) {
    return props.rowErrors[row.id] || rowProblem(row);
}
</script>

<template>
    <Card>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="space-y-0.5">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Alokasi ke Faktur</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ description }}</p>
                </div>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    :disabled="rows.length === 0 || !amountIsValid || disabled"
                    @click="allocateAutomatically"
                >
                    <Wand2 class="w-4 h-4 mr-1.5" aria-hidden="true" />
                    Alokasikan otomatis
                </Button>
            </div>
        </template>

        <Alert v-if="error" variant="danger" class="mb-4">{{ error }}</Alert>

        <p v-if="!partyChosen" class="text-sm text-slate-600 dark:text-slate-400">{{ choosePartyMessage }}</p>
        <p v-else-if="rows.length === 0" class="text-sm text-slate-600 dark:text-slate-400">{{ noInvoicesMessage }}</p>

        <div v-else class="overflow-x-auto -mx-4 sm:mx-0">
            <table class="w-full text-left text-sm border-collapse">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-400">
                        <th class="py-2.5 px-3">No. Faktur</th>
                        <th class="py-2.5 px-3">Jatuh Tempo</th>
                        <th class="py-2.5 px-3 text-right">Total</th>
                        <th class="py-2.5 px-3 text-right">Sisa</th>
                        <th class="py-2.5 px-3 w-44 text-right">Alokasi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="row in rows" :key="row.id" class="align-top">
                        <td class="py-3 px-3 font-mono text-xs">
                            {{ row.number }}
                            <span v-if="row.supplierInvoiceNumber" class="block text-slate-500 dark:text-slate-400">
                                {{ row.supplierInvoiceNumber }}
                            </span>
                        </td>
                        <td class="py-3 px-3"><DateDisplay :value="row.dueDate" /></td>
                        <td class="py-3 px-3 text-right"><Rupiah :value="row.total" /></td>
                        <td class="py-3 px-3 text-right"><Rupiah :value="row.outstanding" /></td>
                        <td class="py-3 px-3">
                            <TextInput
                                v-model="row.allocation"
                                inputmode="decimal"
                                class="text-right"
                                :disabled="disabled"
                                :aria-label="`Alokasi untuk faktur ${row.number}`"
                            />
                            <p v-if="rowError(row)" class="mt-1 text-xs text-red-700 dark:text-red-400">{{ rowError(row) }}</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <dl class="mt-4 ml-auto max-w-xs space-y-1 text-sm">
            <div class="flex justify-between">
                <dt class="text-slate-600 dark:text-slate-400">Dialokasikan</dt>
                <dd data-testid="allocated"><Rupiah :value="allocated" /></dd>
            </div>
            <div class="flex justify-between font-semibold">
                <dt class="text-slate-900 dark:text-slate-100">Sisa belum dialokasikan</dt>
                <dd data-testid="unallocated"><Rupiah :value="unallocated" /></dd>
            </div>
        </dl>
    </Card>
</template>
