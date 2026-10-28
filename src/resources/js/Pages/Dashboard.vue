<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Server,
    Package,
    FolderTree,
    Ruler,
    Truck,
    Users,
    Warehouse,
    Receipt,
    CalendarClock,
    FileText,
    PackageCheck,
    ReceiptText,
    ShoppingCart,
    FileSpreadsheet,
    Boxes,
    History,
    SlidersHorizontal,
    ListTree,
    BookOpen,
    Wallet,
    BarChart3,
    LineChart,
    PieChart,
    TrendingUp,
    UserCheck,
    Shield,
    Settings,
    ShieldAlert,
    LayoutDashboard,
    ArrowRight,
} from 'lucide-vue-next';
import AppLayout from '../Layouts/AppLayout.vue';
import PageHeader from '../Components/ui/PageHeader.vue';
import Card from '../Components/ui/Card.vue';
import EmptyState from '../Components/ui/EmptyState.vue';
import { navigationGroups } from '../config/navigation.js';

defineProps({
    appName: {
        type: String,
        default: 'ERP Distribusi',
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user || null);
const userPermissions = computed(() => page.props.auth?.permissions || []);

const iconMap = {
    LayoutDashboard,
    Package,
    FolderTree,
    Ruler,
    Truck,
    Users,
    Warehouse,
    Receipt,
    CalendarClock,
    FileText,
    PackageCheck,
    ReceiptText,
    ShoppingCart,
    FileSpreadsheet,
    Boxes,
    History,
    SlidersHorizontal,
    ListTree,
    BookOpen,
    Wallet,
    BarChart3,
    LineChart,
    PieChart,
    TrendingUp,
    UserCheck,
    Shield,
    Settings,
    ShieldAlert,
};

const availableModuleGroups = computed(() => {
    return navigationGroups
        .filter((group) => group.title !== 'Dashboard')
        .map((group) => {
            const accessibleItems = group.items.filter((item) => {
                if (item.available !== true) return false;
                if (!item.permission) return true;
                return userPermissions.value.includes(item.permission);
            });
            return {
                ...group,
                items: accessibleItems,
            };
        })
        .filter((group) => group.items.length > 0);
});

const hasAnyAccessibleModule = computed(() => {
    return availableModuleGroups.value.some((group) => group.items.length > 0);
});
</script>

<template>
    <AppLayout title="Dashboard">
        <Head title="Dashboard" />

        <div class="space-y-6">
            <!-- Header Section -->
            <PageHeader
                title="Selamat Datang di ERP Distribusi"
                :description="`Masuk sebagai ${user?.name || 'Pengguna'} (${user?.email || ''}). Sistem Informasi Distribusi & Trading Monolith Terintegrasi.`"
            />

            <!-- Module Grid or Empty State -->
            <div v-if="hasAnyAccessibleModule" class="space-y-6">
                <div
                    v-for="group in availableModuleGroups"
                    :key="group.title"
                    class="space-y-3"
                >
                    <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        {{ group.title }}
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        <Link
                            v-for="item in group.items"
                            :key="item.name"
                            :href="item.href"
                            class="group block focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 rounded-xl"
                            :aria-label="`Buka modul ${item.name}`"
                        >
                            <Card
                                padding="md"
                                class="h-full border-slate-200 dark:border-slate-800 hover:border-indigo-400 dark:hover:border-indigo-500 hover:shadow-sm transition-all"
                            >
                                <div class="flex items-start gap-3">
                                    <div
                                        class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0 bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 group-hover:bg-indigo-600 group-hover:text-white transition-colors"
                                    >
                                        <component
                                            :is="iconMap[item.icon]"
                                            class="w-5 h-5"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors truncate">
                                            {{ item.name }}
                                        </h3>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 line-clamp-2">
                                            {{ item.description }}
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-xs font-medium text-indigo-600 dark:text-indigo-400">
                                    <span>Kelola data</span>
                                    <ArrowRight class="w-4 h-4 transform group-hover:translate-x-0.5 transition-transform" aria-hidden="true" />
                                </div>
                            </Card>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Empty State if No Module Permission -->
            <Card v-else padding="none">
                <EmptyState
                    title="Tidak Ada Modul Operasional"
                    description="Akun Anda belum memiliki izin akses untuk modul bisnis yang aktif saat ini. Silakan hubungi administrator sistem untuk meminta hak akses."
                />
            </Card>

            <!-- Architecture & Environment Status -->
            <Card padding="md">
                <div class="space-y-4">
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white flex items-center gap-2">
                        <Server class="w-5 h-5 text-indigo-600 dark:text-indigo-400" aria-hidden="true" />
                        Status Lingkungan &amp; Arsitektur
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="p-4 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80">
                            <div class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                Arsitektur Backend
                            </div>
                            <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">
                                Laravel 13 Monolith
                            </div>
                            <div class="mt-0.5 text-xs text-slate-600 dark:text-slate-300">
                                PHP 8.5 FPM (Hardened Container)
                            </div>
                        </div>

                        <div class="p-4 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80">
                            <div class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                Antarmuka Frontend
                            </div>
                            <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">
                                Vue 3 + Inertia + Tailwind 4
                            </div>
                            <div class="mt-0.5 text-xs text-slate-600 dark:text-slate-300">
                                Self-hosted Inter Font
                            </div>
                        </div>

                        <div class="p-4 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80">
                            <div class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                Basis Data Relasional
                            </div>
                            <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">
                                MariaDB 12.3 LTS
                            </div>
                            <div class="mt-0.5 text-xs text-slate-600 dark:text-slate-300">
                                Role Terpisah: erp_app &amp; erp_migrator
                            </div>
                        </div>
                    </div>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
