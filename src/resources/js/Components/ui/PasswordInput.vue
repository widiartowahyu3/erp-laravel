<script setup>
import { ref } from 'vue';
import { Eye, EyeOff } from 'lucide-vue-next';
import IconButton from './IconButton.vue';

const props = defineProps({
    modelValue: {
        type: [String, Number],
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
    autocomplete: {
        type: String,
        default: 'current-password',
    },
});

const emit = defineEmits(['update:modelValue', 'blur', 'focus', 'change']);

const showPassword = ref(false);
const inputRef = ref(null);

function toggleShowPassword() {
    showPassword.value = !showPassword.value;
}

function focus() {
    inputRef.value?.focus();
}

defineExpose({
    focus,
    inputRef,
});
</script>

<template>
    <div class="relative w-full">
        <input
            :id="id"
            ref="inputRef"
            :type="showPassword ? 'text' : 'password'"
            :name="name"
            :value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            :readonly="readonly"
            :required="required"
            :autocomplete="autocomplete"
            :aria-invalid="invalid ? 'true' : 'false'"
            :class="[
                'w-full min-h-[40px] pl-3 pr-11 py-2 text-sm rounded-lg border transition-colors',
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
        <div class="absolute inset-y-0 right-0 flex items-center pr-1">
            <IconButton
                type="button"
                variant="ghost"
                size="sm"
                :disabled="disabled"
                :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                tabindex="-1"
                @click="toggleShowPassword"
            >
                <EyeOff
                    v-if="showPassword"
                    class="w-4 h-4 text-slate-500 dark:text-slate-400"
                    aria-hidden="true"
                />
                <Eye
                    v-else
                    class="w-4 h-4 text-slate-500 dark:text-slate-400"
                    aria-hidden="true"
                />
            </IconButton>
        </div>
    </div>
</template>
