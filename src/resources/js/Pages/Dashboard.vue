<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import { ShieldCheck, Activity, Server, Clock } from 'lucide-vue-next';

defineProps({
    appName: {
        type: String,
        default: 'ERP Distribusi',
    },
});
</script>

<template>
    <AppLayout title="Dashboard">
        <Head title="Dashboard" />

        <div class="space-y-6">
            <!-- Header Section -->
            <div
                class="bg-white rounded-xl border border-slate-200 p-6 sm:p-8 shadow-xs"
            >
                <div
                    class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4"
                >
                    <div>
                        <h1
                            class="text-2xl font-bold text-slate-900 tracking-tight"
                        >
                            Selamat Datang di ERP Distribusi
                        </h1>
                        <p class="mt-1 text-sm text-slate-600">
                            Sistem Informasi Distribusi & Trading Monolith
                            Terintegrasi (Fase 1 - Baseline Fondasi).
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200"
                        >
                            <span
                                class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"
                                aria-hidden="true"
                            />
                            Sistem Aktif
                        </span>
                        <span
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200"
                        >
                            <ShieldCheck
                                class="w-3.5 h-3.5"
                                aria-hidden="true"
                            />
                            Security Hardened
                        </span>
                    </div>
                </div>
            </div>

            <!-- Summary Cards Skeleton -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div
                    v-for="i in 4"
                    :key="i"
                    class="bg-white rounded-xl border border-slate-200 p-5 shadow-xs space-y-3"
                >
                    <div class="flex items-center justify-between">
                        <div
                            class="h-4 w-24 bg-slate-200 rounded-sm animate-pulse"
                        />
                        <div
                            class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center animate-pulse"
                        />
                    </div>
                    <div class="space-y-2">
                        <div
                            class="h-7 w-32 bg-slate-200 rounded-sm animate-pulse"
                        />
                        <div
                            class="h-3 w-20 bg-slate-100 rounded-sm animate-pulse"
                        />
                    </div>
                </div>
            </div>

            <!-- System Info Section -->
            <div
                class="bg-white rounded-xl border border-slate-200 p-6 shadow-xs"
            >
                <h2
                    class="text-base font-semibold text-slate-900 mb-4 flex items-center gap-2"
                >
                    <Server
                        class="w-5 h-5 text-indigo-600"
                        aria-hidden="true"
                    />
                    Status Lingkungan & Arsitektur
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div
                        class="p-4 rounded-lg bg-slate-50 border border-slate-200/80"
                    >
                        <div
                            class="text-xs font-medium text-slate-500 uppercase tracking-wider"
                        >
                            Arsitektur Backend
                        </div>
                        <div class="mt-1 text-sm font-semibold text-slate-900">
                            Laravel 13 Monolith
                        </div>
                        <div class="mt-0.5 text-xs text-slate-600">
                            PHP 8.5 FPM (Hardened Container)
                        </div>
                    </div>
                    <div
                        class="p-4 rounded-lg bg-slate-50 border border-slate-200/80"
                    >
                        <div
                            class="text-xs font-medium text-slate-500 uppercase tracking-wider"
                        >
                            Antarmuka Frontend
                        </div>
                        <div class="mt-1 text-sm font-semibold text-slate-900">
                            Vue 3 + Inertia + Tailwind 4
                        </div>
                        <div class="mt-0.5 text-xs text-slate-600">
                            Self-hosted Inter Font
                        </div>
                    </div>
                    <div
                        class="p-4 rounded-lg bg-slate-50 border border-slate-200/80"
                    >
                        <div
                            class="text-xs font-medium text-slate-500 uppercase tracking-wider"
                        >
                            Basis Data Relasional
                        </div>
                        <div class="mt-1 text-sm font-semibold text-slate-900">
                            MariaDB 12.3 LTS
                        </div>
                        <div class="mt-0.5 text-xs text-slate-600">
                            Role Terpisah: erp_app & erp_migrator
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
