<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Button from '@/Components/ui/Button.vue';
import FormField from '@/Components/ui/FormField.vue';
import TextInput from '@/Components/ui/TextInput.vue';

const props = defineProps({
    url: {
        type: String,
        required: true,
    },
    mode: {
        type: String,
        default: 'period',
        validator: (value) => ['period', 'as_of', 'none'].includes(value),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    // Other chosen values to send along, such as the account of a ledger.
    params: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();

const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');
const asOf = ref(props.filters.as_of ?? '');

watch(() => props.filters, (filters) => {
    dateFrom.value = filters.date_from ?? '';
    dateTo.value = filters.date_to ?? '';
    asOf.value = filters.as_of ?? '';
});

const errors = computed(() => page.props.errors ?? {});

function isChosen(value) {
    return value !== null && value !== undefined && value !== '' && value !== false;
}

function apply() {
    const query = {};

    if (props.mode === 'period') {
        query.date_from = dateFrom.value;
        query.date_to = dateTo.value;
    }

    if (props.mode === 'as_of') {
        query.as_of = asOf.value;
    }

    for (const [key, value] of Object.entries(props.params)) {
        if (isChosen(value)) {
            query[key] = value === true ? 1 : value;
        }
    }

    router.get(props.url, query, { preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
    <form class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start print:hidden" @submit.prevent="apply">
        <template v-if="mode === 'period'">
            <FormField v-slot="{ id, invalid }" label="Tanggal Awal" :error="errors.date_from" class="sm:w-44">
                <TextInput :id="id" v-model="dateFrom" type="date" :invalid="invalid" />
            </FormField>
            <FormField v-slot="{ id, invalid }" label="Tanggal Akhir" :error="errors.date_to" class="sm:w-44">
                <TextInput :id="id" v-model="dateTo" type="date" :invalid="invalid" />
            </FormField>
        </template>

        <FormField v-if="mode === 'as_of'" v-slot="{ id, invalid }" label="Tanggal Posisi" :error="errors.as_of" class="sm:w-44">
            <TextInput :id="id" v-model="asOf" type="date" :invalid="invalid" />
        </FormField>

        <slot :errors="errors" />

        <div class="sm:pt-6">
            <Button type="submit" variant="primary">Terapkan</Button>
        </div>
    </form>
</template>
