<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import {
    ShieldCheck,
    Server,
    Lock,
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
} from 'lucide-vue-next';
import AppLayout from '../Layouts/AppLayout.vue';
import PageHeader from '../Components/ui/PageHeader.vue';
import Card from '../Components/ui/Card.vue';
import Badge from '../Components/ui/Badge.vue';
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

const moduleGroups = computed(() => {
    return navigationGroups.filter((group) => group.title !== 'Dashboard');
});

const hasAnyModulePermission = computed(() => {
    return moduleGroups.value.some((group) =>
        group.items.some((item) => !item.permission || userPermissions.value.includes(item.permission))
    );
});

function hasPermission(permission) {
    if (!permission) return true;
    return userPermissions.value.includes(permission);
}
</script>

<template>
    <AppLayout title="Dashboard">
        <Head title="Dashboard" />

        <div class="space-y-6">
            <!-- Header Section -->
            <PageHeader
                title="Selamat Datang di ERP Distribusi"
                :description="`Masuk sebagai ${user?.name || 'Pengguna'} (${user?.email || ''}). Sistem Informasi Distribusi & Trading Monolith Terintegrasi.`"
            >
                <template #actions>
                    <div class="flex items-center gap-2">
                        <Badge variant="success" size="md">
                            <span
                                class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse mr-1.5"
                                aria-hidden="true"
                            />
                            Sistem Aktif
                        </Badge>
                        <Badge variant="info" size="md">
                            <ShieldCheck class="w-3.5 h-3.5 mr-1" aria-hidden="true" />
                            Security Hardened
                        </Badge>
                    </div>
                </template>
            </PageHeader>

            <!-- Module Grid or Empty State -->
            <div v-if="hasAnyModulePermission" class="space-y-6">
                <div
                    v-for="group in moduleGroups"
                    :key="group.title"
                    class="space-y-3"
                >
                    <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        {{ group.title }}
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        <Card
                            v-for="item in group.items"
                            :key="item.name"
                            padding="md"
                            :class="[
                                hasPermission(item.permission)
                                    ? 'border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700'
                                    : 'border-slate-200/60 dark:border-slate-800/60 opacity-60 bg-slate-50/50 dark:bg-slate-900/30',
                            ]"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-center gap-3">
                                    <div
                                        :class="[
                                            'w-10 h-10 rounded-lg flex items-center justify-center shrink-0',
                                            hasPermission(item.permission)
                                                ? 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400'
                                                : 'bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500',
                                        ]"
                                    >
                                        <component
                                            :is="iconMap[item.icon]"
                                            class="w-5 h-5"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div>
                                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                                            {{ item.name }}
                                        </h3>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            {{ hasPermission(item.permission) ? 'Izin Diberikan' : 'Perlu Izin Akses' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="shrink-0">
                                    <Badge
                                        v-if="!hasPermission(item.permission)"
                                        variant="warning"
                                        size="sm"
                                    >
                                        <Lock class="w-3 h-3 mr-1" aria-hidden="true" />
                                        Terkunci
                                    </Badge>
                                    <Badge
                                        v-else-if="item.badge"
                                        variant="neutral"
                                        size="sm"
                                    >
                                        {{ item.badge }}
                                    </Badge>
                                </div>
                            </div>
                        </Card>
                    </div>
                </div>
            </div>

            <!-- Empty State if No Module Permission -->
            <Card v-else padding="none">
                <EmptyState
                    title="Tidak Ada Modul Operasional"
                    description="Akun Anda belum memiliki izin akses untuk modul bisnis apa pun. Silakan hubungi administrator sistem untuk meminta hak akses."
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
