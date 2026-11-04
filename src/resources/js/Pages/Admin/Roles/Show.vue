<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Copy, Edit, Trash2 } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import Can from '@/Components/ui/Can.vue';
import Alert from '@/Components/ui/Alert.vue';
import PermissionMatrix from '@/Components/admin/PermissionMatrix.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useSubmitLock } from '@/composables/useSubmitLock';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    selectedPermissions: {
        type: Array,
        default: () => [],
    },
    usersCount: {
        type: Number,
        default: null,
    },
    permissionMatrix: {
        type: Array,
        required: true,
    },
    grantablePermissions: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const pageError = computed(() => page.props.errors?.form || page.props.errors?.permissions || '');

const { confirm } = useConfirm();
const { busy, submit } = useSubmitLock();

const breadcrumbs = computed(() => [
    { label: 'Peran & Hak Akses', href: '/admin/roles' },
    { label: props.item.display_name },
]);

const deleteBlockReason = computed(() => (props.usersCount > 0 ? `Peran ini dipakai oleh ${props.usersCount} pengguna, sehingga tidak dapat dihapus.` : ''));

function duplicate() {
    submit((options) => {
        router.post(`/admin/roles/${props.item.id}/duplicate`, {}, { ...options, preserveScroll: true });
    });
}

async function handleDelete() {
    const ok = await confirm({
        title: 'Hapus Peran',
        message: `Apakah Anda yakin ingin menghapus peran "${props.item.display_name}"?`,
        confirmText: 'Ya, Hapus',
        variant: 'danger',
    });

    if (ok) {
        submit((options) => {
            router.delete(`/admin/roles/${props.item.id}`, { ...options, preserveScroll: true });
        });
    }
}
</script>

<template>
    <AppLayout :title="`Peran ${item.display_name}`">
        <Head :title="`Peran ${item.display_name}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader :title="item.display_name" :description="item.description || 'Rincian peran dan izinnya.'">
                <template #actions>
                    <Can permission="roles.create">
                        <Button variant="secondary" size="sm" :disabled="busy" @click="duplicate">
                            <Copy class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Duplikat
                        </Button>
                    </Can>
                    <template v-if="!item.isSystem">
                        <Can permission="roles.update">
                            <Link :href="`/admin/roles/${item.id}/edit`">
                                <Button variant="secondary" size="sm">
                                    <Edit class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                    Ubah
                                </Button>
                            </Link>
                        </Can>
                        <Can permission="roles.delete">
                            <Button
                                variant="danger"
                                size="sm"
                                :disabled="busy || deleteBlockReason !== ''"
                                :title="deleteBlockReason"
                                @click="handleDelete"
                            >
                                <Trash2 class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Hapus
                            </Button>
                        </Can>
                    </template>
                </template>
            </PageHeader>

            <Alert v-if="pageError" variant="danger">{{ pageError }}</Alert>

            <Alert v-if="item.isSystem" variant="info">
                Peran bawaan tidak dapat diubah atau dihapus. Gunakan Duplikat untuk membuat salinan yang dapat disesuaikan.
            </Alert>

            <p v-if="deleteBlockReason && !item.isSystem" class="text-xs text-slate-600 dark:text-slate-300">
                {{ deleteBlockReason }}
            </p>

            <Card>
                <template #header>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Informasi Peran</h2>
                </template>

                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Jenis</dt>
                        <dd>
                            <Badge :variant="item.isSystem ? 'info' : 'neutral'">
                                {{ item.isSystem ? 'Bawaan' : 'Kustom' }}
                            </Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Jumlah Pengguna</dt>
                        <dd class="text-slate-800 dark:text-slate-200">{{ usersCount ?? 'Tidak ditampilkan' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Jumlah Izin</dt>
                        <dd class="text-slate-800 dark:text-slate-200">{{ selectedPermissions.length }}</dd>
                    </div>
                </dl>
            </Card>

            <PermissionMatrix
                :model-value="selectedPermissions"
                :modules="permissionMatrix"
                :grantable="grantablePermissions"
                readonly
            />
        </div>
    </AppLayout>
</template>
