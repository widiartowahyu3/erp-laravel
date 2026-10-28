<script setup>
import { computed } from 'vue';
import { ArrowUpDown, ArrowUp, ArrowDown } from 'lucide-vue-next';
import Rupiah from './Rupiah.vue';
import Quantity from './Quantity.vue';
import DateDisplay from './DateDisplay.vue';
import StatusBadge from './StatusBadge.vue';
import EmptyState from './EmptyState.vue';
import ErrorState from './ErrorState.vue';
import LoadingState from './LoadingState.vue';

const props = defineProps({
    columns: {
        type: Array,
        required: true,
    },
    data: {
        type: Array,
        default: () => [],
    },
    sortKey: {
        type: String,
        default: '',
    },
    sortDirection: {
        type: String,
        default: 'asc',
        validator: (value) => ['asc', 'desc'].includes(value),
    },
    loading: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    stickyHeader: {
        type: Boolean,
        default: false,
    },
    rowClickable: {
        type: Boolean,
        default: false,
    },
    emptyTitle: {
        type: String,
        default: 'Tidak ada data',
    },
    emptyDescription: {
        type: String,
        default: 'Belum ada data untuk ditampilkan.',
    },
});

const emit = defineEmits(['sort', 'row-click', 'retry']);

function handleSort(col) {
    if (!col.sortable) return;
    let nextDirection = 'asc';
    if (props.sortKey === col.key) {
        nextDirection = props.sortDirection === 'asc' ? 'desc' : 'asc';
    }
    emit('sort', { key: col.key, direction: nextDirection });
}

function handleRowClick(row, event) {
    if (!props.rowClickable) return;
    // Don't trigger if clicked on a button or link or dropdown
    if (event.target.closest('button, a, input, [role="menu"]')) return;
    emit('row-click', row);
}

const alignClasses = {
    left: 'text-left',
    center: 'text-center',
    right: 'text-right',
};

const cellAlignClasses = {
    left: 'text-left justify-start',
    center: 'text-center justify-center',
    right: 'text-right justify-end',
};
</script>

<template>
    <div class="w-full rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-xs overflow-hidden">
        <!-- Scrollable Table Container -->
        <div class="overflow-x-auto w-full">
            <table class="w-full text-left border-collapse text-sm">
                <thead :class="['bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800', stickyHeader ? 'sticky top-0 z-10' : '']">
                    <tr>
                        <th
                            v-for="col in columns"
                            :key="col.key"
                            scope="col"
                            :class="[
                                'px-4 py-3 text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider select-none whitespace-nowrap',
                                alignClasses[col.align || 'left'],
                                col.sortable ? 'cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors' : '',
                            ]"
                            @click="handleSort(col)"
                        >
                            <div :class="['inline-flex items-center gap-1.5', cellAlignClasses[col.align || 'left']]">
                                <span>{{ col.label }}</span>
                                <span v-if="col.sortable" class="shrink-0 text-slate-400 dark:text-slate-500">
                                    <ArrowUp
                                        v-if="sortKey === col.key && sortDirection === 'asc'"
                                        class="w-3.5 h-3.5 text-indigo-600 dark:text-indigo-400"
                                        aria-hidden="true"
                                    />
                                    <ArrowDown
                                        v-else-if="sortKey === col.key && sortDirection === 'desc'"
                                        class="w-3.5 h-3.5 text-indigo-600 dark:text-indigo-400"
                                        aria-hidden="true"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="w-3.5 h-3.5"
                                        aria-hidden="true"
                                    />
                                </span>
                            </div>
                        </th>
                    </tr>
                </thead>

                <!-- Body with Data -->
                <tbody v-if="!loading && !error && data.length > 0" class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr
                        v-for="(row, rowIdx) in data"
                        :key="row.id || rowIdx"
                        :class="[
                            'transition-colors',
                            rowClickable
                                ? 'cursor-pointer hover:bg-slate-50/80 dark:hover:bg-slate-800/40'
                                : 'hover:bg-slate-50/40 dark:hover:bg-slate-800/20',
                        ]"
                        @click="handleRowClick(row, $event)"
                    >
                        <td
                            v-for="col in columns"
                            :key="col.key"
                            :class="[
                                'px-4 py-3 text-slate-800 dark:text-slate-200',
                                alignClasses[col.align || 'left'],
                            ]"
                        >
                            <slot
                                :name="`cell(${col.key})`"
                                :row="row"
                                :column="col"
                                :value="row[col.key]"
                            >
                                <!-- Auto Formatter by Column Type -->
                                <template v-if="col.type === 'rupiah'">
                                    <Rupiah :value="row[col.key]" />
                                </template>
                                <template v-else-if="col.type === 'quantity'">
                                    <Quantity :value="row[col.key]" :unit="col.unit" />
                                </template>
                                <template v-else-if="col.type === 'date'">
                                    <DateDisplay :value="row[col.key]" :format="col.format || 'short'" />
                                </template>
                                <template v-else-if="col.type === 'status'">
                                    <StatusBadge :status="row[col.key]" size="sm" />
                                </template>
                                <template v-else>
                                    {{ row[col.key] ?? '-' }}
                                </template>
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Integrated States -->
        <LoadingState v-if="loading" />

        <ErrorState
            v-else-if="error"
            :message="error"
            @retry="$emit('retry')"
        />

        <EmptyState
            v-else-if="data.length === 0"
            :title="emptyTitle"
            :description="emptyDescription"
        />
    </div>
</template>
