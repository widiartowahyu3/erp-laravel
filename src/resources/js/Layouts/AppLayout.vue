<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    ShoppingCart,
    ShoppingBag,
    Boxes,
    Wallet,
    Database,
    Menu,
    X,
    ChevronLeft,
    ChevronRight,
    User,
    ShieldCheck,
} from 'lucide-vue-next';
import { navigationItems } from '../config/navigation.js';

const props = defineProps({
    title: {
        type: String,
        default: 'Dashboard',
    },
});

const isSidebarCollapsed = ref(false);
const isMobileDrawerOpen = ref(false);

const iconMap = {
    LayoutDashboard,
    ShoppingCart,
    ShoppingBag,
    Boxes,
    Wallet,
    Database,
};

function toggleSidebar() {
    isSidebarCollapsed.value = !isSidebarCollapsed.value;
}

function toggleMobileDrawer() {
    isMobileDrawerOpen.value = !isMobileDrawerOpen.value;
}

function closeMobileDrawer() {
    isMobileDrawerOpen.value = false;
}
</script>

<template>
    <div class="min-h-screen bg-slate-50 flex flex-col text-slate-800">
        <!-- Mobile Header -->
        <header
            class="lg:hidden flex items-center justify-between px-4 py-3 bg-white border-b border-slate-200"
        >
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    class="p-2 -ml-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600"
                    aria-label="Buka menu navigasi"
                    @click="toggleMobileDrawer"
                >
                    <Menu class="w-6 h-6" aria-hidden="true" />
                </button>
                <div class="flex items-center gap-2">
                    <div
                        class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-bold text-base"
                    >
                        E
                    </div>
                    <span class="font-semibold text-slate-900 text-lg"
                        >ERP Distribusi</span
                    >
                </div>
            </div>
            <div class="flex items-center gap-2">
                <div
                    class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center text-slate-600"
                    aria-label="Profil Pengguna"
                >
                    <User class="w-4 h-4" aria-hidden="true" />
                </div>
            </div>
        </header>

        <!-- Mobile Drawer Overlay -->
        <div
            v-if="isMobileDrawerOpen"
            class="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-xs lg:hidden transition-opacity"
            @click="closeMobileDrawer"
        />

        <!-- Mobile Drawer Sidebar -->
        <aside
            :class="[
                'fixed top-0 bottom-0 left-0 z-50 w-64 bg-white border-r border-slate-200 transform transition-transform duration-200 ease-in-out lg:hidden flex flex-col',
                isMobileDrawerOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
            aria-label="Navigasi Menu Mobile"
        >
            <div
                class="flex items-center justify-between px-4 py-4 border-b border-slate-200"
            >
                <div class="flex items-center gap-2">
                    <div
                        class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-bold text-base"
                    >
                        E
                    </div>
                    <span class="font-semibold text-slate-900 text-base"
                        >ERP Distribusi</span
                    >
                </div>
                <button
                    type="button"
                    class="p-1 rounded-lg text-slate-500 hover:text-slate-900 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600"
                    aria-label="Tutup menu navigasi"
                    @click="closeMobileDrawer"
                >
                    <X class="w-5 h-5" aria-hidden="true" />
                </button>
            </div>

            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                <template v-for="item in navigationItems" :key="item.name">
                    <Link
                        v-if="!item.disabled"
                        :href="item.href"
                        :class="[
                            'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600',
                            item.active
                                ? 'bg-indigo-50 text-indigo-700'
                                : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900',
                        ]"
                        @click="closeMobileDrawer"
                    >
                        <component
                            :is="iconMap[item.icon]"
                            class="w-5 h-5 shrink-0"
                            aria-hidden="true"
                        />
                        <span>{{ item.name }}</span>
                    </Link>
                    <div
                        v-else
                        class="flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium text-slate-400 cursor-not-allowed select-none"
                    >
                        <div class="flex items-center gap-3">
                            <component
                                :is="iconMap[item.icon]"
                                class="w-5 h-5 shrink-0 text-slate-400"
                                aria-hidden="true"
                            />
                            <span>{{ item.name }}</span>
                        </div>
                        <span
                            v-if="item.badge"
                            class="px-2 py-0.5 text-xs font-medium rounded-full bg-slate-100 text-slate-600"
                        >
                            {{ item.badge }}
                        </span>
                    </div>
                </template>
            </nav>
        </aside>

        <!-- Main Layout with Desktop Sidebar -->
        <div class="flex-1 flex overflow-hidden">
            <!-- Desktop Sidebar -->
            <aside
                :class="[
                    'hidden lg:flex flex-col bg-white border-r border-slate-200 transition-all duration-200 shrink-0 select-none',
                    isSidebarCollapsed ? 'w-16' : 'w-60',
                ]"
                aria-label="Navigasi Menu Utama"
            >
                <!-- Brand Header -->
                <div
                    class="h-16 flex items-center justify-between px-4 border-b border-slate-200"
                >
                    <div
                        v-if="!isSidebarCollapsed"
                        class="flex items-center gap-2 overflow-hidden"
                    >
                        <div
                            class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-bold text-base shrink-0"
                        >
                            E
                        </div>
                        <span
                            class="font-bold text-slate-900 text-base tracking-tight truncate"
                            >ERP Distribusi</span
                        >
                    </div>
                    <div v-else class="w-full flex justify-center">
                        <div
                            class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-bold text-base"
                        >
                            E
                        </div>
                    </div>
                </div>

                <!-- Nav Items -->
                <nav class="flex-1 px-2 py-4 space-y-1 overflow-y-auto">
                    <template v-for="item in navigationItems" :key="item.name">
                        <Link
                            v-if="!item.disabled"
                            :href="item.href"
                            :class="[
                                'flex items-center rounded-lg text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600',
                                isSidebarCollapsed
                                    ? 'justify-center p-2'
                                    : 'gap-3 px-3 py-2',
                                item.active
                                    ? 'bg-indigo-50 text-indigo-700 font-semibold'
                                    : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900',
                            ]"
                            :title="isSidebarCollapsed ? item.name : undefined"
                            :aria-label="item.name"
                        >
                            <component
                                :is="iconMap[item.icon]"
                                class="w-5 h-5 shrink-0"
                                aria-hidden="true"
                            />
                            <span v-if="!isSidebarCollapsed" class="truncate">{{
                                item.name
                            }}</span>
                        </Link>
                        <div
                            v-else
                            :class="[
                                'flex items-center rounded-lg text-sm font-medium text-slate-400 cursor-not-allowed select-none',
                                isSidebarCollapsed
                                    ? 'justify-center p-2'
                                    : 'justify-between px-3 py-2',
                            ]"
                            :title="
                                isSidebarCollapsed
                                    ? `${item.name} (${item.badge || 'Segera'})`
                                    : undefined
                            "
                            :aria-label="`${item.name} (Segera)`"
                        >
                            <div
                                :class="[
                                    'flex items-center',
                                    isSidebarCollapsed ? '' : 'gap-3 truncate',
                                ]"
                            >
                                <component
                                    :is="iconMap[item.icon]"
                                    class="w-5 h-5 shrink-0 text-slate-400"
                                    aria-hidden="true"
                                />
                                <span
                                    v-if="!isSidebarCollapsed"
                                    class="truncate"
                                    >{{ item.name }}</span
                                >
                            </div>
                            <span
                                v-if="!isSidebarCollapsed && item.badge"
                                class="px-2 py-0.5 text-xs font-medium rounded-full bg-slate-100 text-slate-600 shrink-0"
                            >
                                {{ item.badge }}
                            </span>
                        </div>
                    </template>
                </nav>

                <!-- Collapse Toggle -->
                <div class="p-2 border-t border-slate-200">
                    <button
                        type="button"
                        class="w-full flex items-center justify-center p-2 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 transition-colors"
                        :aria-label="
                            isSidebarCollapsed
                                ? 'Perluas sidebar'
                                : 'Ciutkan sidebar'
                        "
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
                    class="hidden lg:flex h-16 bg-white border-b border-slate-200 items-center justify-between px-8 sticky top-0 z-10"
                >
                    <!-- Breadcrumb Slot -->
                    <div class="flex items-center gap-2 text-sm text-slate-600">
                        <slot name="breadcrumb">
                            <span class="font-medium text-slate-900">{{
                                title
                            }}</span>
                        </slot>
                    </div>

                    <!-- User Placeholder Info -->
                    <div class="flex items-center gap-4">
                        <div
                            class="flex items-center gap-3 pl-3 border-l border-slate-200"
                        >
                            <div
                                class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 font-semibold flex items-center justify-center text-sm"
                                aria-label="Avatar Pengguna Demo"
                            >
                                PD
                            </div>
                            <div class="text-left">
                                <div
                                    class="text-sm font-semibold text-slate-900 leading-tight"
                                >
                                    Pengguna Demo
                                </div>
                                <div class="text-xs text-slate-500">
                                    Administrator
                                </div>
                            </div>
                        </div>
                    </div>
                </header>

                <!-- Page Content -->
                <main class="flex-1 p-4 sm:p-6 lg:p-8">
                    <div class="max-w-7xl mx-auto">
                        <slot />
                    </div>
                </main>
            </div>
        </div>
    </div>
</template>
