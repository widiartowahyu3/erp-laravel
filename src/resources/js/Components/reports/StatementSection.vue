<script setup>
import Card from '@/Components/ui/Card.vue';
import Rupiah from '@/Components/ui/Rupiah.vue';

defineProps({
    // A statement section: label, accounts (code, name, amount), total, and optionally current_earnings.
    section: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Card class="report-section">
        <template #header>
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ section.label }}</h2>
        </template>

        <div class="report-table overflow-x-auto print:overflow-visible">
            <table class="w-full text-sm">
                <thead class="sr-only">
                    <tr>
                        <th scope="col">Kode</th>
                        <th scope="col">Akun</th>
                        <th scope="col">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="section.accounts.length === 0 && section.current_earnings === undefined">
                        <td colspan="3" class="py-2 text-slate-500 dark:text-slate-400">Tidak ada mutasi.</td>
                    </tr>
                    <tr v-for="account in section.accounts" :key="account.code" class="border-b border-slate-100 dark:border-slate-800">
                        <td class="w-20 py-2 pr-3 font-mono text-xs">{{ account.code }}</td>
                        <td class="py-2 pr-3">{{ account.name }}</td>
                        <td class="py-2 text-right"><Rupiah :value="account.amount" /></td>
                    </tr>
                    <tr v-if="section.current_earnings !== undefined" class="border-b border-slate-100 dark:border-slate-800">
                        <td class="w-20 py-2 pr-3"></td>
                        <td class="py-2 pr-3">Laba Berjalan</td>
                        <td class="py-2 text-right"><Rupiah :value="section.current_earnings" /></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-slate-300 font-semibold dark:border-slate-600">
                        <td class="py-2 pr-3"></td>
                        <td class="py-2 pr-3">Total {{ section.label }}</td>
                        <td class="py-2 text-right"><Rupiah :value="section.total" /></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </Card>
</template>
