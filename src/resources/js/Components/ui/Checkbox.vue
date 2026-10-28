<script setup>
import { ref, watch, onMounted, computed } from 'vue';
import { Check, Minus } from 'lucide-vue-next';

const props = defineProps({
    modelValue: {
        type: [Boolean, Array],
        default: false,
    },
    value: {
        type: [String, Number, Boolean, Object],
        default: undefined,
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
    name: {
        type: String,
        default: undefined,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    indeterminate: {
        type: Boolean,
        default: false,
    },
    invalid: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue', 'change']);

const inputRef = ref(null);

const isChecked = computed(() => {
    if (Array.isArray(props.modelValue)) {
        return props.modelValue.includes(props.value);
    }
    return Boolean(props.modelValue);
});

function syncIndeterminate() {
    if (inputRef.value) {
        inputRef.value.indeterminate = Boolean(props.indeterminate);
    }
}

watch(() => props.indeterminate, syncIndeterminate);
onMounted(syncIndeterminate);

function handleChange(event) {
    if (Array.isArray(props.modelValue)) {
        const set = new Set(props.modelValue);
        if (event.target.checked) {
            set.add(props.value);
        } else {
            set.delete(props.value);
        }
        const updated = Array.from(set);
        emit('update:modelValue', updated);
        emit('change', updated);
    } else {
        const checked = event.target.checked;
        emit('update:modelValue', checked);
        emit('change', checked);
    }
}
</script>

<template>
    <label
        :for="id"
        :class="[
            'min-h-[40px] inline-flex items-start gap-3 select-none py-2 cursor-pointer',
            disabled ? 'opacity-60 cursor-not-allowed pointer-events-none' : '',
        ]"
    >
        <div class="relative flex items-center justify-center shrink-0 mt-0.5">
            <input
                :id="id"
                ref="inputRef"
                type="checkbox"
                :name="name"
                :checked="isChecked"
                :disabled="disabled"
                :aria-invalid="invalid ? 'true' : 'false'"
                class="sr-only peer"
                @change="handleChange"
            />
            <div
                :class="[
                    'w-5 h-5 rounded-md border flex items-center justify-center transition-colors',
                    'peer-focus-visible:ring-2 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-indigo-600 dark:peer-focus-visible:ring-offset-slate-900',
                    isChecked || indeterminate
                        ? 'bg-indigo-600 border-indigo-600 text-white'
                        : 'bg-white dark:bg-slate-900 border-slate-300 dark:border-slate-700',
                    invalid ? 'border-rose-500' : '',
                ]"
                aria-hidden="true"
            >
                <Minus v-if="indeterminate" class="w-3.5 h-3.5 stroke-[3]" />
                <Check v-else-if="isChecked" class="w-3.5 h-3.5 stroke-[3]" />
            </div>
        </div>
        <div v-if="label || description" class="text-sm">
            <span
                v-if="label"
                class="font-medium text-slate-800 dark:text-slate-200 block"
            >
                {{ label }}
            </span>
            <span
                v-if="description"
                class="text-xs text-slate-500 dark:text-slate-400 block mt-0.5"
            >
                {{ description }}
            </span>
        </div>
        <slot />
    </label>
</template>
