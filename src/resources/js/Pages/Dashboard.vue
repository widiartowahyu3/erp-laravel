<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
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
    AlertTriangle,
    Clock,
} from 'lucide-vue-next';
import AppLayout from '../Layouts/AppLayout.vue';
import PageHeader from '../Components/ui/PageHeader.vue';
import Card from '../Components/ui/Card.vue';
import EmptyState from '../Components/ui/EmptyState.vue';
import Rupiah from '../Components/ui/Rupiah.vue';
import Quantity from '../Components/ui/Quantity.vue';
import DateDisplay from '../Components/ui/DateDisplay.vue';
import Badge from '../Components/ui/Badge.vue';
import { navigationGroups } from '../config/navigation.js';

const props = defineProps({
    appName: {
        type: String,
        default: 'ERP Distribusi',
    },
    purchase_orders: {
        type: Object,
        default: null,
    },
    goods_receipts: {
        type: Object,
        default: null,
    },
    stock_balances: {
        type: Object,
        default: null,
    },
    stock_movements: {
        type: Array,
        default: null,
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user || null);
const userPermissions = computed(() => page.props.auth?.permissions || []);

const hasAnySummaryPermission = computed(() => {
    return (
        props.purchase_orders !== null ||
        props.goods_receipts !== null ||
        props.stock_balances !== null ||
        props.stock_movements !== null
    );
});

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

const movementTypeLabels = {
    receipt: 'Penerimaan',
    delivery: 'Pengiriman',
    adjustment_in: 'Koreksi Lebih',
    adjustment_out: 'Koreksi Kurang',
};
</script>

<template>
    <AppLayout title="Dashboard">
        <Head title="Dashboard" />

        <div class="space-y-6">
            <!-- Header Section -->
            <PageHeader
                title="Selamat Datang di ERP Distribusi"
                :description="`Masuk sebagai ${user?.name || 'Pengguna'} (${user?.email || ''}). Ringkasan operasional dan modul bisnis terintegrasi.`"
            />

            <!-- Operational Metrics Summary Cards -->
            <div v-if="hasAnySummaryPermission" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <!-- 1. Purchase Orders Metric Card -->
                    <Card v-if="purchase_orders !== null" padding="md" class="border-indigo-100 dark:border-indigo-900/30">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                                    Pesanan Pembelian Berjalan
                                </span>
                                <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                    {{ purchase_orders.active_po_count }} <span class="text-xs font-medium text-slate-500">PO</span>
                                </div>
                                <div class="mt-1 text-sm text-indigo-600 dark:text-indigo-400 font-medium">
                                    Total: <Rupiah :value="purchase_orders.active_po_total" />
                                </div>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                                <FileText class="w-5 h-5" aria-hidden="true" />
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                href="/purchasing/purchase-orders?status=approved"
                                class="inline-flex items-center text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 gap-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 rounded"
                            >
                                <span>Lihat daftar pesanan disetujui</span>
                                <ArrowRight class="w-3.5 h-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </Card>

                    <!-- 2. Goods Receipts Metric Card -->
                    <Card v-if="goods_receipts !== null" padding="md" class="border-emerald-100 dark:border-emerald-900/30">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                                    Penerimaan Barang Menunggu
                                </span>
                                <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                    {{ goods_receipts.draft_gr_count }} <span class="text-xs font-medium text-slate-500">Draf</span>
                                </div>
                                <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    Menunggu verifikasi fisik &amp; posting
                                </div>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                                <PackageCheck class="w-5 h-5" aria-hidden="true" />
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                href="/purchasing/goods-receipts?status=draft"
                                class="inline-flex items-center text-xs font-medium text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300 gap-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 rounded"
                            >
                                <span>Periksa draf penerimaan</span>
                                <ArrowRight class="w-3.5 h-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </Card>

                    <!-- 3. Stock Below Minimum Metric Card -->
                    <Card v-if="stock_balances !== null" padding="md" class="border-rose-100 dark:border-rose-900/30">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                                    Peringatan Stok Minimum
                                </span>
                                <div class="mt-2 text-2xl font-bold text-rose-600 dark:text-rose-400">
                                    {{ stock_balances.low_stock_count }} <span class="text-xs font-medium text-slate-500">Produk</span>
                                </div>
                                <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    Kuantitas di bawah batas aman
                                </div>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                                <AlertTriangle class="w-5 h-5" aria-hidden="true" />
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                href="/inventory/stock-balances"
                                class="inline-flex items-center text-xs font-medium text-rose-600 dark:text-rose-400 hover:text-rose-700 dark:hover:text-rose-300 gap-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 rounded"
                            >
                                <span>Pantau persediaan gudang</span>
                                <ArrowRight class="w-3.5 h-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </Card>
                </div>

                <!-- Deep Dive Tables (Deficient Products & Recent Movements) -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Low Stock Detail Table -->
                    <Card v-if="stock_balances !== null" padding="md">
                        <template #header>
                            <div class="flex items-center justify-between">
                                <h2 class="text-sm font-semibold text-slate-900 dark:text-white flex items-center gap-2">
                                    <AlertTriangle class="w-4 h-4 text-rose-500" aria-hidden="true" />
                                    Produk Paling Kekurangan Stok (Top 5)
                                </h2>
                                <Link
                                    href="/inventory/stock-balances"
                                    class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                                >
                                    Semua Saldo
                                </Link>
                            </div>
                        </template>

                        <div v-if="stock_balances.deficient_products.length > 0" class="overflow-x-auto">
                            <table class="w-full text-left text-xs divide-y divide-slate-200 dark:divide-slate-700">
                                <caption class="sr-only">Daftar produk dengan kuantitas stok di bawah batas minimum</caption>
                                <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-500 uppercase font-semibold">
                                    <tr>
                                        <th scope="col" class="py-2.5 px-3">Produk</th>
                                        <th scope="col" class="py-2.5 px-3 text-right">Stok</th>
                                        <th scope="col" class="py-2.5 px-3 text-right">Batas Min</th>
                                        <th scope="col" class="py-2.5 px-3 text-right">Defisit</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr
                                        v-for="p in stock_balances.deficient_products"
                                        :key="p.id"
                                        class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40"
                                    >
                                        <td class="py-2.5 px-3">
                                            <div class="font-medium text-slate-800 dark:text-slate-200 truncate max-w-[180px]">
                                                {{ p.name }}
                                            </div>
                                            <div class="text-[11px] text-slate-400 font-mono">
                                                {{ p.sku }}
                                            </div>
                                        </td>
                                        <td class="py-2.5 px-3 text-right font-mono">
                                            <Quantity :value="p.current_stock" :unit="p.unit_code" />
                                        </td>
                                        <td class="py-2.5 px-3 text-right font-mono text-slate-500">
                                            <Quantity :value="p.min_stock" :unit="p.unit_code" />
                                        </td>
                                        <td class="py-2.5 px-3 text-right font-mono font-semibold text-rose-600 dark:text-rose-400">
                                            -<Quantity :value="p.shortage" :unit="p.unit_code" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <EmptyState
                            v-else
                            title="Stok Aman"
                            description="Seluruh produk aktif saat ini memiliki saldo di atas batas minimum."
                        />
                    </Card>

                    <!-- Recent Stock Movements Table -->
                    <Card v-if="stock_movements !== null" padding="md">
                        <template #header>
                            <div class="flex items-center justify-between">
                                <h2 class="text-sm font-semibold text-slate-900 dark:text-white flex items-center gap-2">
                                    <History class="w-4 h-4 text-indigo-500" aria-hidden="true" />
                                    Mutasi Persediaan Terkini
                                </h2>
                                <Link
                                    href="/inventory/stock-movements"
                                    class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                                >
                                    Semua Mutasi
                                </Link>
                            </div>
                        </template>

                        <div v-if="stock_movements.length > 0" class="overflow-x-auto">
                            <table class="w-full text-left text-xs divide-y divide-slate-200 dark:divide-slate-700">
                                <caption class="sr-only">Daftar mutasi stok persediaan terbaru di seluruh gudang</caption>
                                <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-500 uppercase font-semibold">
                                    <tr>
                                        <th scope="col" class="py-2.5 px-3">Tanggal</th>
                                        <th scope="col" class="py-2.5 px-3">Produk / Gudang</th>
                                        <th scope="col" class="py-2.5 px-3 text-right">Kuantitas</th>
                                        <th scope="col" class="py-2.5 px-3 text-right">Biaya</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr
                                        v-for="m in stock_movements"
                                        :key="m.id"
                                        class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40"
                                    >
                                        <td class="py-2.5 px-3 whitespace-nowrap text-slate-600 dark:text-slate-400">
                                            <DateDisplay :value="m.movement_date" format="datetime" />
                                            <div class="mt-0.5">
                                                <Badge size="sm" variant="neutral">
                                                    {{ movementTypeLabels[m.movement_type] || m.movement_type }}
                                                </Badge>
                                            </div>
                                        </td>
                                        <td class="py-2.5 px-3">
                                            <div class="font-medium text-slate-800 dark:text-slate-200 truncate max-w-[160px]">
                                                {{ m.product_name }}
                                            </div>
                                            <div class="text-[11px] text-slate-400">
                                                {{ m.warehouse_name }}
                                            </div>
                                        </td>
                                        <td class="py-2.5 px-3 text-right font-mono font-medium" :class="Number(m.quantity) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">
                                            <Quantity :value="m.quantity" />
                                        </td>
                                        <td class="py-2.5 px-3 text-right font-mono text-slate-600 dark:text-slate-300">
                                            <Rupiah :value="m.unit_cost" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <EmptyState
                            v-else
                            title="Belum Ada Mutasi"
                            description="Belum ada riwayat transaksi mutasi stok yang tercatat di dalam sistem."
                        />
                    </Card>
                </div>
            </div>

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
                        >
                            <span class="sr-only">Buka modul {{ item.name }}</span>
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
            <Card v-else-if="!hasAnySummaryPermission" padding="none">
                <EmptyState
                    title="Tidak Ada Modul Operasional"
                    description="Akun Anda belum memiliki izin akses untuk modul bisnis yang aktif saat ini. Silakan hubungi administrator sistem untuk meminta hak akses."
                />
            </Card>
        </div>
    </AppLayout>
</template>
