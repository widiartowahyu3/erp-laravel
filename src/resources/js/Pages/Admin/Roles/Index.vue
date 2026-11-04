<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Eye, Edit, Copy, Trash2 } from 'lucide-vue-next';
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
import Alert from '@/Components/ui/Alert.vue';
import { useListQuery } from '@/composables/useListQuery';
import { useConfirm } from '@/composables/useConfirm';
import { useSubmitLock } from '@/composables/useSubmitLock';

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const pageError = computed(() => page.props.errors?.form || page.props.errors?.permissions || '');

const { search, filters, setSearch, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/admin/roles', {
    defaults: {
        search: props.items.query?.search || '',
        perPage: props.items.meta?.per_page || 25,
        filters: {
            is_system: props.items.query?.filter?.is_system ?? '',
        },
    },
});

const { confirm } = useConfirm();
const { busy, submit } = useSubmitLock();

const typeOptions = [
    { value: '', label: 'Semua Jenis' },
    { value: '1', label: 'Bawaan' },
    { value: '0', label: 'Kustom' },
];

const hasActiveFilters = computed(() => Boolean(search.value || filters.value.is_system !== ''));

const columns = [
    { key: 'display_name', label: 'Nama Peran' },
    { key: 'type', label: 'Jenis' },
    { key: 'permissions', label: 'Jumlah Izin' },
    { key: 'users', label: 'Jumlah Pengguna' },
    { key: 'actions', label: 'Aksi', align: 'right' },
];

function deleteBlockReason(role) {
    return role.usersCount > 0 ? `Dipakai oleh ${role.usersCount} pengguna` : '';
}

function duplicate(role) {
    submit((options) => {
        router.post(`/admin/roles/${role.id}/duplicate`, {}, { ...options, preserveScroll: true });
    });
}

async function handleDelete(role) {
    const ok = await confirm({
        title: 'Hapus Peran',
        message: `Apakah Anda yakin ingin menghapus peran "${role.display_name}"?`,
        confirmText: 'Ya, Hapus',
        variant: 'danger',
    });

    if (ok) {
        submit((options) => {
            router.delete(`/admin/roles/${role.id}`, { ...options, preserveScroll: true });
        });
    }
}
</script>

<template>
    <AppLayout title="Peran & Hak Akses">
        <Head title="Peran & Hak Akses" />

        <div class="space-y-6">
            <PageHeader title="Peran & Hak Akses" description="Kelola peran dan izin yang diberikan kepada pengguna.">
                <template #actions>
                    <Can permission="roles.create">
                        <Link href="/admin/roles/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Tambah Peran
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <Alert v-if="pageError" variant="danger">{{ pageError }}</Alert>

            <FilterBar
                :search="search"
                search-placeholder="Cari nama peran..."
                :has-active-filters="hasActiveFilters"
                @update:search="setSearch"
                @reset="resetFilters"
            >
                <div class="w-full sm:w-44">
                    <Select
                        :model-value="filters.is_system"
                        :options="typeOptions"
                        size="sm"
                        aria-label="Filter berdasarkan jenis peran"
                        @update:model-value="setFilter('is_system', $event)"
                    />
                </div>
            </FilterBar>

            <DataTable
                :columns="columns"
                :data="items.data"
                empty-title="Belum ada peran"
                empty-description="Belum ada peran yang cocok untuk ditampilkan."
            >
                <template #cell(display_name)="{ row }">
                    <Link
                        :href="`/admin/roles/${row.id}`"
                        class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                    >
                        {{ row.display_name }}
                    </Link>
                    <p v-if="row.description" class="text-xs text-slate-500 dark:text-slate-400">{{ row.description }}</p>
                </template>

                <template #cell(type)="{ row }">
                    <Badge :variant="row.isSystem ? 'info' : 'neutral'">
                        {{ row.isSystem ? 'Bawaan' : 'Kustom' }}
                    </Badge>
                </template>

                <template #cell(permissions)="{ row }">
                    {{ row.permissionsCount }}
                </template>

                <template #cell(users)="{ row }">
                    {{ row.usersCount ?? 'Tidak ditampilkan' }}
                </template>

                <template #cell(actions)="{ row }">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="`/admin/roles/${row.id}`">
                            <IconButton variant="ghost" size="sm" title="Lihat Peran" :aria-label="`Lihat peran ${row.display_name}`">
                                <Eye class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                            </IconButton>
                        </Link>
                        <Can permission="roles.create">
                            <IconButton
                                variant="ghost"
                                size="sm"
                                title="Duplikat Peran"
                                :aria-label="`Duplikat peran ${row.display_name}`"
                                :disabled="busy"
                                @click="duplicate(row)"
                            >
                                <Copy class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                            </IconButton>
                        </Can>
                        <template v-if="!row.isSystem">
                            <Can permission="roles.update">
                                <Link :href="`/admin/roles/${row.id}/edit`">
                                    <IconButton variant="ghost" size="sm" title="Ubah Peran" :aria-label="`Ubah peran ${row.display_name}`">
                                        <Edit class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                                    </IconButton>
                                </Link>
                            </Can>
                            <Can permission="roles.delete">
                                <IconButton
                                    variant="ghost"
                                    size="sm"
                                    :title="deleteBlockReason(row) || 'Hapus Peran'"
                                    :aria-label="`Hapus peran ${row.display_name}`"
                                    :disabled="busy || deleteBlockReason(row) !== ''"
                                    @click="handleDelete(row)"
                                >
                                    <Trash2 class="w-4 h-4 text-rose-600 dark:text-rose-400" />
                                </IconButton>
                            </Can>
                        </template>
                    </div>
                </template>
            </DataTable>

            <Pagination :meta="items.meta" @change-page="setPage" @change-per-page="setPerPage" />
        </div>
    </AppLayout>
</template>
