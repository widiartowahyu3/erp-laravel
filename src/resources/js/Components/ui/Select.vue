<script setup>
import { ChevronDown } from 'lucide-vue-next';

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    options: {
        type: Array,
        default: () => [],
    },
    placeholder: {
        type: String,
        default: undefined,
    },
    id: {
        type: String,
        default: undefined,
    },
    name: {
        type: String,
        default: undefined,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    required: {
        type: Boolean,
        default: false,
    },
    invalid: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue', 'change', 'blur', 'focus']);
</script>

<template>
    <div class="relative w-full">
        <select
            :id="id"
            :name="name"
            :value="modelValue"
            :disabled="disabled"
            :required="required"
            :aria-invalid="invalid ? 'true' : 'false'"
            :class="[
                'w-full min-h-[40px] pl-3 pr-10 py-2 text-sm rounded-lg border appearance-none transition-colors cursor-pointer',
                'bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100',
                'focus-visible:outline-none focus-visible:ring-2',
                invalid
                    ? 'border-rose-500 focus-visible:ring-rose-500 bg-rose-50/20 dark:bg-rose-950/20'
                    : 'border-slate-300 dark:border-slate-700 focus-visible:border-indigo-600 focus-visible:ring-indigo-600',
                disabled
                    ? 'opacity-60 bg-slate-100 dark:bg-slate-800 cursor-not-allowed'
                    : '',
                !modelValue && placeholder
                    ? 'text-slate-400 dark:text-slate-500'
                    : '',
            ]"
            @change="emit('update:modelValue', $event.target.value); emit('change', $event)"
            @blur="emit('blur', $event)"
            @focus="emit('focus', $event)"
        >
            <option
                v-if="placeholder"
                value=""
                disabled
                :selected="!modelValue"
            >
                {{ placeholder }}
            </option>
            <option
                v-for="opt in options"
                :key="opt.value"
                :value="opt.value"
                :disabled="opt.disabled"
            >
                {{ opt.label }}
            </option>
            <slot />
        </select>
        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-slate-500 dark:text-slate-400">
            <ChevronDown class="w-4 h-4" aria-hidden="true" />
        </div>
    </div>
</template>
