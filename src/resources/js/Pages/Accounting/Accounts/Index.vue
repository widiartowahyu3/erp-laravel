<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Eye, Edit, Trash2, FolderTree, FileText } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import DataTable from '@/Components/ui/DataTable.vue';
import FilterBar from '@/Components/ui/FilterBar.vue';
import Pagination from '@/Components/ui/Pagination.vue';
import Button from '@/Components/ui/Button.vue';
import IconButton from '@/Components/ui/IconButton.vue';
import Select from '@/Components/ui/Select.vue';
import Badge from '@/Components/ui/Badge.vue';
import Can from '@/Components/ui/Can.vue';
import { useListQuery } from '@/composables/useListQuery';
import { useConfirm } from '@/composables/useConfirm';
import { useSubmitLock } from '@/composables/useSubmitLock';

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
    types: {
        type: Array,
        default: () => [],
    },
});

const { search, filters, setSearch, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/accounting/accounts', {
    defaults: {
        search: props.items.query?.search || '',
        perPage: props.items.meta?.per_page || 50,
        filters: {
            type: props.items.query?.filter?.type || '',
            is_active: props.items.query?.filter?.is_active ?? '',
        },
    },
});

const { confirm } = useConfirm();
const { busy, submit } = useSubmitLock();

const typeOptions = computed(() => [
    { value: '', label: 'Semua Tipe' },
    ...props.types.map((type) => ({ value: type.value, label: type.label })),
]);

const statusOptions = [
    { value: '', label: 'Semua Status' },
    { value: '1', label: 'Aktif' },
    { value: '0', label: 'Nonaktif' },
];

const hasActiveFilters = computed(() => Boolean(search.value || filters.value.type || filters.value.is_active !== ''));

const columns = [
    { key: 'code', label: 'Kode' },
    { key: 'name', label: 'Nama Akun' },
    { key: 'type', label: 'Tipe' },
    { key: 'normal_balance', label: 'Saldo Normal' },
    { key: 'is_active', label: 'Status' },
    { key: 'actions', label: 'Aksi', align: 'right' },
];

const indentClasses = ['', 'pl-5', 'pl-10', 'pl-14', 'pl-20'];

function indentClass(level) {
    return indentClasses[Math.min(level, indentClasses.length - 1)];
}

async function handleDelete(account) {
    const ok = await confirm({
        title: 'Hapus Akun',
        message: `Apakah Anda yakin ingin menghapus akun "${account.code} - ${account.name}"?`,
        confirmText: 'Ya, Hapus',
        variant: 'danger',
    });

    if (ok) {
        submit((options) => {
            router.delete(`/accounting/accounts/${account.id}`, {
                ...options,
                preserveScroll: true,
            });
        });
    }
}
</script>

<template>
    <AppLayout title="Bagan Akun">
        <Head title="Bagan Akun" />

        <div class="space-y-6">
            <PageHeader
                title="Bagan Akun"
                description="Daftar akun pembukuan beserta hierarki akun induk dan akun detail."
            >
                <template #actions>
                    <Can permission="chart_of_accounts.create">
                        <Link href="/accounting/accounts/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Tambah Akun
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <FilterBar
                :search="search"
                search-placeholder="Cari kode atau nama akun..."
                :has-active-filters="hasActiveFilters"
                @update:search="setSearch"
                @reset="resetFilters"
            >
                <div class="w-full sm:w-48">
                    <Select
                        :model-value="filters.type"
                        :options="typeOptions"
                        size="sm"
                        aria-label="Filter berdasarkan tipe akun"
                        @update:model-value="setFilter('type', $event)"
                    />
                </div>

                <div class="w-full sm:w-44">
                    <Select
                        :model-value="filters.is_active"
                        :options="statusOptions"
                        size="sm"
                        aria-label="Filter berdasarkan status akun"
                        @update:model-value="setFilter('is_active', $event)"
                    />
                </div>
            </FilterBar>

            <DataTable
                :columns="columns"
                :data="items.data"
                empty-title="Belum ada akun"
                empty-description="Belum ada akun yang cocok untuk ditampilkan."
            >
                <template #cell(code)="{ row }">
                    <Link
                        :href="`/accounting/accounts/${row.id}`"
                        class="font-mono text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                    >
                        {{ row.code }}
                    </Link>
                </template>

                <template #cell(name)="{ row }">
                    <div class="flex items-center gap-2" :class="indentClass(row.level)">
                        <FolderTree v-if="!row.is_postable" class="w-4 h-4 text-slate-500 shrink-0" aria-hidden="true" />
                        <FileText v-else class="w-4 h-4 text-slate-400 shrink-0" aria-hidden="true" />
                        <span :class="row.is_postable ? 'text-slate-800 dark:text-slate-200' : 'font-semibold text-slate-900 dark:text-slate-100'">
                            {{ row.name }}
                        </span>
                        <span v-if="!row.is_postable" class="text-xs text-slate-500 dark:text-slate-400">(Induk)</span>
                    </div>
                </template>

                <template #cell(type)="{ row }">
                    {{ row.type_label }}
                </template>

                <template #cell(normal_balance)="{ row }">
                    {{ row.normal_balance_label }}
                </template>

                <template #cell(is_active)="{ row }">
                    <Badge :variant="row.is_active ? 'success' : 'neutral'">
                        {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
                    </Badge>
                </template>

                <template #cell(actions)="{ row }">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="`/accounting/accounts/${row.id}`">
                            <IconButton variant="ghost" size="sm" title="Lihat Akun" :aria-label="`Lihat akun ${row.code}`">
                                <Eye class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                            </IconButton>
                        </Link>
                        <Can permission="chart_of_accounts.update">
                            <Link :href="`/accounting/accounts/${row.id}/edit`">
                                <IconButton variant="ghost" size="sm" title="Ubah Akun" :aria-label="`Ubah akun ${row.code}`">
                                    <Edit class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                                </IconButton>
                            </Link>
                        </Can>
                        <Can permission="chart_of_accounts.delete">
                            <IconButton
                                variant="ghost"
                                size="sm"
                                title="Hapus Akun"
                                :aria-label="`Hapus akun ${row.code}`"
                                :disabled="busy"
                                @click="handleDelete(row)"
                            >
                                <Trash2 class="w-4 h-4 text-rose-600 dark:text-rose-400" />
                            </IconButton>
                        </Can>
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
