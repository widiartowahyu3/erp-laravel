<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import { LogIn } from 'lucide-vue-next';
import GuestLayout from '../../Layouts/GuestLayout.vue';
import FormField from '../../Components/ui/FormField.vue';
import TextInput from '../../Components/ui/TextInput.vue';
import PasswordInput from '../../Components/ui/PasswordInput.vue';
import Button from '../../Components/ui/Button.vue';
import Alert from '../../Components/ui/Alert.vue';

const form = useForm({
    email: '',
    password: '',
});

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
            <h1 class="text-xl font-bold text-slate-900 dark:text-white tracking-tight">
                Masuk ke Akun Anda
            </h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Silakan masukkan email dan kata sandi untuk melanjutkan.
            </p>
        </div>

        <!-- Rate Limit Alert Banner -->
        <Alert
            v-if="form.errors.email && form.errors.email.includes('Terlalu banyak percobaan')"
            variant="danger"
            class="mb-4"
        >
            {{ form.errors.email }}
        </Alert>

        <form class="space-y-4" novalidate @submit.prevent="submit">
            <!-- Field: Email -->
            <FormField
                label="Alamat Email"
                :error="form.errors.email && !form.errors.email.includes('Terlalu banyak percobaan') ? form.errors.email : undefined"
                required
            >
                <template #default="{ id, invalid, describedBy }">
                    <TextInput
                        :id="id"
                        v-model="form.email"
                        type="email"
                        name="email"
                        autocomplete="email"
                        placeholder="nama@perusahaan.com"
                        required
                        :invalid="invalid"
                        :described-by="describedBy"
                    />
                </template>
            </FormField>

            <!-- Field: Kata Sandi -->
            <FormField
                label="Kata Sandi"
                :error="form.errors.password"
                required
            >
                <template #default="{ id, invalid, describedBy }">
                    <PasswordInput
                        :id="id"
                        v-model="form.password"
                        name="password"
                        autocomplete="current-password"
                        placeholder="••••••••••••"
                        required
                        :invalid="invalid"
                        :described-by="describedBy"
                    />
                </template>
            </FormField>

            <!-- Tombol Masuk -->
            <div class="pt-2">
                <Button
                    type="submit"
                    variant="primary"
                    block
                    :loading="form.processing"
                >
                    <template #icon>
                        <LogIn class="w-4 h-4" aria-hidden="true" />
                    </template>
                    Masuk
                </Button>
            </div>
        </form>
    </GuestLayout>
</template>
