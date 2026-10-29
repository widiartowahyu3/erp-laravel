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

const config = masterDataModules.productCategories;
const { search, sortKey, sortDirection, setSearch, setSort, setPage, setPerPage } = useListQuery('/master/product-categories', {
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
        title: 'Hapus Kategori Produk',
        message: `Apakah Anda yakin ingin menghapus kategori "${item.name}" (${item.code})? Tindakan ini tidak dapat dibatalkan jika kategori memiliki sub-kategori atau produk terkait.`,
        confirmText: 'Ya, Hapus',
        variant: 'danger',
    });

    if (ok) {
        router.delete(`/master/product-categories/${item.id}`, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <AppLayout title="Kategori Produk">
        <Head title="Kategori Produk" />

        <div class="space-y-6">
            <PageHeader
                title="Kategori Produk"
                description="Kelola hierarki dan pengelompokan kategori produk barang dagang."
            >
                <template #actions>
                    <Can permission="product_categories.create">
                        <Link href="/master/product-categories/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Tambah Kategori
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
                <FilterBar
                    :search="search"
                    search-placeholder="Cari kode atau nama kategori..."
                    @update:search="setSearch"
                    @reset="setSearch('')"
                />

                <DataTable
                    :columns="columns"
                    :data="items.data"
                    :sort-key="sortKey"
                    :sort-direction="sortDirection"
                    empty-title="Belum ada data kategori"
                    empty-description="Mulai tambahkan kategori produk baru dengan menekan tombol Tambah Kategori."
                    @sort="setSort($event.key, $event.direction)"
                >
                    <template #cell(parent_name)="{ row }">
                        <span v-if="row.parent" class="inline-flex items-center text-xs font-medium text-slate-700 dark:text-slate-300">
                            {{ row.parent.name }} ({{ row.parent.code }})
                        </span>
                        <span v-else class="text-xs text-slate-400 dark:text-slate-500 italic">
                            - Kategori Utama -
                        </span>
                    </template>

                    <template #cell(actions)="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <Can permission="product_categories.update">
                                <Link :href="`/master/product-categories/${row.id}/edit`">
                                    <IconButton variant="ghost" size="sm" aria-label="Ubah Kategori">
                                        <Edit class="w-4 h-4 text-slate-600 dark:text-slate-400" aria-hidden="true" />
                                    </IconButton>
                                </Link>
                            </Can>

                            <Can permission="product_categories.delete">
                                <IconButton
                                    variant="ghost"
                                    size="sm"
                                    aria-label="Hapus Kategori"
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
