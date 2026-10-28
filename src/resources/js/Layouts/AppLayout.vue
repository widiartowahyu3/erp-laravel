<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
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
    Menu,
    X,
    ChevronLeft,
    ChevronRight,
    ChevronDown,
    Sun,
    Moon,
    KeyRound,
    LogOut,
    PanelLeftClose,
    PanelLeftOpen,
} from 'lucide-vue-next';
import { navigationGroups } from '../config/navigation.js';
import { useTheme } from '@/composables/useTheme';
import { useConfirm } from '@/composables/useConfirm';
import Dropdown from '@/Components/ui/Dropdown.vue';
import FlashRegion from '@/Components/ui/FlashRegion.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import Badge from '@/Components/ui/Badge.vue';

const props = defineProps({
    title: {
        type: String,
        default: 'Dashboard',
    },
});

const page = usePage();
const { isDark, toggleTheme } = useTheme();
const { state: confirmState, onConfirm, onCancel } = useConfirm();

const isSidebarCollapsed = ref(false);
const isMobileDrawerOpen = ref(false);
const drawerRef = ref(null);
const mobileToggleRef = ref(null);

onMounted(() => {
    try {
        const saved = localStorage.getItem('erp_sidebar_collapsed');
        if (saved !== null) {
            isSidebarCollapsed.value = saved === 'true';
        }
    } catch {
        // localStorage unavailable
    }
    window.addEventListener('keydown', handleDrawerKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleDrawerKeydown);
});

const user = computed(() => page.props.auth?.user || null);
const userInitials = computed(() => {
    if (!user.value?.name) return 'U';
    const parts = user.value.name.trim().split(/\s+/);
    if (parts.length >= 2) {
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return user.value.name.substring(0, 2).toUpperCase();
});

const filteredNavigationGroups = computed(() => {
    const permissions = page.props.auth?.permissions || [];
    return navigationGroups
        .map((group) => {
            const visibleItems = group.items.filter((item) => {
                if (item.available === false) return false;
                if (!item.permission) return true;
                return permissions.includes(item.permission);
            });
            return {
                ...group,
                items: visibleItems,
            };
        })
        .filter((group) => group.items.length > 0);
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

function toggleSidebar() {
    isSidebarCollapsed.value = !isSidebarCollapsed.value;
    try {
        localStorage.setItem('erp_sidebar_collapsed', String(isSidebarCollapsed.value));
    } catch {
        // localStorage unavailable
    }
}

function openMobileDrawer() {
    isMobileDrawerOpen.value = true;
    nextTick(() => {
        const focusable = drawerRef.value?.querySelectorAll(
            'button:not([disabled]), [href]:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        if (focusable && focusable.length > 0) {
            focusable[0].focus();
        }
    });
}

function closeMobileDrawer() {
    isMobileDrawerOpen.value = false;
    mobileToggleRef.value?.focus();
}

function handleDrawerKeydown(event) {
    if (!isMobileDrawerOpen.value) return;

    if (event.key === 'Escape') {
        event.preventDefault();
        closeMobileDrawer();
        return;
    }

    if (event.key === 'Tab') {
        const focusable = Array.from(
            drawerRef.value?.querySelectorAll(
                'button:not([disabled]), [href]:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
            ) || []
        );
        if (focusable.length === 0) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey) {
            if (document.activeElement === first) {
                event.preventDefault();
                last.focus();
            }
        } else {
            if (document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    }
}

function isItemActive(href) {
    if (!href || href === '#') return false;
    if (href === '/') return page.url === '/';
    return page.url.startsWith(href);
}
</script>

<template>
    <div class="min-h-screen bg-slate-50 dark:bg-slate-950 flex flex-col text-slate-800 dark:text-slate-100 transition-colors">
        <!-- Skip to content link -->
        <a
            href="#main-content"
            class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50 focus:px-4 focus:py-2 focus:bg-indigo-600 focus:text-white focus:rounded-lg focus:shadow-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-600"
        >
            Lewati ke konten
        </a>

        <!-- Mobile Header -->
        <header
            class="lg:hidden flex items-center justify-between px-4 py-3 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800"
        >
            <div class="flex items-center gap-3">
                <button
                    ref="mobileToggleRef"
                    type="button"
                    class="p-2 -ml-2 rounded-lg text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-400"
                    aria-label="Buka menu navigasi"
                    :aria-expanded="isMobileDrawerOpen"
                    aria-controls="mobile-sidebar-drawer"
                    @click="openMobileDrawer"
                >
                    <Menu class="w-6 h-6" aria-hidden="true" />
                </button>
                <div class="flex items-center gap-2">
                    <div
                        class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-bold text-base shadow-xs"
                    >
                        E
                    </div>
                    <span class="font-semibold text-slate-900 dark:text-white text-base"
                        >ERP Distribusi</span
                    >
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="p-2 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-400"
                    :aria-label="isDark ? 'Alihkan ke tema terang' : 'Alihkan ke tema gelap'"
                    @click="toggleTheme"
                >
                    <Sun v-if="isDark" class="w-5 h-5 text-amber-400" aria-hidden="true" />
                    <Moon v-else class="w-5 h-5 text-slate-600" aria-hidden="true" />
                </button>

                <div
                    class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-400 font-semibold flex items-center justify-center text-xs shadow-xs"
                    aria-hidden="true"
                >
                    {{ userInitials }}
                </div>
            </div>
        </header>

        <!-- Mobile Drawer Overlay -->
        <button
            v-if="isMobileDrawerOpen"
            type="button"
            aria-label="Tutup menu navigasi"
            class="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-xs lg:hidden transition-opacity cursor-default w-full h-full border-0 p-0 text-left"
            @click="closeMobileDrawer"
        />

        <!-- Mobile Drawer Sidebar -->
        <aside
            id="mobile-sidebar-drawer"
            ref="drawerRef"
            :class="[
                'fixed top-0 bottom-0 left-0 z-50 w-72 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 transform transition-transform duration-200 ease-in-out lg:hidden flex flex-col',
                isMobileDrawerOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
            aria-label="Navigasi Menu Mobile"
        >
            <div
                class="flex items-center justify-between px-4 py-4 border-b border-slate-200 dark:border-slate-800"
            >
                <div class="flex items-center gap-2.5">
                    <div
                        class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-bold text-base shadow-xs"
                    >
                        E
                    </div>
                    <span class="font-bold text-slate-900 dark:text-white text-base tracking-tight"
                        >ERP Distribusi</span
                    >
                </div>
                <button
                    type="button"
                    class="p-1 rounded-lg text-slate-500 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-400"
                    aria-label="Tutup menu navigasi"
                    @click="closeMobileDrawer"
                >
                    <X class="w-5 h-5" aria-hidden="true" />
                </button>
            </div>

            <nav class="flex-1 px-3 py-4 space-y-5 overflow-y-auto">
                <div v-for="group in filteredNavigationGroups" :key="group.title" class="space-y-1">
                    <div
                        v-if="group.items.length > 1"
                        class="px-3 text-2xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-1.5"
                    >
                        {{ group.title }}
                    </div>
                    <template v-for="item in group.items" :key="item.name">
                        <Link
                            v-if="!item.disabled"
                            :href="item.href"
                            :title="item.name"
                            :aria-label="item.name"
                            :class="[
                                'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-400',
                                isItemActive(item.href)
                                    ? 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-400 font-semibold'
                                    : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white',
                            ]"
                            @click="closeMobileDrawer"
                        >
                            <component
                                :is="iconMap[item.icon]"
                                class="w-5 h-5 shrink-0"
                                aria-hidden="true"
                            />
                            <span :aria-current="isItemActive(item.href) ? 'page' : undefined" class="truncate">{{ item.name }}</span>
                            <span v-if="isItemActive(item.href)" class="sr-only">(halaman aktif)</span>
                        </Link>
                        <div
                            v-else
                            class="flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium text-slate-400 dark:text-slate-500 cursor-not-allowed select-none"
                        >
                            <div class="flex items-center gap-3">
                                <component
                                    :is="iconMap[item.icon]"
                                    class="w-5 h-5 shrink-0 text-slate-400 dark:text-slate-500"
                                    aria-hidden="true"
                                />
                                <span>{{ item.name }}</span>
                            </div>
                            <Badge v-if="item.badge" variant="neutral" size="sm">
                                {{ item.badge }}
                            </Badge>
                        </div>
                    </template>
                </div>
            </nav>

            <!-- Mobile Drawer User Profile -->
            <div class="p-3 border-t border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 space-y-1">
                <div class="px-3 py-2">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white truncate">
                        {{ user?.name || 'Pengguna' }}
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 truncate">
                        {{ user?.email || '' }}
                    </p>
                </div>
                <Link
                    href="/account/password"
                    class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    @click="closeMobileDrawer"
                >
                    <KeyRound class="w-4 h-4 text-slate-500" aria-hidden="true" />
                    <span>Ubah Kata Sandi</span>
                </Link>
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors text-left cursor-pointer"
                    @click="closeMobileDrawer"
                >
                    <LogOut class="w-4 h-4 text-rose-500" aria-hidden="true" />
                    <span>Keluar</span>
                </Link>
            </div>
        </aside>

        <!-- Main Layout with Desktop Sidebar -->
        <div class="flex-1 flex overflow-hidden">
            <!-- Desktop Sidebar -->
            <aside
                :class="[
                    'hidden lg:flex flex-col bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 transition-[width] duration-200 ease-in-out shrink-0 select-none',
                    isSidebarCollapsed ? 'w-16' : 'w-64',
                ]"
                aria-label="Navigasi Menu Utama"
            >
                <!-- Brand Header -->
                <div
                    class="h-16 flex items-center justify-between px-4 border-b border-slate-200 dark:border-slate-800"
                >
                    <div
                        v-if="!isSidebarCollapsed"
                        class="flex items-center gap-2.5 overflow-hidden"
                    >
                        <div
                            class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-bold text-base shrink-0 shadow-xs"
                        >
                            E
                        </div>
                        <span
                            class="font-bold text-slate-900 dark:text-white text-base tracking-tight truncate"
                        >
                            ERP Distribusi
                        </span>
                    </div>
                    <div v-else class="w-full flex justify-center">
                        <div
                            class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-bold text-base shadow-xs"
                        >
                            E
                        </div>
                    </div>
                </div>

                <!-- Nav Groups & Items -->
                <nav class="flex-1 px-2 py-4 space-y-4 overflow-y-auto">
                    <div
                        v-for="group in filteredNavigationGroups"
                        :key="group.title"
                        :class="[isSidebarCollapsed ? 'space-y-1 pt-2 first:pt-0 border-t border-slate-100 dark:border-slate-800 first:border-0' : 'space-y-1']"
                    >
                        <div
                            v-if="!isSidebarCollapsed && group.items.length > 1"
                            class="px-2.5 text-2xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-1"
                        >
                            {{ group.title }}
                        </div>

                        <template v-for="item in group.items" :key="item.name">
                            <Link
                                v-if="!item.disabled"
                                :href="item.href"
                                :title="item.name"
                                :aria-label="item.name"
                                :class="[
                                    'flex items-center rounded-lg text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-400',
                                    isSidebarCollapsed
                                        ? 'justify-center p-2.5'
                                        : 'gap-3 px-3 py-2',
                                    isItemActive(item.href)
                                        ? 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-400 font-semibold'
                                        : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white',
                                ]"
                            >
                                <component
                                    :is="iconMap[item.icon]"
                                    class="w-5 h-5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span
                                    v-if="!isSidebarCollapsed"
                                    class="truncate"
                                    :aria-current="isItemActive(item.href) ? 'page' : undefined"
                                >{{ item.name }}</span>
                                <span
                                    v-else
                                    class="sr-only"
                                    :aria-current="isItemActive(item.href) ? 'page' : undefined"
                                >{{ item.name }}</span>
                                <span v-if="isItemActive(item.href)" class="sr-only">(halaman aktif)</span>
                            </Link>
                            <div
                                v-else
                                :class="[
                                    'flex items-center rounded-lg text-sm font-medium text-slate-400 dark:text-slate-500 cursor-not-allowed select-none',
                                    isSidebarCollapsed
                                        ? 'justify-center p-2.5'
                                        : 'justify-between px-3 py-2',
                                ]"
                                :title="
                                    isSidebarCollapsed
                                        ? `${item.name} (${item.badge || 'Segera'})`
                                        : undefined
                                "
                            >
                                <div
                                    :class="[
                                        'flex items-center',
                                        isSidebarCollapsed ? '' : 'gap-3 truncate',
                                    ]"
                                >
                                    <component
                                        :is="iconMap[item.icon]"
                                        class="w-5 h-5 shrink-0 text-slate-400 dark:text-slate-500"
                                        aria-hidden="true"
                                    />
                                    <span
                                        v-if="!isSidebarCollapsed"
                                        class="truncate"
                                    >{{ item.name }}</span>
                                </div>
                                <Badge
                                    v-if="!isSidebarCollapsed && item.badge"
                                    variant="neutral"
                                    size="sm"
                                >
                                    {{ item.badge }}
                                </Badge>
                            </div>
                        </template>
                    </div>
                </nav>

                <!-- Collapse Toggle -->
                <div class="p-2 border-t border-slate-200 dark:border-slate-800">
                    <button
                        type="button"
                        class="w-full flex items-center justify-center p-2 rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-400 transition-colors"
                        :aria-label="
                            isSidebarCollapsed
                                ? 'Perluas sidebar'
                                : 'Ciutkan sidebar'
                        "
                        :aria-expanded="!isSidebarCollapsed"
                        @click="toggleSidebar"
                    >
                        <ChevronRight
                            v-if="isSidebarCollapsed"
                            class="w-5 h-5"
                            aria-hidden="true"
                        />
                        <ChevronLeft
                            v-else
                            class="w-5 h-5"
                            aria-hidden="true"
                        />
                    </button>
                </div>
            </aside>

            <!-- Main Content Area -->
            <div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
                <!-- Desktop Top Bar -->
                <header
                    class="hidden lg:flex h-16 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 items-center justify-between px-6 sticky top-0 z-10"
                >
                    <!-- Left: Collapse toggle + Breadcrumb / Header slot -->
                    <div class="flex items-center gap-4 min-w-0">
                        <button
                            type="button"
                            class="p-2 rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-400"
                            :aria-label="isSidebarCollapsed ? 'Perluas sidebar' : 'Ciutkan sidebar'"
                            @click="toggleSidebar"
                        >
                            <PanelLeftOpen v-if="isSidebarCollapsed" class="w-5 h-5" aria-hidden="true" />
                            <PanelLeftClose v-else class="w-5 h-5" aria-hidden="true" />
                        </button>

                        <div class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300 min-w-0">
                            <slot name="breadcrumbs">
                                <slot name="header">
                                    <h1 class="text-base font-semibold text-slate-900 dark:text-white truncate">
                                        {{ title }}
                                    </h1>
                                </slot>
                            </slot>
                        </div>
                    </div>

                    <!-- Right: Theme Toggle & User Menu Dropdown -->
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            class="p-2 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-400"
                            :aria-label="isDark ? 'Alihkan ke tema terang' : 'Alihkan ke tema gelap'"
                            @click="toggleTheme"
                        >
                            <Sun v-if="isDark" class="w-5 h-5 text-amber-400" aria-hidden="true" />
                            <Moon v-else class="w-5 h-5 text-slate-600" aria-hidden="true" />
                        </button>

                        <!-- User Menu Dropdown -->
                        <Dropdown align="right" width="56">
                            <template #trigger="{ open, toggle }">
                                <button
                                    type="button"
                                    class="flex items-center gap-2.5 pl-2 py-1 pr-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 dark:focus-visible:ring-indigo-400 cursor-pointer transition-colors"
                                    aria-label="Menu akun pengguna"
                                    :aria-expanded="open"
                                    aria-haspopup="true"
                                    @click="toggle"
                                >
                                    <div
                                        class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-400 font-semibold flex items-center justify-center text-xs shadow-xs"
                                        aria-hidden="true"
                                    >
                                        {{ userInitials }}
                                    </div>
                                    <div class="text-left hidden sm:block">
                                        <div
                                            class="text-xs font-semibold text-slate-900 dark:text-white leading-tight"
                                        >
                                            {{ user?.name || 'Pengguna' }}
                                        </div>
                                        <div class="text-2xs text-slate-500 dark:text-slate-400 truncate max-w-36">
                                            {{ user?.email || '' }}
                                        </div>
                                    </div>
                                    <ChevronDown
                                        class="w-4 h-4 text-slate-400 transition-transform duration-200"
                                        :class="{ 'rotate-180': open }"
                                        aria-hidden="true"
                                    />
                                </button>
                            </template>

                            <template #default="{ close }">
                                <div class="px-4 py-2 border-b border-slate-100 dark:border-slate-800">
                                    <p class="text-2xs font-medium text-slate-400 dark:text-slate-500">
                                        Masuk sebagai
                                    </p>
                                    <p class="text-xs font-semibold text-slate-900 dark:text-white truncate">
                                        {{ user?.name || 'Pengguna' }}
                                    </p>
                                    <p class="text-2xs text-slate-500 dark:text-slate-400 truncate">
                                        {{ user?.email || '' }}
                                    </p>
                                </div>

                                <div class="py-1">
                                    <Link
                                        href="/account/password"
                                        class="flex items-center gap-2 px-4 py-2 text-xs font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white transition-colors"
                                        @click="close"
                                    >
                                        <KeyRound class="w-4 h-4 text-slate-400 shrink-0" aria-hidden="true" />
                                        <span>Ubah Kata Sandi</span>
                                    </Link>
                                </div>

                                <div class="border-t border-slate-100 dark:border-slate-800 my-1" />

                                <div class="py-1">
                                    <Link
                                        href="/logout"
                                        method="post"
                                        as="button"
                                        class="w-full flex items-center gap-2 px-4 py-2 text-xs font-medium text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors text-left cursor-pointer"
                                        @click="close"
                                    >
                                        <LogOut class="w-4 h-4 text-rose-500 shrink-0" aria-hidden="true" />
                                        <span>Keluar</span>
                                    </Link>
                                </div>
                            </template>
                        </Dropdown>
                    </div>
                </header>

                <!-- Page Content -->
                <main id="main-content" class="flex-1 p-4 sm:p-6 lg:p-8">
                    <div class="max-w-7xl mx-auto">
                        <slot />
                    </div>
                </main>
            </div>
        </div>

        <!-- Global Notifications & Dialogs -->
        <FlashRegion />
        <ConfirmDialog
            :show="confirmState.show"
            :title="confirmState.title"
            :message="confirmState.message"
            :confirm-text="confirmState.confirmText"
            :cancel-text="confirmState.cancelText"
            :variant="confirmState.variant"
            :requires-reason="confirmState.requiresReason"
            :reason-label="confirmState.reasonLabel"
            :reason-placeholder="confirmState.reasonPlaceholder"
            @confirm="onConfirm"
            @cancel="onCancel"
        />
    </div>
</template>
