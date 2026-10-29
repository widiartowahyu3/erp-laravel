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

const config = masterDataModules.paymentTerms;
const { search, sortKey, sortDirection, setSearch, setSort, setPage, setPerPage } = useListQuery('/master/payment-terms', {
    defaults: {
        search: props.items.query?.search || '',
        sort: props.items.query?.sort || 'days',
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
        title: 'Hapus Syarat Pembayaran',
        message: `Apakah Anda yakin ingin menghapus termin "${item.name}" (${item.code})? Tindakan ini tidak dapat dibatalkan jika termin sudah terhubung ke data mitra atau transaksi.`,
        confirmText: 'Ya, Hapus',
        variant: 'danger',
    });

    if (ok) {
        router.delete(`/master/payment-terms/${item.id}`, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <AppLayout title="Syarat Pembayaran">
        <Head title="Syarat Pembayaran" />

        <div class="space-y-6">
            <PageHeader
                title="Syarat Pembayaran"
                description="Kelola termin pembayaran (tempo, COD) untuk pembelian dan penjualan."
            >
                <template #actions>
                    <Can permission="payment_terms.create">
                        <Link href="/master/payment-terms/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Tambah Termin
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
                <FilterBar
                    :search="search"
                    search-placeholder="Cari kode atau nama termin..."
                    @update:search="setSearch"
                    @reset="setSearch('')"
                />

                <DataTable
                    :columns="columns"
                    :data="items.data"
                    :sort-key="sortKey"
                    :sort-direction="sortDirection"
                    empty-title="Belum ada data termin pembayaran"
                    empty-description="Mulai tambahkan syarat pembayaran baru dengan menekan tombol Tambah Termin."
                    @sort="setSort($event.key, $event.direction)"
                >
                    <template #cell(days)="{ row }">
                        <span class="font-mono text-sm text-slate-800 dark:text-slate-200">
                            {{ row.days === 0 ? '0 Hari (COD/Tunai)' : `${row.days} Hari` }}
                        </span>
                    </template>

                    <template #cell(actions)="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <Can permission="payment_terms.update">
                                <Link :href="`/master/payment-terms/${row.id}/edit`">
                                    <IconButton variant="ghost" size="sm" aria-label="Ubah Syarat Pembayaran">
                                        <Edit class="w-4 h-4 text-slate-600 dark:text-slate-400" aria-hidden="true" />
                                    </IconButton>
                                </Link>
                            </Can>

                            <Can permission="payment_terms.delete">
                                <IconButton
                                    variant="ghost"
                                    size="sm"
                                    aria-label="Hapus Syarat Pembayaran"
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
