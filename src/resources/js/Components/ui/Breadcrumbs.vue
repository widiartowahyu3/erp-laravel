<script setup>
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Home } from 'lucide-vue-next';

defineProps({
    items: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <nav aria-label="Breadcrumb">
        <ol class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
            <li class="inline-flex items-center">
                <Link
                    href="/"
                    class="inline-flex items-center gap-1 hover:text-slate-800 dark:hover:text-slate-200 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 rounded-sm"
                >
                    <Home class="w-3.5 h-3.5" aria-hidden="true" />
                    <span>Beranda</span>
                </Link>
            </li>

            <li
                v-for="(item, index) in items"
                :key="index"
                class="inline-flex items-center gap-1.5"
            >
                <ChevronRight class="w-3.5 h-3.5 text-slate-400 dark:text-slate-500 shrink-0" aria-hidden="true" />
                <Link
                    v-if="item.href && index !== items.length - 1"
                    :href="item.href"
                    class="hover:text-slate-800 dark:hover:text-slate-200 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 rounded-sm"
                >
                    {{ item.label }}
                </Link>
                <span
                    v-else
                    aria-current="page"
                    class="font-semibold text-slate-800 dark:text-slate-100 truncate max-w-xs"
                >
                    {{ item.label }}
                </span>
            </li>
        </ol>
    </nav>
</template>
