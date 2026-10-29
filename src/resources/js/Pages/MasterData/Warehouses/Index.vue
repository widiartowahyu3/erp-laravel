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

const config = masterDataModules.warehouses;
const { search, sortKey, sortDirection, setSearch, setSort, setPage, setPerPage } = useListQuery('/master/warehouses', {
    defaults: {
        search: props.items.query?.search || '',
        sort: props.items.query?.sort || 'name',
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
        title: 'Hapus Master Gudang',
        message: `Apakah Anda yakin ingin menghapus gudang "${item.name}" (${item.code})? Tindakan ini tidak dapat dibatalkan jika gudang memiliki saldo stok atau mutasi aktif.`,
        confirmText: 'Ya, Hapus',
        variant: 'danger',
    });

    if (ok) {
        router.delete(`/master/warehouses/${item.id}`, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <AppLayout title="Master Gudang">
        <Head title="Master Gudang" />

        <div class="space-y-6">
            <PageHeader
                title="Master Gudang"
                description="Kelola data lokasi fasilitas gudang penyimpanan barang dan distribusi."
            >
                <template #actions>
                    <Can permission="warehouses.create">
                        <Link href="/master/warehouses/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Tambah Gudang
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
                <FilterBar
                    :search="search"
                    search-placeholder="Cari kode, nama, atau alamat gudang..."
                    @update:search="setSearch"
                    @reset="setSearch('')"
                />

                <DataTable
                    :columns="columns"
                    :data="items.data"
                    :sort-key="sortKey"
                    :sort-direction="sortDirection"
                    empty-title="Belum ada data gudang"
                    empty-description="Mulai tambahkan gudang penyimpanan baru dengan menekan tombol Tambah Gudang."
                    @sort="setSort($event.key, $event.direction)"
                >
                    <template #cell(is_active)="{ row }">
                        <Badge :variant="row.is_active ? 'success' : 'neutral'" size="sm">
                            {{ row.is_active ? 'Aktif' : 'Non-aktif' }}
                        </Badge>
                    </template>

                    <template #cell(actions)="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <Can permission="warehouses.update">
                                <Link :href="`/master/warehouses/${row.id}/edit`">
                                    <IconButton variant="ghost" size="sm" aria-label="Ubah Gudang">
                                        <Edit class="w-4 h-4 text-slate-600 dark:text-slate-400" aria-hidden="true" />
                                    </IconButton>
                                </Link>
                            </Can>

                            <Can permission="warehouses.delete">
                                <IconButton
                                    variant="ghost"
                                    size="sm"
                                    aria-label="Hapus Gudang"
                                    @click="handleDelete(row)"
                                >
                                    <Trash2 class="w-4 h-4 text-rose-600 dark:text-rose-400" aria-hidden="true" />
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
        </div>
    </AppLayout>
</template>
