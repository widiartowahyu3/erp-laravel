<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Edit } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Breadcrumbs from '@/Components/ui/Breadcrumbs.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import Can from '@/Components/ui/Can.vue';

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    isUsed: {
        type: Boolean,
        default: false,
    },
    isMapped: {
        type: Boolean,
        default: false,
    },
});

const breadcrumbs = computed(() => [
    { label: 'Bagan Akun', href: '/accounting/accounts' },
    { label: props.item.code },
]);

const typeLabels = { asset: 'Aset', liability: 'Kewajiban', equity: 'Ekuitas', revenue: 'Pendapatan', expense: 'Beban' };
const balanceLabels = { debit: 'Debit', credit: 'Kredit' };
</script>

<template>
    <AppLayout :title="`Akun ${item.code}`">
        <Head :title="`Akun ${item.code}`" />

        <div class="space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <PageHeader :title="`${item.code} - ${item.name}`" description="Rincian akun pada bagan akun.">
                <template #actions>
                    <Can permission="chart_of_accounts.update">
                        <Link :href="`/accounting/accounts/${item.id}/edit`">
                            <Button variant="secondary" size="sm">
                                <Edit class="w-4 h-4 mr-1.5" aria-hidden="true" />
                                Ubah Akun
                            </Button>
                        </Link>
                    </Can>
                </template>
            </PageHeader>

            <Card>
                <template #header>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Informasi Akun</h2>
                </template>

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Tipe</dt>
                        <dd class="text-slate-800 dark:text-slate-200">{{ typeLabels[item.type] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Saldo Normal</dt>
                        <dd class="text-slate-800 dark:text-slate-200">{{ balanceLabels[item.normal_balance] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Akun Induk</dt>
                        <dd class="text-slate-800 dark:text-slate-200">
                            <Link
                                v-if="item.parent"
                                :href="`/accounting/accounts/${item.parent.id}`"
                                class="text-indigo-600 dark:text-indigo-400 hover:underline"
                            >
                                {{ item.parent.code }} - {{ item.parent.name }}
                            </Link>
                            <span v-else>-</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Jenis</dt>
                        <dd class="text-slate-800 dark:text-slate-200">{{ item.is_postable ? 'Akun detail' : 'Akun induk' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Status</dt>
                        <dd>
                            <Badge :variant="item.is_active ? 'success' : 'neutral'">
                                {{ item.is_active ? 'Aktif' : 'Nonaktif' }}
                            </Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Pemakaian</dt>
                        <dd class="text-slate-800 dark:text-slate-200">
                            {{ isUsed ? 'Sudah dipakai pada jurnal' : 'Belum dipakai pada jurnal' }}
                            <span v-if="isMapped">, dipetakan pada konfigurasi akuntansi</span>
                        </dd>
                    </div>
                </dl>
            </Card>
        </div>
    </AppLayout>
</template>
