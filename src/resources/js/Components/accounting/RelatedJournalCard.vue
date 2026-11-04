<script setup>
import { Link } from '@inertiajs/vue3';
import Card from '@/Components/ui/Card.vue';
import Can from '@/Components/ui/Can.vue';
import StatusBadge from '@/Components/ui/StatusBadge.vue';

defineProps({
    journal: {
        type: Object,
        default: null,
    },
});
</script>

<template>
    <Can permission="journals.view">
        <Card>
            <template #header>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Jurnal Terkait</h2>
            </template>

            <div v-if="journal" class="flex items-center gap-2 text-sm">
                <Link
                    :href="`/accounting/journals/${journal.id}`"
                    class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                >
                    {{ journal.number }}
                </Link>
                <StatusBadge :status="journal.status" />
            </div>
            <p v-else class="text-sm text-slate-600 dark:text-slate-400">Belum ada jurnal</p>
        </Card>
    </Can>
</template>
