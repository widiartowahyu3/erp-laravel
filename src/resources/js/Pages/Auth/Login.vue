<script setup>
import { ref } from 'vue';
import { useForm, Head } from '@inertiajs/vue3';
import { Eye, EyeOff, LogIn, Loader2, AlertCircle } from 'lucide-vue-next';
import GuestLayout from '../../Layouts/GuestLayout.vue';

const showPassword = ref(false);

const form = useForm({
    email: '',
    password: '',
});

function toggleShowPassword() {
    showPassword.value = !showPassword.value;
}

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <GuestLayout title="Masuk ke Sistem">
        <Head title="Masuk ke Sistem" />

        <div class="mb-6">
            <h1 class="text-xl font-bold text-slate-900 tracking-tight">
                Masuk ke Akun Anda
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Silakan masukkan email dan kata sandi untuk melanjutkan.
            </p>
        </div>

        <!-- Rate Limit Alert Banner -->
        <div
            v-if="form.errors.email && form.errors.email.includes('Terlalu banyak percobaan')"
            class="mb-4 p-3.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 flex items-start gap-2.5"
            role="alert"
        >
            <AlertCircle class="w-4 h-4 text-rose-600 shrink-0 mt-0.5" aria-hidden="true" />
            <div class="text-xs sm:text-sm font-medium">
                {{ form.errors.email }}
            </div>
        </div>

        <form class="space-y-4" novalidate @submit.prevent="submit">
            <!-- Field: Email -->
            <div>
                <label
                    for="email"
                    class="block text-sm font-medium text-slate-700 mb-1"
                >
                    Alamat Email
                </label>
                <div class="relative">
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        name="email"
                        autocomplete="email"
                        required
                        :aria-invalid="form.errors.email ? 'true' : 'false'"
                        :aria-describedby="form.errors.email ? 'email-error' : undefined"
                        class="w-full px-3 py-2 border rounded-lg text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition-colors"
                        :class="[
                            form.errors.email
                                ? 'border-rose-500 bg-rose-50/30'
                                : 'border-slate-300 bg-white',
                        ]"
                        placeholder="nama@perusahaan.com"
                    />
                </div>
                <p
                    v-if="form.errors.email"
                    id="email-error"
                    class="mt-1 text-xs text-rose-600 flex items-center gap-1 font-medium"
                    role="alert"
                >
                    <AlertCircle class="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
                    <span>{{ form.errors.email }}</span>
                </p>
            </div>

            <!-- Field: Kata Sandi -->
            <div>
                <label
                    for="password"
                    class="block text-sm font-medium text-slate-700 mb-1"
                >
                    Kata Sandi
                </label>
                <div class="relative">
                    <input
                        id="password"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        name="password"
                        autocomplete="current-password"
                        required
                        :aria-invalid="form.errors.password ? 'true' : 'false'"
                        :aria-describedby="form.errors.password ? 'password-error' : undefined"
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
                        :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                        :aria-pressed="showPassword"
                        @click="toggleShowPassword"
                    >
                        <EyeOff v-if="showPassword" class="w-4 h-4" aria-hidden="true" />
                        <Eye v-else class="w-4 h-4" aria-hidden="true" />
                    </button>
                </div>
                <p
                    v-if="form.errors.password"
                    id="password-error"
                    class="mt-1 text-xs text-rose-600 flex items-center gap-1 font-medium"
                    role="alert"
                >
                    <AlertCircle class="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
                    <span>{{ form.errors.password }}</span>
                </p>
            </div>

            <!-- Tombol Masuk -->
            <div class="pt-2">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 disabled:opacity-60 disabled:cursor-not-allowed transition-colors shadow-xs min-h-10"
                >
                    <Loader2
                        v-if="form.processing"
                        class="w-4 h-4 animate-spin shrink-0"
                        aria-hidden="true"
                    />
                    <LogIn
                        v-else
                        class="w-4 h-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span>{{ form.processing ? 'Memproses...' : 'Masuk' }}</span>
                </button>
            </div>
        </form>
    </GuestLayout>
</template>
