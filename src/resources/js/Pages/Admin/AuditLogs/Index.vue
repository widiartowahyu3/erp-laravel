<script setup>
import { computed, onUnmounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Eye, RotateCcw } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import DataTable from '@/Components/ui/DataTable.vue';
import Pagination from '@/Components/ui/Pagination.vue';
import Button from '@/Components/ui/Button.vue';
import IconButton from '@/Components/ui/IconButton.vue';
import Select from '@/Components/ui/Select.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Modal from '@/Components/ui/Modal.vue';
import { useListQuery } from '@/composables/useListQuery';

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
    users: {
        type: Array,
        default: () => [],
    },
});

// Only events the application really writes. Anything else is shown as it is stored.
const eventLabels = {
    'user.created': 'Pengguna dibuat',
    'user.updated': 'Pengguna diubah',
    'user.roles_changed': 'Peran pengguna diubah',
    'user.activated': 'Pengguna diaktifkan',
    'user.deactivated': 'Pengguna dinonaktifkan',
    'password.reset': 'Kata sandi direset',
    'password.changed': 'Kata sandi diganti',
    'role.created': 'Peran dibuat',
    'role.updated': 'Peran diubah',
    'role.permissions_changed': 'Izin peran diubah',
    'role.deleted': 'Peran dihapus',
    'login.success': 'Berhasil masuk',
    'login.failed': 'Gagal masuk',
    'login.throttled': 'Percobaan masuk dibatasi',
    logout: 'Keluar',
    'session.expired': 'Sesi berakhir',
};

const typeLabels = {
    'App\\Models\\User': 'Pengguna',
    'App\\Models\\Role': 'Peran',
    auth: 'Autentikasi',
};

function eventLabel(event) {
    return eventLabels[event] ?? null;
}

function typeLabel(type) {
    return typeLabels[type] ?? type;
}

function asText(values) {
    return values === null || values === undefined ? '-' : JSON.stringify(values, null, 2);
}

const { filters, setFilter, resetFilters, setPage, setPerPage } = useListQuery('/admin/audit-logs', {
    defaults: {
        perPage: props.items.meta?.per_page || 25,
        filters: {
            date_from: props.items.query?.filter?.date_from ?? '',
            date_to: props.items.query?.filter?.date_to ?? '',
            user_id: props.items.query?.filter?.user_id ?? '',
            event: props.items.query?.filter?.event ?? '',
            auditable_type: props.items.query?.filter?.auditable_type ?? '',
        },
    },
});

const eventText = ref(filters.value.event ?? '');
const typeText = ref(filters.value.auditable_type ?? '');
let textTimer = null;

function scheduleTextFilter(key, value) {
    clearTimeout(textTimer);
    textTimer = setTimeout(() => setFilter(key, value), 300);
}

function onEventInput(value) {
    eventText.value = value;
    scheduleTextFilter('event', value);
}

function onTypeInput(value) {
    typeText.value = value;
    scheduleTextFilter('auditable_type', value);
}

function reset() {
    clearTimeout(textTimer);
    eventText.value = '';
    typeText.value = '';
    resetFilters();
}

onUnmounted(() => clearTimeout(textTimer));

const userOptions = computed(() => [
    { value: '', label: 'Semua Pengguna' },
    ...props.users.map((user) => ({ value: String(user.id), label: user.name })),
]);

const hasActiveFilters = computed(() => Boolean(
    filters.value.date_from
    || filters.value.date_to
    || filters.value.user_id
    || eventText.value
    || typeText.value
));

const columns = [
    { key: 'created_at', label: 'Waktu', class: 'whitespace-nowrap text-xs' },
    { key: 'user', label: 'Pengguna' },
    { key: 'event', label: 'Event' },
    { key: 'object', label: 'Objek' },
    { key: 'ip_address', label: 'Alamat IP', class: 'whitespace-nowrap text-xs' },
    { key: 'actions', label: 'Detail', align: 'right' },
];

const selected = ref(null);
</script>

<template>
    <AppLayout title="Log Audit">
        <Head title="Log Audit" />

        <div class="space-y-6">
            <PageHeader title="Log Audit" description="Catatan aktivitas sistem. Hanya dapat dibaca." />

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-4 bg-slate-50/50 dark:bg-slate-800/30 border-b border-slate-200 dark:border-slate-800">
                <div class="flex flex-wrap items-center gap-2.5">
                    <div class="w-full sm:w-40">
                        <TextInput
                            type="date"
                            :model-value="filters.date_from"
                            size="sm"
                            aria-label="Dari tanggal"
                            @update:model-value="setFilter('date_from', $event)"
                        />
                    </div>
                    <div class="w-full sm:w-40">
                        <TextInput
                            type="date"
                            :model-value="filters.date_to"
                            size="sm"
                            aria-label="Sampai tanggal"
                            @update:model-value="setFilter('date_to', $event)"
                        />
                    </div>
                    <div class="w-full sm:w-52">
                        <Select
                            :model-value="filters.user_id"
                            :options="userOptions"
                            size="sm"
                            aria-label="Filter berdasarkan pengguna"
                            @update:model-value="setFilter('user_id', $event)"
                        />
                    </div>
                    <div class="w-full sm:w-44">
                        <TextInput
                            :model-value="eventText"
                            size="sm"
                            placeholder="Awalan event, mis. role."
                            aria-label="Filter berdasarkan awalan event"
                            @update:model-value="onEventInput"
                        />
                    </div>
                    <div class="w-full sm:w-44">
                        <TextInput
                            :model-value="typeText"
                            size="sm"
                            placeholder="Tipe objek, mis. Role"
                            aria-label="Filter berdasarkan tipe objek"
                            @update:model-value="onTypeInput"
                        />
                    </div>
                    <Button v-if="hasActiveFilters" variant="ghost" size="sm" @click="reset">
                        <RotateCcw class="w-3.5 h-3.5 mr-1.5" aria-hidden="true" />
                        <span>Reset</span>
                    </Button>
                </div>
            </div>

            <DataTable
                :columns="columns"
                :data="items.data"
                empty-title="Belum ada catatan"
                empty-description="Belum ada catatan yang cocok untuk ditampilkan."
            >
                <template #cell(user)="{ row }">
                    {{ row.user?.name ?? 'Sistem atau tamu' }}
                </template>

                <template #cell(event)="{ row }">
                    <span class="text-slate-800 dark:text-slate-200">{{ eventLabel(row.event) ?? row.event }}</span>
                    <span v-if="eventLabel(row.event)" class="block font-mono text-xs text-slate-500 dark:text-slate-400">{{ row.event }}</span>
                </template>

                <template #cell(object)="{ row }">
                    {{ typeLabel(row.auditable_type) }} #{{ row.auditable_id }}
                </template>

                <template #cell(ip_address)="{ row }">
                    {{ row.ip_address ?? '-' }}
                </template>

                <template #cell(actions)="{ row }">
                    <IconButton
                        variant="ghost"
                        size="sm"
                        title="Lihat Detail"
                        :aria-label="`Lihat detail catatan ${row.id}`"
                        @click="selected = row"
                    >
                        <Eye class="w-4 h-4 text-slate-600 dark:text-slate-300" />
                    </IconButton>
                </template>
            </DataTable>

            <Pagination :meta="items.meta" @change-page="setPage" @change-per-page="setPerPage" />
        </div>

        <Modal
            :show="selected !== null"
            title="Detail Catatan"
            :description="selected ? `${selected.event} pada ${selected.created_at}` : ''"
            max-width="2xl"
            @close="selected = null"
        >
            <div v-if="selected" class="space-y-4 text-sm">
                <div>
                    <p class="mb-1 text-xs font-semibold text-slate-600 dark:text-slate-300">Nilai Lama</p>
                    <pre data-testid="old-values" class="max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-200">{{ asText(selected.old_values) }}</pre>
                </div>
                <div>
                    <p class="mb-1 text-xs font-semibold text-slate-600 dark:text-slate-300">Nilai Baru</p>
                    <pre data-testid="new-values" class="max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-200">{{ asText(selected.new_values) }}</pre>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
