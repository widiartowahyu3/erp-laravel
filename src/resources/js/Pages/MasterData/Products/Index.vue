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
import Rupiah from '@/Components/ui/Rupiah.vue';
import Quantity from '@/Components/ui/Quantity.vue';
import Can from '@/Components/ui/Can.vue';
import { useListQuery } from '@/composables/useListQuery';
import { useConfirm } from '@/composables/useConfirm';
import { masterDataModules } from '@/config/master-data';

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
});

const config = masterDataModules.products;
const { search, sortKey, sortDirection, setSearch, setSort, setPage, setPerPage } = useListQuery('/master/products', {
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
        title: 'Hapus Master Produk',
        message: `Apakah Anda yakin ingin menghapus produk "${item.name}" (${item.sku})? Tindakan ini tidak dapat dibatalkan jika produk memiliki saldo stok atau riwayat pesanan.`,
        confirmText: 'Ya, Hapus',
        variant: 'danger',
    });

    if (ok) {
        router.delete(`/master/products/${item.id}`, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <AppLayout title="Master Produk">
        <Head title="Master Produk" />

        <div class="space-y-6">
            <PageHeader
                title="Master Produk"
                description="Kelola katalog produk barang komersial, harga jual, dan aturan stok persediaan."
            >
                <template #actions>
                    <Can permission="products.create">
                        <Link href="/master/products/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Tambah Produk
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
                <FilterBar
                    :search="search"
                    search-placeholder="Cari SKU, nama produk, atau barcode..."
                    @update:search="setSearch"
                    @reset="setSearch('')"
                />

                <DataTable
                    :columns="columns"
                    :data="items.data"
                    :sort-key="sortKey"
                    :sort-direction="sortDirection"
                    empty-title="Belum ada data produk"
                    empty-description="Mulai tambahkan produk dagang baru dengan menekan tombol Tambah Produk."
                    @sort="setSort($event.key, $event.direction)"
                >
                    <template #cell(category_name)="{ row }">
                        <span v-if="row.category" class="text-xs text-slate-700 dark:text-slate-300">
                            {{ row.category.name }}
                        </span>
                        <span v-else class="text-xs text-slate-400 dark:text-slate-500 italic">-</span>
                    </template>

                    <template #cell(base_unit_name)="{ row }">
                        <span v-if="row.base_unit" class="text-xs font-mono text-slate-700 dark:text-slate-300">
                            {{ row.base_unit.code }}
                        </span>
                        <span v-else class="text-xs text-slate-400 dark:text-slate-500 italic">-</span>
                    </template>

                    <template #cell(selling_price)="{ row }">
                        <Rupiah :value="row.selling_price" />
                    </template>

                    <template #cell(min_stock)="{ row }">
                        <Quantity :value="row.min_stock" :unit="row.base_unit?.code || ''" />
                    </template>

                    <template #cell(track_stock)="{ row }">
                        <Badge :variant="row.track_stock ? 'info' : 'neutral'" size="sm">
                            {{ row.track_stock ? 'Ya' : 'Tidak' }}
                        </Badge>
                    </template>

                    <template #cell(is_active)="{ row }">
                        <Badge :variant="row.is_active ? 'success' : 'neutral'" size="sm">
                            {{ row.is_active ? 'Aktif' : 'Non-aktif' }}
                        </Badge>
                    </template>

                    <template #cell(actions)="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <Can permission="products.update">
                                <Link :href="`/master/products/${row.id}/edit`">
                                    <IconButton variant="ghost" size="sm" aria-label="Ubah Produk">
                                        <Edit class="w-4 h-4 text-slate-600 dark:text-slate-400" aria-hidden="true" />
                                    </IconButton>
                                </Link>
                            </Can>

                            <Can permission="products.delete">
                                <IconButton
                                    variant="ghost"
                                    size="sm"
                                    aria-label="Hapus Produk"
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
