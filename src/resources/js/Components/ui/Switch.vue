<script setup>
const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    label: {
        type: String,
        default: '',
    },
    description: {
        type: String,
        default: '',
    },
    id: {
        type: String,
        default: undefined,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue', 'change']);

function toggle() {
    if (props.disabled) return;
    const next = !props.modelValue;
    emit('update:modelValue', next);
    emit('change', next);
}

function handleKeydown(event) {
    if (event.key === ' ' || event.key === 'Enter') {
        event.preventDefault();
        toggle();
    }
}
</script>

<template>
    <div
        :class="[
            'min-h-[40px] inline-flex items-center gap-3 select-none py-1.5',
            disabled ? 'opacity-60 cursor-not-allowed pointer-events-none' : '',
        ]"
    >
        <button
            :id="id"
            type="button"
            role="switch"
            :aria-checked="modelValue"
            :aria-label="label || 'Toggle switch'"
            :disabled="disabled"
            :class="[
                'relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors ease-in-out duration-200 cursor-pointer',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900',
                modelValue ? 'bg-indigo-600' : 'bg-slate-200 dark:bg-slate-700',
            ]"
            @click="toggle"
            @keydown="handleKeydown"
        >
            <span
                aria-hidden="true"
                :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-xs ring-0 transition ease-in-out duration-200',
                    modelValue ? 'translate-x-5' : 'translate-x-0',
                ]"
            />
        </button>
        <div v-if="label || description" class="text-sm">
            <span
                v-if="label"
                class="font-medium text-slate-800 dark:text-slate-200 block"
            >
                {{ label }}
            </span>
            <span
                v-if="description"
                class="text-xs text-slate-500 dark:text-slate-400 block"
            >
                {{ description }}
            </span>
        </div>
        <slot />
    </div>
</template>
