<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Eye, Edit } from 'lucide-vue-next';
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

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
    roles: {
        type: Array,
        default: () => [],
    },
});

const { search, filters, setSearch, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/admin/users', {
    defaults: {
        search: props.items.query?.search || '',
        perPage: props.items.meta?.per_page || 25,
        filters: {
            is_active: props.items.query?.filter?.is_active ?? '',
            role_id: props.items.query?.filter?.role_id ?? '',
        },
    },
});

const statusOptions = [
    { value: '', label: 'Semua Status' },
    { value: '1', label: 'Aktif' },
    { value: '0', label: 'Nonaktif' },
];

const roleOptions = computed(() => [
    { value: '', label: 'Semua Peran' },
    ...props.roles.map((role) => ({ value: String(role.id), label: role.display_name })),
]);

const hasActiveFilters = computed(() => Boolean(search.value || filters.value.is_active !== '' || filters.value.role_id));

const columns = [
    { key: 'name', label: 'Nama' },
    { key: 'email', label: 'Email' },
    { key: 'roles', label: 'Peran' },
    { key: 'is_active', label: 'Status' },
    { key: 'last_login_at', label: 'Login Terakhir' },
    { key: 'actions', label: 'Aksi', align: 'right' },
];
</script>

<template>
    <AppLayout title="Pengguna">
        <Head title="Pengguna" />

        <div class="space-y-6">
            <PageHeader title="Pengguna" description="Kelola akun, peran, dan status aktif pengguna.">
                <template #actions>
                    <Can permission="users.create">
                        <Link href="/admin/users/create">
                            <Button variant="primary">
                                <Plus class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Tambah Pengguna
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <FilterBar
                :search="search"
                search-placeholder="Cari nama atau email..."
                :has-active-filters="hasActiveFilters"
                @update:search="setSearch"
                @reset="resetFilters"
            >
                <div class="w-full sm:w-44">
                    <Select
                        :model-value="filters.is_active"
                        :options="statusOptions"
                        size="sm"
                        aria-label="Filter berdasarkan status"
                        @update:model-value="setFilter('is_active', $event)"
                    />
                </div>

                <div class="w-full sm:w-56">
                    <Select
                        :model-value="filters.role_id"
                        :options="roleOptions"
                        size="sm"
                        aria-label="Filter berdasarkan peran"
                        @update:model-value="setFilter('role_id', $event)"
                    />
                </div>
            </FilterBar>

            <DataTable
                :columns="columns"
                :data="items.data"
                empty-title="Belum ada pengguna"
                empty-description="Belum ada pengguna yang cocok untuk ditampilkan."
            >
                <template #cell(name)="{ row }">
                    <Link
                        :href="`/admin/users/${row.id}`"
                        class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                    >
                        {{ row.name }}
                    </Link>
                </template>

                <template #cell(roles)="{ row }">
                    <div class="flex flex-wrap gap-1">
                        <Badge v-for="role in row.roles" :key="role.id" variant="neutral">
                            {{ role.display_name }}
                        </Badge>
                        <span v-if="row.roles.length === 0" class="text-xs text-slate-500 dark:text-slate-400">Tanpa peran</span>
                    </div>
                </template>

                <template #cell(is_active)="{ row }">
                    <Badge :variant="row.is_active ? 'success' : 'neutral'">
                        {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
                    </Badge>
                </template>

                <template #cell(last_login_at)="{ row }">
                    <span class="text-xs text-slate-600 dark:text-slate-300">{{ row.last_login_at || 'Belum pernah' }}</span>
                </template>

                <template #cell(actions)="{ row }">
                    <div class="flex items-center justify-end gap-1">
                        <Link :href="`/admin/users/${row.id}`">
                            <IconButton variant="ghost" size="sm" title="Lihat Pengguna" :aria-label="`Lihat pengguna ${row.name}`">
                                <Eye class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                            </IconButton>
                        </Link>
                        <Can permission="users.update">
                            <Link :href="`/admin/users/${row.id}/edit`">
                                <IconButton variant="ghost" size="sm" title="Ubah Pengguna" :aria-label="`Ubah pengguna ${row.name}`">
                                    <Edit class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                                </IconButton>
                            </Link>
                        </Can>
                    </div>
                </template>
            </DataTable>

            <Pagination :meta="items.meta" @change-page="setPage" @change-per-page="setPerPage" />
        </div>
    </AppLayout>
</template>
