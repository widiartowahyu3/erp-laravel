<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import ExportButton from '@/Components/reports/ExportButton.vue';
import DataTable from '@/Components/ui/DataTable.vue';
import FilterBar from '@/Components/ui/FilterBar.vue';
import Pagination from '@/Components/ui/Pagination.vue';
import Button from '@/Components/ui/Button.vue';
import Select from '@/Components/ui/Select.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import StatusBadge from '@/Components/ui/StatusBadge.vue';
import DateDisplay from '@/Components/ui/DateDisplay.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';
import Badge from '@/Components/ui/Badge.vue';
import Can from '@/Components/ui/Can.vue';
import { useListQuery } from '@/composables/useListQuery';
import statusMap from '@/generated/enums.json';

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
    statuses: {
        type: Array,
        default: () => [],
    },
    sources: {
        type: Array,
        default: () => [],
    },
});

const { search, filters, setSearch, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/accounting/journals', {
    defaults: {
        search: props.items.query?.search || '',
        perPage: props.items.meta?.per_page || 25,
        filters: {
            status: props.items.query?.filter?.status || '',
            source_type: props.items.query?.filter?.source_type || '',
            date_from: props.items.query?.filter?.date_from || '',
            date_to: props.items.query?.filter?.date_to || '',
        },
    },
});

const statusOptions = computed(() => [
    { value: '', label: 'Semua Status' },
    ...props.statuses.map((status) => ({ value: status, label: statusMap[status]?.label ?? status })),
]);

const sourceOptions = computed(() => [
    { value: '', label: 'Semua Sumber' },
    { value: 'manual', label: 'Jurnal Manual' },
    ...props.sources.map((source) => ({ value: source.value, label: source.label })),
]);

const hasActiveFilters = computed(() => Boolean(
    search.value || filters.value.status || filters.value.source_type || filters.value.date_from || filters.value.date_to
));

const columns = [
    { key: 'number', label: 'Nomor' },
    { key: 'journal_date', label: 'Tanggal' },
    { key: 'description', label: 'Deskripsi' },
    { key: 'source', label: 'Sumber' },
    { key: 'total_debit', label: 'Total', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout title="Jurnal">
        <Head title="Jurnal" />

        <div class="space-y-6">
            <PageHeader title="Jurnal" description="Jurnal manual dan jurnal otomatis dari dokumen operasional.">
                <template #actions>
                    <ExportButton url="/accounting/journals" :query="items.query ?? {}" />
                    <Can permission="journals.create">
                        <Link href="/accounting/journals/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Buat Jurnal Manual
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <FilterBar
                :search="search"
                search-placeholder="Cari nomor atau deskripsi..."
                :has-active-filters="hasActiveFilters"
                @update:search="setSearch"
                @reset="resetFilters"
            >
                <div class="w-full sm:w-44">
                    <Select
                        :model-value="filters.status"
                        :options="statusOptions"
                        size="sm"
                        aria-label="Filter berdasarkan status"
                        @update:model-value="setFilter('status', $event)"
                    />
                </div>

                <div class="w-full sm:w-48">
                    <Select
                        :model-value="filters.source_type"
                        :options="sourceOptions"
                        size="sm"
                        aria-label="Filter berdasarkan sumber"
                        @update:model-value="setFilter('source_type', $event)"
                    />
                </div>

                <div class="w-full sm:w-36">
                    <TextInput
                        :model-value="filters.date_from"
                        type="date"
                        size="sm"
                        aria-label="Filter tanggal mulai"
                        @update:model-value="setFilter('date_from', $event)"
                    />
                </div>

                <div class="w-full sm:w-36">
                    <TextInput
                        :model-value="filters.date_to"
                        type="date"
                        size="sm"
                        aria-label="Filter tanggal akhir"
                        @update:model-value="setFilter('date_to', $event)"
                    />
                </div>
            </FilterBar>

            <DataTable
                :columns="columns"
                :data="items.data"
                empty-title="Belum ada jurnal"
                empty-description="Belum ada jurnal yang cocok untuk ditampilkan."
            >
                <template #cell(number)="{ row }">
                    <Link
                        :href="`/accounting/journals/${row.id}`"
                        class="text-indigo-600 dark:text-indigo-400 font-semibold hover:underline"
                    >
                        {{ row.number }}
                    </Link>
                </template>

                <template #cell(journal_date)="{ row }">
                    <DateDisplay :value="row.journal_date" />
                </template>

                <template #cell(source)="{ row }">
                    <Link
                        v-if="row.sourceUrl"
                        :href="row.sourceUrl"
                        class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                    >
                        {{ row.sourceLabel }}
                    </Link>
                    <span v-else class="text-xs text-slate-600 dark:text-slate-400">
                        {{ row.sourceLabel || 'Jurnal Manual' }}
                    </span>
                </template>

                <template #cell(total_debit)="{ row }">
                    <span class="font-mono text-xs font-semibold text-slate-800 dark:text-slate-200">
                        <Rupiah :value="row.total_debit" />
                    </span>
                </template>

                <template #cell(status)="{ row }">
                    <div class="flex flex-wrap items-center gap-1">
                        <StatusBadge :status="row.status" />
                        <Badge v-if="row.isReversal" variant="info">Pembalik</Badge>
                        <Badge v-if="row.isReversed" variant="warning">Sudah dibalik</Badge>
                    </div>
                </template>
            </DataTable>

            <Pagination
                :meta="items.meta"
                @change-page="setPage"
                @change-per-page="setPerPage"
            />
        </div>
    </AppLayout>
</template>
