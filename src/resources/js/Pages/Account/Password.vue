<script setup>
import { useForm, Head, usePage } from '@inertiajs/vue3';
import { KeyRound, Shield } from 'lucide-vue-next';
import AppLayout from '../../Layouts/AppLayout.vue';
import Card from '../../Components/ui/Card.vue';
import FormField from '../../Components/ui/FormField.vue';
import PasswordInput from '../../Components/ui/PasswordInput.vue';
import Button from '../../Components/ui/Button.vue';
import Alert from '../../Components/ui/Alert.vue';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const page = usePage();

function submit() {
    form.put('/account/password', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <AppLayout title="Ubah Kata Sandi">
        <Head title="Ubah Kata Sandi" />

        <div class="max-w-2xl mx-auto space-y-6">
            <!-- Header Section -->
            <div class="border-b border-slate-200 dark:border-slate-800 pb-4">
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">
                    Ubah Kata Sandi
                </h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Perbarui kata sandi akun Anda secara berkala untuk menjaga keamanan sistem.
                </p>
            </div>

            <!-- Status Banner (Success Flash) -->
            <Alert
                v-if="page.props.flash?.status || form.recentlySuccessful"
                variant="success"
            >
                {{ page.props.flash?.status || 'Kata sandi berhasil diperbarui.' }}
            </Alert>

            <!-- Rate Limit Alert Banner -->
            <Alert
                v-if="(form.errors.current_password && form.errors.current_password.includes('Terlalu banyak percobaan')) || (form.errors.password && form.errors.password.includes('Terlalu banyak percobaan'))"
                variant="danger"
            >
                {{ form.errors.current_password || form.errors.password }}
            </Alert>

            <!-- Form Card -->
            <Card padding="lg">
                <form class="space-y-5" novalidate @submit.prevent="submit">
                    <!-- Hidden username input for browser password autofill heuristic compliance -->
                    <input
                        type="text"
                        name="username"
                        :value="page.props.auth?.user?.email || ''"
                        autocomplete="username"
                        class="sr-only"
                        aria-hidden="true"
                        tabindex="-1"
                        readonly
                    />

                    <!-- Field: Kata Sandi Saat Ini -->
                    <FormField
                        id="current-password-field"
                        label="Kata Sandi Saat Ini"
                        :error="form.errors.current_password && !form.errors.current_password.includes('Terlalu banyak percobaan') ? form.errors.current_password : undefined"
                        required
                    >
                        <template #default="{ id, invalid, describedBy }">
                            <PasswordInput
                                :id="id"
                                v-model="form.current_password"
                                name="current_password"
                                autocomplete="current-password"
                                placeholder="••••••••••••"
                                required
                                :invalid="invalid"
                                :described-by="describedBy"
                            />
                        </template>
                    </FormField>

                    <!-- Field: Kata Sandi Baru -->
                    <FormField
                        id="new-password-field"
                        label="Kata Sandi Baru"
                        :error="form.errors.password && !form.errors.password.includes('Terlalu banyak percobaan') ? form.errors.password : undefined"
                        required
                    >
                        <template #default="{ id, invalid, describedBy }">
                            <PasswordInput
                                :id="id"
                                v-model="form.password"
                                name="password"
                                autocomplete="new-password"
                                placeholder="••••••••••••"
                                required
                                :invalid="invalid"
                                :described-by="describedBy"
                            />
                        </template>
                    </FormField>

                    <!-- Field: Konfirmasi Kata Sandi Baru -->
                    <FormField
                        id="new-password-confirmation-field"
                        label="Konfirmasi Kata Sandi Baru"
                        :error="form.errors.password_confirmation"
                        required
                    >
                        <template #default="{ id, invalid, describedBy }">
                            <PasswordInput
                                :id="id"
                                v-model="form.password_confirmation"
                                name="password_confirmation"
                                autocomplete="new-password"
                                placeholder="••••••••••••"
                                required
                                :invalid="invalid"
                                :described-by="describedBy"
                            />
                        </template>
                    </FormField>

                    <!-- Password Policy Box -->
                    <div class="rounded-lg bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700/80 p-4 text-xs text-slate-600 dark:text-slate-300 space-y-2">
                        <div class="flex items-center gap-1.5 font-semibold text-slate-800 dark:text-slate-200">
                            <Shield class="w-4 h-4 text-indigo-600 dark:text-indigo-400" aria-hidden="true" />
                            <span>Persyaratan Kata Sandi:</span>
                        </div>
                        <ul class="list-disc list-inside space-y-1 text-slate-600 dark:text-slate-300 pl-1">
                            <li>Minimal 12 karakter dan maksimal 128 karakter.</li>
                            <li>Mengandung setidaknya satu huruf besar (A-Z).</li>
                            <li>Mengandung setidaknya satu huruf kecil (a-z).</li>
                            <li>Mengandung setidaknya satu angka (0-9).</li>
                            <li>Tidak boleh memuat bagian nama pengguna/email Anda.</li>
                            <li>Harus berbeda dari kata sandi saat ini.</li>
                        </ul>
                    </div>

                    <!-- Action Buttons -->
                    <div class="pt-3 flex items-center justify-end gap-3 border-t border-slate-100 dark:border-slate-800">
                        <Button
                            type="submit"
                            variant="primary"
                            :loading="form.processing"
                        >
                            <template #icon>
                                <KeyRound class="w-4 h-4" aria-hidden="true" />
                            </template>
                            Simpan Kata Sandi
                        </Button>
                    </div>
                </form>
            </Card>
        </div>
    </AppLayout>
</template>
