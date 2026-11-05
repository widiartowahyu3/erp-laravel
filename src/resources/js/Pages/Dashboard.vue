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
    purchaseOrders: {
        type: Object,
        default: null,
    },
    goodsReceipts: {
        type: Object,
        default: null,
    },
    salesOrders: {
        type: Object,
        default: null,
    },
    deliveryOrders: {
        type: Object,
        default: null,
    },
    salesInvoices: {
        type: Object,
        default: null,
    },
    purchaseInvoices: {
        type: Object,
        default: null,
    },
    stockBalances: {
        type: Object,
        default: null,
    },
    stockMovements: {
        type: Array,
        default: null,
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user || null);
const userPermissions = computed(() => page.props.auth?.permissions || []);

const hasAnySummaryPermission = computed(() => {
    return (
        props.purchaseOrders !== null ||
        props.goodsReceipts !== null ||
        props.salesOrders !== null ||
        props.deliveryOrders !== null ||
        props.salesInvoices !== null ||
        props.purchaseInvoices !== null ||
        props.stockBalances !== null ||
        props.stockMovements !== null
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
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    <!-- 1. Purchase Orders Metric Card -->
                    <Card v-if="purchaseOrders !== null" padding="md" class="border-indigo-100 dark:border-indigo-900/30">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                                    Pesanan Pembelian Berjalan
                                </span>
                                <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                    {{ purchaseOrders.active_po_count }} <span class="text-xs font-medium text-slate-500">PO</span>
                                </div>
                                <div class="mt-1 text-sm text-indigo-600 dark:text-indigo-400 font-medium">
                                    Total: <Rupiah :value="purchaseOrders.active_po_total" />
                                </div>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                                <FileText class="w-5 h-5" aria-hidden="true" />
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                href="/purchasing/purchase-orders?filter[status]=approved"
                                class="inline-flex items-center text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 gap-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 rounded"
                            >
                                <span>Lihat daftar pesanan disetujui</span>
                                <ArrowRight class="w-3.5 h-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </Card>

                    <!-- 2. Goods Receipts Metric Card -->
                    <Card v-if="goodsReceipts !== null" padding="md" class="border-emerald-100 dark:border-emerald-900/30">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                                    Penerimaan Barang Menunggu
                                </span>
                                <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                    {{ goodsReceipts.draft_gr_count }} <span class="text-xs font-medium text-slate-500">Draf</span>
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
                                href="/purchasing/goods-receipts?filter[status]=draft"
                                class="inline-flex items-center text-xs font-medium text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300 gap-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 rounded"
                            >
                                <span>Periksa draf penerimaan</span>
                                <ArrowRight class="w-3.5 h-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </Card>

                    <!-- 3. Sales Orders Metric Card -->
                    <Card v-if="salesOrders !== null" padding="md" class="border-blue-100 dark:border-blue-900/30">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                                    Pesanan Penjualan Berjalan
                                </span>
                                <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                    {{ salesOrders.active_so_count }} <span class="text-xs font-medium text-slate-500">SO</span>
                                </div>
                                <div class="mt-1 text-sm text-blue-600 dark:text-blue-400 font-medium">
                                    Total: <Rupiah :value="salesOrders.active_so_total" />
                                </div>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0">
                                <ShoppingCart class="w-5 h-5" aria-hidden="true" />
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                href="/sales/sales-orders?filter[status]=approved"
                                class="inline-flex items-center text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 gap-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 rounded"
                            >
                                <span>Lihat daftar pesanan disetujui</span>
                                <ArrowRight class="w-3.5 h-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </Card>

                    <!-- 4. Delivery Orders Metric Card -->
                    <Card v-if="deliveryOrders !== null" padding="md" class="border-teal-100 dark:border-teal-900/30">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                                    Surat Jalan Menunggu
                                </span>
                                <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                    {{ deliveryOrders.draft_do_count }} <span class="text-xs font-medium text-slate-500">Draf</span>
                                </div>
                                <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    Menunggu pengiriman fisik &amp; posting
                                </div>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-teal-400 flex items-center justify-center shrink-0">
                                <Truck class="w-5 h-5" aria-hidden="true" />
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                href="/sales/delivery-orders?filter[status]=draft"
                                class="inline-flex items-center text-xs font-medium text-teal-600 dark:text-teal-400 hover:text-teal-700 dark:hover:text-teal-300 gap-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 rounded"
                            >
                                <span>Periksa draf surat jalan</span>
                                <ArrowRight class="w-3.5 h-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </Card>

                    <!-- Receivables Metric Cards -->
                    <Card v-if="salesInvoices !== null" padding="md" class="border-indigo-100 dark:border-indigo-900/30">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                                    Piutang Beredar
                                </span>
                                <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                    <Rupiah :value="salesInvoices.outstanding_total" />
                                </div>
                                <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    Sisa tagihan faktur yang sudah diposting
                                </div>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                                <Wallet class="w-5 h-5" aria-hidden="true" />
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                href="/sales/invoices?filter[status]=posted"
                                class="inline-flex items-center text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 gap-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 rounded"
                            >
                                <span>Lihat faktur diposting</span>
                                <ArrowRight class="w-3.5 h-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </Card>

                    <Card v-if="salesInvoices !== null" padding="md" class="border-rose-100 dark:border-rose-900/30">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                                    Faktur Jatuh Tempo
                                </span>
                                <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                    {{ salesInvoices.overdue_count }} <span class="text-xs font-medium text-slate-500">Faktur</span>
                                </div>
                                <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    Sudah lewat tempo dan belum lunas
                                </div>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                                <CalendarClock class="w-5 h-5" aria-hidden="true" />
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                href="/sales/invoices?filter[overdue]=1"
                                class="inline-flex items-center text-xs font-medium text-rose-600 dark:text-rose-400 hover:text-rose-700 dark:hover:text-rose-300 gap-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 rounded"
                            >
                                <span>Lihat faktur jatuh tempo</span>
                                <ArrowRight class="w-3.5 h-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </Card>

                    <!-- Payables Metric Cards -->
                    <Card v-if="purchaseInvoices !== null" padding="md" class="border-amber-100 dark:border-amber-900/30">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                                    Utang Beredar
                                </span>
                                <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                    <Rupiah :value="purchaseInvoices.outstanding_total" />
                                </div>
                                <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    Sisa tagihan faktur pembelian yang sudah diposting
                                </div>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                                <Wallet class="w-5 h-5" aria-hidden="true" />
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                href="/purchasing/invoices?filter[status]=posted"
                                class="inline-flex items-center text-xs font-medium text-amber-700 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 gap-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 rounded"
                            >
                                <span>Lihat faktur pembelian diposting</span>
                                <ArrowRight class="w-3.5 h-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </Card>

                    <Card v-if="purchaseInvoices !== null" padding="md" class="border-rose-100 dark:border-rose-900/30">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                                    Faktur Pembelian Jatuh Tempo
                                </span>
                                <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                    {{ purchaseInvoices.overdue_count }} <span class="text-xs font-medium text-slate-500">Faktur</span>
                                </div>
                                <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    Sudah lewat tempo dan belum lunas
                                </div>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                                <CalendarClock class="w-5 h-5" aria-hidden="true" />
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                href="/purchasing/invoices?filter[overdue]=1"
                                class="inline-flex items-center text-xs font-medium text-rose-600 dark:text-rose-400 hover:text-rose-700 dark:hover:text-rose-300 gap-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 rounded"
                            >
                                <span>Lihat faktur pembelian jatuh tempo</span>
                                <ArrowRight class="w-3.5 h-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </Card>

                    <!-- 5. Stock Below Minimum Metric Card -->
                    <Card v-if="stockBalances !== null" padding="md" class="border-rose-100 dark:border-rose-900/30">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                                    Peringatan Stok Minimum
                                </span>
                                <div class="mt-2 text-2xl font-bold text-rose-600 dark:text-rose-400">
                                    {{ stockBalances.low_stock_count }} <span class="text-xs font-medium text-slate-500">Produk</span>
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
                    <Card v-if="stockBalances !== null" padding="md">
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

                        <div v-if="stockBalances.deficient_products.length > 0" class="overflow-x-auto">
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
                                        v-for="p in stockBalances.deficient_products"
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
                    <Card v-if="stockMovements !== null" padding="md">
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

                        <div v-if="stockMovements.length > 0" class="overflow-x-auto">
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
                                        v-for="m in stockMovements"
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
