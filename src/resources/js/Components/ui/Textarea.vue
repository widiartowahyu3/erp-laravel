<script setup>
import { ref } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    id: {
        type: String,
        default: undefined,
    },
    name: {
        type: String,
        default: undefined,
    },
    placeholder: {
        type: String,
        default: undefined,
    },
    rows: {
        type: Number,
        default: 3,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    readonly: {
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
    maxLength: {
        type: Number,
        default: undefined,
    },
    autoResize: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue', 'blur', 'focus', 'change']);

const textareaRef = ref(null);

function focus() {
    textareaRef.value?.focus();
}

defineExpose({
    focus,
    textareaRef,
});
</script>

<template>
    <div class="w-full space-y-1">
        <textarea
            :id="id"
            ref="textareaRef"
            :name="name"
            :rows="rows"
            :value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            :readonly="readonly"
            :required="required"
            :maxlength="maxLength"
            :aria-invalid="invalid ? 'true' : 'false'"
            :class="[
                'w-full px-3 py-2 text-sm rounded-lg border transition-colors min-h-[40px]',
                autoResize ? 'field-sizing-content resize-none' : 'resize-y',
                'bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500',
                'focus-visible:outline-none focus-visible:ring-2',
                invalid
                    ? 'border-rose-500 focus-visible:ring-rose-500 bg-rose-50/20 dark:bg-rose-950/20'
                    : 'border-slate-300 dark:border-slate-700 focus-visible:border-indigo-600 focus-visible:ring-indigo-600',
                disabled
                    ? 'opacity-60 bg-slate-100 dark:bg-slate-800 cursor-not-allowed'
                    : '',
                readonly
                    ? 'bg-slate-50 dark:bg-slate-800/60 cursor-default'
                    : '',
            ]"
            @input="emit('update:modelValue', $event.target.value)"
            @blur="emit('blur', $event)"
            @focus="emit('focus', $event)"
            @change="emit('change', $event)"
        />
        <div
            v-if="maxLength !== undefined"
            class="flex justify-end text-xs text-slate-500 dark:text-slate-400"
        >
            <span>{{ (modelValue || '').length }} / {{ maxLength }}</span>
        </div>
    </div>
</template>
