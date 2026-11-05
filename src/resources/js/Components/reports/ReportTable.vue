<script setup>
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import Quantity from '@/Components/ui/Quantity.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';

defineProps({
    // Each column: key, label, and optionally type (money, quantity, date) and align.
    columns: {
        type: Array,
        required: true,
    },
    rows: {
        type: Array,
        default: () => [],
    },
    // Values for a totals row, by column key.
    footer: {
        type: Object,
        default: null,
    },
    emptyTitle: {
        type: String,
        default: 'Tidak ada data',
    },
    emptyDescription: {
        type: String,
        default: 'Belum ada data untuk periode ini.',
    },
});

function isNumeric(column) {
    return column.type === 'money' || column.type === 'quantity' || column.align === 'right';
}
</script>

<template>
    <EmptyState v-if="rows.length === 0" :title="emptyTitle" :description="emptyDescription" />

    <div v-else class="report-table overflow-x-auto print:overflow-visible">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:text-slate-400">
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        scope="col"
                        :class="['whitespace-nowrap px-3 py-2', isNumeric(column) ? 'text-right' : 'text-left']"
                    >
                        {{ column.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(row, index) in rows"
                    :key="index"
                    class="border-b border-slate-100 last:border-0 dark:border-slate-800"
                >
                    <td
                        v-for="column in columns"
                        :key="column.key"
                        :class="['px-3 py-2 align-top', isNumeric(column) ? 'text-right' : 'text-left']"
                    >
                        <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">
                            <Rupiah v-if="column.type === 'money'" :value="row[column.key]" />
                            <Quantity v-else-if="column.type === 'quantity'" :value="row[column.key]" />
                            <DateDisplay v-else-if="column.type === 'date'" :value="row[column.key]" />
                            <template v-else>{{ row[column.key] ?? '-' }}</template>
                        </slot>
                    </td>
                </tr>
            </tbody>
            <tfoot v-if="footer">
                <tr class="border-t-2 border-slate-300 font-semibold dark:border-slate-600">
                    <td
                        v-for="column in columns"
                        :key="column.key"
                        :class="['px-3 py-2', isNumeric(column) ? 'text-right' : 'text-left']"
                    >
                        <Rupiah v-if="column.type === 'money' && footer[column.key] !== undefined" :value="footer[column.key]" />
                        <template v-else>{{ footer[column.key] ?? '' }}</template>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
