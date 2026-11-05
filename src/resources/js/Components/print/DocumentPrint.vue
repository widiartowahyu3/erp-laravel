<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import PrintLayout from '@/Layouts/PrintLayout.vue';
import Button from '@/Components/ui/Button.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import statusMap from '@/generated/enums.json';

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    number: {
        type: String,
        required: true,
    },
    status: {
        type: String,
        required: true,
    },
    backUrl: {
        type: String,
        required: true,
    },
    // The other side of the document: label, name and address.
    party: {
        type: Object,
        default: null,
    },
    // Label and value pairs, with date: true for dates.
    facts: {
        type: Array,
        default: () => [],
    },
    notes: {
        type: String,
        default: '',
    },
    // Who signs at the bottom of the page.
    signatures: {
        type: Array,
        default: () => [],
    },
});

const companyName = 'ERP Distribusi/Trading';

const signatureColumns = { 1: 'grid-cols-1', 2: 'grid-cols-2', 3: 'grid-cols-3' };

// A printed draft or voided document must not pass for a valid one.
const stamp = computed(() => (['draft', 'void'].includes(props.status) ? statusMap[props.status]?.label : null));

function printPage() {
    window.print();
}
</script>

<template>
    <PrintLayout>
        <Head :title="`Cetak ${title}: ${number}`" />

        <div class="mb-6 flex items-center justify-end gap-2 print:hidden">
            <Link :href="backUrl">
                <Button variant="secondary" size="sm">
                    <ArrowLeft class="mr-1.5 h-4 w-4" aria-hidden="true" />
                    Kembali
                </Button>
            </Link>
            <Button variant="primary" size="sm" data-test="print" @click="printPage">
                <Printer class="mr-1.5 h-4 w-4" aria-hidden="true" />
                Cetak
            </Button>
        </div>

        <header class="flex items-start justify-between border-b border-slate-300 pb-4">
            <p class="text-lg font-bold">{{ companyName }}</p>
            <div class="text-right">
                <h1 class="text-xl font-bold">{{ title }}</h1>
                <p class="font-mono text-sm">{{ number }}</p>
                <p v-if="stamp" class="mt-1 text-sm font-bold uppercase" data-test="stamp">{{ stamp }}</p>
            </div>
        </header>

        <section class="mt-4 grid grid-cols-2 gap-6 text-sm" aria-label="Data dokumen">
            <div>
                <template v-if="party">
                    <p class="text-xs text-slate-600">{{ party.label }}:</p>
                    <p class="font-semibold">{{ party.name }}</p>
                    <p class="whitespace-pre-line">{{ party.address }}</p>
                </template>
            </div>
            <dl class="space-y-1">
                <div v-for="fact in facts" :key="fact.label" class="flex justify-between gap-4">
                    <dt class="text-slate-600">{{ fact.label }}</dt>
                    <dd>
                        <DateDisplay v-if="fact.date" :value="fact.value" />
                        <template v-else>{{ fact.value ?? '-' }}</template>
                    </dd>
                </div>
            </dl>
        </section>

        <slot />

        <section v-if="notes" class="mt-4 break-inside-avoid text-sm" aria-label="Catatan">
            <p class="text-xs text-slate-600">Catatan:</p>
            <p class="whitespace-pre-line">{{ notes }}</p>
        </section>

        <section
            v-if="signatures.length > 0"
            :class="['mt-12 grid break-inside-avoid gap-6 text-center text-sm', signatureColumns[signatures.length]]"
            aria-label="Tanda tangan"
        >
            <div v-for="signature in signatures" :key="signature">
                <p>{{ signature }}</p>
                <p class="mt-16 border-t border-slate-400 pt-1 text-xs text-slate-600">Nama dan tanda tangan</p>
            </div>
        </section>
    </PrintLayout>
</template>
