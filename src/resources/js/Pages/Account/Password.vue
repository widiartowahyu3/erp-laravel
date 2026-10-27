<script setup>
import { ref } from 'vue';
import { useForm, Head, usePage } from '@inertiajs/vue3';
import {
    Eye,
    EyeOff,
    KeyRound,
    Loader2,
    CheckCircle2,
    AlertCircle,
    Shield,
} from 'lucide-vue-next';
import AppLayout from '../../Layouts/AppLayout.vue';

const showCurrentPassword = ref(false);
const showNewPassword = ref(false);
const showConfirmPassword = ref(false);

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
            <div class="border-b border-slate-200 pb-4">
                <h1 class="text-2xl font-bold text-slate-900 tracking-tight">
                    Ubah Kata Sandi
                </h1>
                <p class="mt-1 text-sm text-slate-500">
                    Perbarui kata sandi akun Anda secara berkala untuk menjaga keamanan sistem.
                </p>
            </div>

            <!-- Status Banner (Success Flash) -->
            <div
                v-if="page.props.flash?.status || form.recentlySuccessful"
                class="p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 flex items-start gap-3"
                role="status"
            >
                <CheckCircle2 class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" aria-hidden="true" />
                <div class="text-sm font-medium">
                    {{ page.props.flash?.status || 'Kata sandi berhasil diperbarui.' }}
                </div>
            </div>

            <!-- Rate Limit Alert Banner -->
            <div
                v-if="(form.errors.current_password && form.errors.current_password.includes('Terlalu banyak percobaan')) || (form.errors.password && form.errors.password.includes('Terlalu banyak percobaan'))"
                class="p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 flex items-start gap-3"
                role="alert"
            >
                <AlertCircle class="w-5 h-5 text-rose-600 shrink-0 mt-0.5" aria-hidden="true" />
                <div class="text-sm font-medium">
                    {{ form.errors.current_password || form.errors.password }}
                </div>
            </div>

            <!-- Form Card -->
            <div class="bg-white border border-slate-200 rounded-xl shadow-xs p-6 sm:p-8">
                <form class="space-y-5" novalidate @submit.prevent="submit">
                    <!-- Field: Kata Sandi Saat Ini -->
                    <div>
                        <label
                            for="current_password"
                            class="block text-sm font-medium text-slate-700 mb-1"
                        >
                            Kata Sandi Saat Ini
                        </label>
                        <div class="relative">
                            <input
                                id="current_password"
                                v-model="form.current_password"
                                :type="showCurrentPassword ? 'text' : 'password'"
                                name="current_password"
                                autocomplete="current-password"
                                required
                                :aria-invalid="form.errors.current_password ? 'true' : 'false'"
                                :aria-describedby="form.errors.current_password ? 'current-password-error' : undefined"
                                class="w-full pl-3 pr-11 py-2 border rounded-lg text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition-colors"
                                :class="[
                                    form.errors.current_password
                                        ? 'border-rose-500 bg-rose-50/30'
                                        : 'border-slate-300 bg-white',
                                ]"
                                placeholder="••••••••••••"
                            />
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 px-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none focus:text-indigo-600 min-w-10 min-h-10 justify-center rounded-r-lg"
                                :aria-label="showCurrentPassword ? 'Sembunyikan kata sandi saat ini' : 'Tampilkan kata sandi saat ini'"
                                :aria-pressed="showCurrentPassword"
                                @click="showCurrentPassword = !showCurrentPassword"
                            >
                                <EyeOff v-if="showCurrentPassword" class="w-4 h-4" aria-hidden="true" />
                                <Eye v-else class="w-4 h-4" aria-hidden="true" />
                            </button>
                        </div>
                        <p
                            v-if="form.errors.current_password && !form.errors.current_password.includes('Terlalu banyak percobaan')"
                            id="current-password-error"
                            class="mt-1 text-xs text-rose-600 flex items-center gap-1 font-medium"
                            role="alert"
                        >
                            <AlertCircle class="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
                            <span>{{ form.errors.current_password }}</span>
                        </p>
                    </div>

                    <!-- Field: Kata Sandi Baru -->
                    <div>
                        <label
                            for="password"
                            class="block text-sm font-medium text-slate-700 mb-1"
                        >
                            Kata Sandi Baru
                        </label>
                        <div class="relative">
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showNewPassword ? 'text' : 'password'"
                                name="password"
                                autocomplete="new-password"
                                required
                                :aria-invalid="form.errors.password ? 'true' : 'false'"
                                :aria-describedby="form.errors.password ? 'new-password-error' : undefined"
                                class="w-full pl-3 pr-11 py-2 border rounded-lg text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition-colors"
                                :class="[
                                    form.errors.password
                                        ? 'border-rose-500 bg-rose-50/30'
                                        : 'border-slate-300 bg-white',
                                ]"
                                placeholder="••••••••••••"
                            />
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 px-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none focus:text-indigo-600 min-w-10 min-h-10 justify-center rounded-r-lg"
                                :aria-label="showNewPassword ? 'Sembunyikan kata sandi baru' : 'Tampilkan kata sandi baru'"
                                :aria-pressed="showNewPassword"
                                @click="showNewPassword = !showNewPassword"
                            >
                                <EyeOff v-if="showNewPassword" class="w-4 h-4" aria-hidden="true" />
                                <Eye v-else class="w-4 h-4" aria-hidden="true" />
                            </button>
                        </div>
                        <p
                            v-if="form.errors.password && !form.errors.password.includes('Terlalu banyak percobaan')"
                            id="new-password-error"
                            class="mt-1 text-xs text-rose-600 flex items-center gap-1 font-medium"
                            role="alert"
                        >
                            <AlertCircle class="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
                            <span>{{ form.errors.password }}</span>
                        </p>
                    </div>

                    <!-- Field: Konfirmasi Kata Sandi Baru -->
                    <div>
                        <label
                            for="password_confirmation"
                            class="block text-sm font-medium text-slate-700 mb-1"
                        >
                            Konfirmasi Kata Sandi Baru
                        </label>
                        <div class="relative">
                            <input
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                :type="showConfirmPassword ? 'text' : 'password'"
                                name="password_confirmation"
                                autocomplete="new-password"
                                required
                                :aria-invalid="form.errors.password_confirmation ? 'true' : 'false'"
                                :aria-describedby="form.errors.password_confirmation ? 'password-confirmation-error' : undefined"
                                class="w-full pl-3 pr-11 py-2 border rounded-lg text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition-colors"
                                :class="[
                                    form.errors.password_confirmation
                                        ? 'border-rose-500 bg-rose-50/30'
                                        : 'border-slate-300 bg-white',
                                ]"
                                placeholder="••••••••••••"
                            />
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 px-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none focus:text-indigo-600 min-w-10 min-h-10 justify-center rounded-r-lg"
                                :aria-label="showConfirmPassword ? 'Sembunyikan konfirmasi kata sandi' : 'Tampilkan konfirmasi kata sandi'"
                                :aria-pressed="showConfirmPassword"
                                @click="showConfirmPassword = !showConfirmPassword"
                            >
                                <EyeOff v-if="showConfirmPassword" class="w-4 h-4" aria-hidden="true" />
                                <Eye v-else class="w-4 h-4" aria-hidden="true" />
                            </button>
                        </div>
                        <p
                            v-if="form.errors.password_confirmation"
                            id="password-confirmation-error"
                            class="mt-1 text-xs text-rose-600 flex items-center gap-1 font-medium"
                            role="alert"
                        >
                            <AlertCircle class="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
                            <span>{{ form.errors.password_confirmation }}</span>
                        </p>
                    </div>

                    <!-- Password Policy Box -->
                    <div class="rounded-lg bg-slate-50 border border-slate-200 p-4 text-xs text-slate-600 space-y-2">
                        <div class="flex items-center gap-1.5 font-semibold text-slate-800">
                            <Shield class="w-4 h-4 text-indigo-600" aria-hidden="true" />
                            <span>Persyaratan Kata Sandi:</span>
                        </div>
                        <ul class="list-disc list-inside space-y-1 text-slate-600 pl-1">
                            <li>Minimal 12 karakter dan maksimal 128 karakter.</li>
                            <li>Mengandung setidaknya satu huruf besar (A-Z).</li>
                            <li>Mengandung setidaknya satu huruf kecil (a-z).</li>
                            <li>Mengandung setidaknya satu angka (0-9).</li>
                            <li>Tidak boleh memuat bagian nama pengguna/email Anda.</li>
                            <li>Harus berbeda dari kata sandi saat ini.</li>
                        </ul>
                    </div>

                    <!-- Action Buttons -->
                    <div class="pt-3 flex items-center justify-end gap-3 border-t border-slate-100">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 disabled:opacity-60 disabled:cursor-not-allowed transition-colors shadow-xs min-h-10"
                        >
                            <Loader2
                                v-if="form.processing"
                                class="w-4 h-4 animate-spin shrink-0"
                                aria-hidden="true"
                            />
                            <KeyRound
                                v-else
                                class="w-4 h-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span>{{ form.processing ? 'Menyimpan...' : 'Simpan Kata Sandi' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
