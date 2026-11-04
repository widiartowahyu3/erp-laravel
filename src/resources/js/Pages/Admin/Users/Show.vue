<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Edit, KeyRound, UserCheck, UserX } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import Can from '@/Components/ui/Can.vue';
import Alert from '@/Components/ui/Alert.vue';
import Modal from '@/Components/ui/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import PasswordInput from '@/Components/ui/PasswordInput.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useSubmitLock } from '@/composables/useSubmitLock';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    isSelf: {
        type: Boolean,
        default: false,
    },
    isLastSuperAdmin: {
        type: Boolean,
        default: false,
    },
    canManage: {
        type: Boolean,
        default: true,
    },
});

const page = usePage();
const { confirm } = useConfirm();
const { busy, submit } = useSubmitLock();

const pageError = computed(() => page.props.errors?.form || page.props.errors?.is_active || '');

const breadcrumbs = computed(() => [
    { label: 'Pengguna', href: '/admin/users' },
    { label: props.item.name },
]);

const manageBlockReason = computed(() => (props.canManage ? '' : 'Akun ini memiliki izin yang melebihi izin Anda, sehingga tidak dapat Anda ubah.'));

const deactivateBlockReason = computed(() => {
    if (props.isSelf) {
        return 'Anda tidak dapat menonaktifkan akun sendiri.';
    }

    if (props.isLastSuperAdmin) {
        return 'Ini Super Administrator aktif yang terakhir, sehingga tidak dapat dinonaktifkan.';
    }

    return manageBlockReason.value;
});

const resetBlockReason = computed(() => {
    if (props.isSelf) {
        return 'Untuk mengganti kata sandi sendiri, gunakan halaman Akun.';
    }

    return manageBlockReason.value;
});

const statusBlockReason = computed(() => (props.item.is_active ? deactivateBlockReason.value : manageBlockReason.value));

async function toggleActive() {
    const nextActive = !props.item.is_active;
    const ok = await confirm({
        title: nextActive ? 'Aktifkan Pengguna' : 'Nonaktifkan Pengguna',
        message: nextActive
            ? `Aktifkan kembali akun "${props.item.name}"? Pengguna dapat masuk lagi.`
            : `Nonaktifkan akun "${props.item.name}"? Seluruh sesi aktif pengguna ini akan dicabut dan ia tidak dapat masuk.`,
        confirmText: nextActive ? 'Ya, Aktifkan' : 'Ya, Nonaktifkan',
        variant: nextActive ? 'primary' : 'danger',
    });

    if (ok) {
        submit((options) => {
            router.post(`/admin/users/${props.item.id}/active`, { is_active: nextActive }, { ...options, preserveScroll: true });
        });
    }
}

const showResetDialog = ref(false);
const resetForm = useForm({ password: '', password_confirmation: '' });
const { busy: resetBusy, submit: submitReset } = useSubmitLock();

function openResetDialog() {
    resetForm.reset();
    resetForm.clearErrors?.();
    showResetDialog.value = true;
}

function closeResetDialog() {
    showResetDialog.value = false;
    resetForm.reset();
}

function sendReset() {
    submitReset((options) => {
        resetForm.post(`/admin/users/${props.item.id}/reset-password`, {
            ...options,
            preserveScroll: true,
            onSuccess: closeResetDialog,
        });
    });
}
</script>

<template>
    <AppLayout :title="`Pengguna ${item.name}`">
        <Head :title="`Pengguna ${item.name}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader :title="item.name" description="Rincian akun, peran, dan status pengguna.">
                <template #actions>
                    <Can permission="users.update">
                        <Link v-if="canManage" :href="`/admin/users/${item.id}/edit`">
                            <Button variant="secondary" size="sm">
                                <Edit class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Ubah
                            </Button>
                        </Link>
                        <Button
                            variant="secondary"
                            size="sm"
                            :disabled="resetBlockReason !== '' || resetBusy"
                            :title="resetBlockReason"
                            @click="openResetDialog"
                        >
                            <KeyRound class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            Reset Kata Sandi
                        </Button>
                    </Can>
                    <Can permission="users.delete">
                        <Button
                            :variant="item.is_active ? 'danger' : 'primary'"
                            size="sm"
                            :loading="busy"
                            :disabled="statusBlockReason !== '' || busy"
                            :title="statusBlockReason"
                            @click="toggleActive"
                        >
                            <component :is="item.is_active ? UserX : UserCheck" class="w-4 h-4 mr-1.5" aria-hidden="true" />
                            {{ item.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                        </Button>
                    </Can>
                </template>
            </PageHeader>

            <Alert v-if="pageError" variant="danger">{{ pageError }}</Alert>

            <ul
                v-if="statusBlockReason || resetBlockReason"
                class="space-y-1 text-xs text-slate-600 dark:text-slate-300"
            >
                <li v-if="statusBlockReason">{{ statusBlockReason }}</li>
                <li v-if="resetBlockReason && resetBlockReason !== statusBlockReason">{{ resetBlockReason }}</li>
            </ul>

            <Card>
                <template #header>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Informasi Akun</h2>
                </template>

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Email</dt>
                        <dd class="text-slate-800 dark:text-slate-200">{{ item.email }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Status</dt>
                        <dd>
                            <Badge :variant="item.is_active ? 'success' : 'neutral'">
                                {{ item.is_active ? 'Aktif' : 'Nonaktif' }}
                            </Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Login Terakhir</dt>
                        <dd class="text-slate-800 dark:text-slate-200">{{ item.last_login_at || 'Belum pernah' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Peran</dt>
                        <dd class="flex flex-wrap gap-1">
                            <Badge v-for="role in item.roles" :key="role.id" variant="neutral">
                                {{ role.display_name }}
                            </Badge>
                            <span v-if="item.roles.length === 0" class="text-slate-800 dark:text-slate-200">Tanpa peran</span>
                        </dd>
                    </div>
                </dl>
            </Card>
        </div>

        <Modal
            :show="showResetDialog"
            title="Reset Kata Sandi"
            :description="`Tetapkan kata sandi baru untuk ${item.name}. Seluruh sesi aktif pengguna ini akan dicabut.`"
            @close="closeResetDialog"
        >
            <form class="space-y-4" novalidate @submit.prevent="sendReset">
                <Alert v-if="resetForm.errors.form" variant="danger">{{ resetForm.errors.form }}</Alert>

                <FormField
                    id="reset-password"
                    label="Kata Sandi Baru"
                    :error="resetForm.errors.password"
                    help="Minimal 12 karakter."
                    required
                >
                    <template #default="{ id, invalid, describedBy }">
                        <PasswordInput
                            :id="id"
                            v-model="resetForm.password"
                            name="password"
                            autocomplete="new-password"
                            required
                            :invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>

                <FormField id="reset-password-confirmation" label="Konfirmasi Kata Sandi" required>
                    <template #default="{ id, invalid, describedBy }">
                        <PasswordInput
                            :id="id"
                            v-model="resetForm.password_confirmation"
                            name="password_confirmation"
                            autocomplete="new-password"
                            required
                            :invalid="invalid"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <Button type="button" variant="secondary" @click="closeResetDialog">Batal</Button>
                    <Button
                        type="submit"
                        variant="danger"
                        :loading="resetForm.processing || resetBusy"
                        :disabled="resetForm.processing || resetBusy"
                    >
                        Reset Kata Sandi
                    </Button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
