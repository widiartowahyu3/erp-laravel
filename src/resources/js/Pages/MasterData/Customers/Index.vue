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

const config = masterDataModules.customers;
const { search, sortKey, sortDirection, setSearch, setSort, setPage, setPerPage } = useListQuery('/master/customers', {
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
        title: 'Hapus Master Pelanggan',
        message: `Apakah Anda yakin ingin menghapus pelanggan "${item.name}" (${item.code})? Tindakan ini tidak dapat dibatalkan jika pelanggan telah memiliki pesanan atau piutang aktif.`,
        confirmText: 'Ya, Hapus',
        variant: 'danger',
    });

    if (ok) {
        router.delete(`/master/customers/${item.id}`, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <AppLayout title="Master Pelanggan">
        <Head title="Master Pelanggan" />

        <div class="space-y-6">
            <PageHeader
                title="Master Pelanggan"
                description="Kelola mitra pembeli retail dan grosir beserta batas kredit transaksi."
            >
                <template #actions>
                    <Can permission="customers.create">
                        <Link href="/master/customers/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Tambah Pelanggan
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
                <FilterBar
                    :search="search"
                    search-placeholder="Cari kode, nama, kontak, email, atau telepon pelanggan..."
                    @update:search="setSearch"
                    @reset="setSearch('')"
                />

                <DataTable
                    :columns="columns"
                    :data="items.data"
                    :sort-key="sortKey"
                    :sort-direction="sortDirection"
                    empty-title="Belum ada data pelanggan"
                    empty-description="Mulai tambahkan mitra pelanggan baru dengan menekan tombol Tambah Pelanggan."
                    @sort="setSort($event.key, $event.direction)"
                >
                    <template #cell(credit_limit)="{ row }">
                        <Rupiah :value="row.credit_limit" />
                    </template>

                    <template #cell(payment_term_name)="{ row }">
                        <span v-if="row.payment_term" class="text-xs text-slate-700 dark:text-slate-300">
                            {{ row.payment_term.code }} ({{ row.payment_term.days }} hari)
                        </span>
                        <span v-else class="text-xs text-slate-400 dark:text-slate-500 italic">
                            - Standar / COD -
                        </span>
                    </template>

                    <template #cell(is_active)="{ row }">
                        <Badge :variant="row.is_active ? 'success' : 'neutral'" size="sm">
                            {{ row.is_active ? 'Aktif' : 'Non-aktif' }}
                        </Badge>
                    </template>

                    <template #cell(actions)="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <Can permission="customers.update">
                                <Link :href="`/master/customers/${row.id}/edit`">
                                    <IconButton variant="ghost" size="sm" aria-label="Ubah Pelanggan">
                                        <Edit class="w-4 h-4 text-slate-600 dark:text-slate-400" aria-hidden="true" />
                                    </IconButton>
                                </Link>
                            </Can>

                            <Can permission="customers.delete">
                                <IconButton
                                    variant="ghost"
                                    size="sm"
                                    aria-label="Hapus Pelanggan"
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
