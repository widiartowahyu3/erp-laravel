<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Edit, Trash2 } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import DataTable from '@/Components/ui/DataTable.vue';
import FilterBar from '@/Components/ui/FilterBar.vue';
import Pagination from '@/Components/ui/Pagination.vue';
import Button from '@/Components/ui/Button.vue';
import IconButton from '@/Components/ui/IconButton.vue';
import Badge from '@/Components/ui/Badge.vue';
import Can from '@/Components/ui/Can.vue';
import { useListQuery } from '@/composables/useListQuery';
import { useConfirm } from '@/composables/useConfirm';
import { masterDataModules } from '@/config/master-data';

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
});

const config = masterDataModules.taxRates;
const { search, sortKey, sortDirection, setSearch, setSort, setPage, setPerPage } = useListQuery('/master/tax-rates', {
    defaults: {
        search: props.items.query?.search || '',
        sort: props.items.query?.sort || 'code',
        direction: props.items.query?.direction || 'asc',
        perPage: props.items.meta?.per_page || 25,
    },
});

const columns = computed(() => [
    ...config.columns,
    { key: 'actions', label: 'Aksi', sortable: false, align: 'right' },
]);

const { confirm } = useConfirm();

async function handleDelete(item) {
    const ok = await confirm({
        title: 'Hapus Tarif Pajak',
        message: `Apakah Anda yakin ingin menghapus tarif pajak "${item.name}" (${item.code})? Tindakan ini tidak dapat dibatalkan jika tarif sudah digunakan dalam faktur transaksi.`,
        confirmText: 'Ya, Hapus',
        variant: 'danger',
    });

    if (ok) {
        router.delete(`/master/tax-rates/${item.id}`, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <AppLayout title="Tarif Pajak">
        <Head title="Tarif Pajak" />

        <div class="space-y-6">
            <PageHeader
                title="Tarif Pajak"
                description="Kelola skema persentase tarif pajak pertambahan nilai dan pemotongan."
            >
                <template #actions>
                    <Can permission="tax_rates.create">
                        <Link href="/master/tax-rates/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Tambah Tarif
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
                <FilterBar
                    :search="search"
                    search-placeholder="Cari kode atau nama tarif pajak..."
                    @update:search="setSearch"
                    @reset="setSearch('')"
                />

                <DataTable
                    :columns="columns"
                    :data="items.data"
                    :sort-key="sortKey"
                    :sort-direction="sortDirection"
                    empty-title="Belum ada data tarif pajak"
                    empty-description="Mulai tambahkan tarif pajak baru dengan menekan tombol Tambah Tarif."
                    @sort="setSort($event.key, $event.direction)"
                >
                    <template #cell(rate)="{ row }">
                        <span class="font-mono text-sm text-slate-800 dark:text-slate-200">
                            {{ Number(row.rate).toFixed(2) }}%
                        </span>
                    </template>

                    <template #cell(is_active)="{ row }">
                        <Badge :variant="row.is_active ? 'success' : 'neutral'" size="sm">
                            {{ row.is_active ? 'Aktif' : 'Non-aktif' }}
                        </Badge>
                    </template>

                    <template #cell(actions)="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <Can permission="tax_rates.update">
                                <Link :href="`/master/tax-rates/${row.id}/edit`">
                                    <IconButton variant="ghost" size="sm" aria-label="Ubah Tarif Pajak">
                                        <Edit class="w-4 h-4 text-slate-600 dark:text-slate-400" aria-hidden="true" />
                                    </IconButton>
                                </Link>
                            </Can>

                            <Can permission="tax_rates.delete">
                                <IconButton
                                    variant="ghost"
                                    size="sm"
                                    aria-label="Hapus Tarif Pajak"
                                    @click="handleDelete(row)"
                                >
                                    <Trash2 class="w-4 h-4 text-rose-600 dark:text-rose-400" aria-hidden="true" />
                                </IconButton>
                            </Can>
                        </div>
                    </template>
                </DataTable>

                <Pagination
                    :current-page="items.meta.page"
                    :total-pages="items.meta.last_page"
                    :total-items="items.meta.total"
                    :per-page="items.meta.per_page"
                    @page-change="setPage"
                    @change-per-page="setPerPage"
                />
            </div>
        </div>
    </AppLayout>
</template>
