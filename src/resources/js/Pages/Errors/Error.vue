<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import GuestLayout from '../../Layouts/GuestLayout.vue';
import {
    AlertTriangle,
    ShieldAlert,
    FileQuestion,
    Clock,
    RefreshCw,
} from 'lucide-vue-next';

const props = defineProps({
    status: {
        type: Number,
        required: true,
    },
    message: {
        type: String,
        default: '',
    },
});

const title = computed(() => {
    return (
        {
            403: '403 - Akses Ditolak',
            404: '404 - Halaman Tidak Ditemukan',
            419: '419 - Sesi Kedaluwarsa',
            429: '429 - Terlalu Banyak Permintaan',
            500: '500 - Kesalahan Server',
            503: '503 - Layanan Sedang Pemeliharaan',
        }[props.status] || `${props.status} - Kesalahan`
    );
});

const description = computed(() => {
    if (props.message) {
        return props.message;
    }
    return (
        {
            403: 'Anda tidak memiliki hak akses untuk membuka halaman ini.',
            404: 'Halaman yang Anda cari tidak dapat ditemukan.',
            419: 'Sesi Anda telah kedaluwarsa. Silakan muat ulang halaman.',
            429: 'Terlalu banyak permintaan diterima. Harap tunggu beberapa saat.',
            500: 'Terjadi kendala internal pada server. Tim teknis sedang menanganinya.',
            503: 'Layanan sedang dalam pemeliharaan berkala. Silakan kembali nanti.',
        }[props.status] || 'Terjadi kesalahan yang tidak terduga.'
    );
});
</script>

<template>
    <GuestLayout :title="title">
        <Head :title="title" />

        <div class="text-center py-4">
            <div
                class="w-12 h-12 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center mx-auto mb-4"
            >
                <ShieldAlert
                    v-if="status === 403"
                    class="w-6 h-6"
                    aria-hidden="true"
                />
                <FileQuestion
                    v-else-if="status === 404"
                    class="w-6 h-6"
                    aria-hidden="true"
                />
                <Clock
                    v-else-if="status === 419"
                    class="w-6 h-6"
                    aria-hidden="true"
                />
                <RefreshCw
                    v-else-if="status === 429"
                    class="w-6 h-6"
                    aria-hidden="true"
                />
                <AlertTriangle v-else class="w-6 h-6" aria-hidden="true" />
            </div>

            <h1 class="text-xl font-bold text-slate-900 tracking-tight">
                {{ title }}
            </h1>
            <p class="mt-2 text-sm text-slate-600 leading-relaxed">
                {{ description }}
            </p>

            <div
                class="mt-6 flex flex-col sm:flex-row items-center justify-center gap-3"
            >
                <Link
                    href="/"
                    class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 shadow-xs transition-colors"
                >
                    Kembali ke Beranda
                </Link>
            </div>
        </div>
    </GuestLayout>
</template>
