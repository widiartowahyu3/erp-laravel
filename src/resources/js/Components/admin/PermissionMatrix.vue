<script setup>
import { computed } from 'vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { clearModule, lockReason, selectModule, togglePermission } from '@/Support/permissionMatrix';

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => [],
    },
    modules: {
        type: Array,
        required: true,
    },
    grantable: {
        type: Array,
        default: () => [],
    },
    readonly: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

const selectedSet = computed(() => new Set(props.modelValue));

function columnsOf(module) {
    const columns = new Map();

    for (const resource of module.resources) {
        for (const action of resource.actions) {
            if (!columns.has(action.action)) {
                columns.set(action.action, action.label);
            }
        }
    }

    return [...columns].map(([action, label]) => ({ action, label }));
}

function actionOf(resource, action) {
    return resource.actions.find((candidate) => candidate.action === action) ?? null;
}

function selectedCount(module) {
    return module.resources.flatMap((resource) => resource.actions).filter((action) => selectedSet.value.has(action.name)).length;
}

function totalCount(module) {
    return module.resources.flatMap((resource) => resource.actions).length;
}

function reasonFor(resource, action) {
    return props.readonly ? '' : lockReason(resource, action, props.modelValue, props.grantable);
}

function isDisabled(resource, action) {
    return props.readonly || reasonFor(resource, action) !== '';
}

function inputId(name) {
    return `permission-${name.replace('.', '-')}`;
}

function onToggle(resource, action, event) {
    emit('update:modelValue', togglePermission(props.modelValue, resource, action, event.target.checked));
}

function onSelectAll(module) {
    emit('update:modelValue', selectModule(props.modelValue, module, props.grantable));
}

function onClear(module) {
    emit('update:modelValue', clearModule(props.modelValue, module, props.grantable));
}
</script>

<template>
    <div class="space-y-4">
        <Card v-for="module in modules" :key="module.key">
            <template #header>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ module.label }}</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ selectedCount(module) }} dari {{ totalCount(module) }} izin dipilih
                        </p>
                    </div>
                    <div v-if="!readonly" class="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            :aria-label="`Pilih semua izin modul ${module.label}`"
                            @click="onSelectAll(module)"
                        >
                            Pilih Semua
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :aria-label="`Kosongkan izin modul ${module.label}`"
                            @click="onClear(module)"
                        >
                            Kosongkan
                        </Button>
                    </div>
                </div>
            </template>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">Izin modul {{ module.label }} per sumber dan aksi</caption>
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-700">
                            <th scope="col" class="py-2 pr-4 text-left text-xs font-semibold text-slate-600 dark:text-slate-300">Sumber</th>
                            <th
                                v-for="column in columnsOf(module)"
                                :key="column.action"
                                scope="col"
                                class="px-3 py-2 text-center text-xs font-semibold text-slate-600 dark:text-slate-300"
                            >
                                {{ column.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="resource in module.resources"
                            :key="resource.key"
                            class="border-b border-slate-100 last:border-0 dark:border-slate-800"
                        >
                            <th scope="row" class="py-2 pr-4 text-left font-medium text-slate-800 dark:text-slate-200">
                                {{ resource.label }}
                            </th>
                            <td v-for="column in columnsOf(module)" :key="column.action" class="px-3 py-2 text-center">
                                <input
                                    v-if="actionOf(resource, column.action)"
                                    :id="inputId(actionOf(resource, column.action).name)"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-2 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600"
                                    :checked="selectedSet.has(actionOf(resource, column.action).name)"
                                    :disabled="isDisabled(resource, actionOf(resource, column.action))"
                                    :title="reasonFor(resource, actionOf(resource, column.action))"
                                    :aria-label="`${actionOf(resource, column.action).label} ${resource.label}`"
                                    @change="onToggle(resource, actionOf(resource, column.action), $event)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>
</template>
