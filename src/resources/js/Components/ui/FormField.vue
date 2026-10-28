<script setup>
import { computed } from 'vue';
import { AlertCircle } from 'lucide-vue-next';

let autoIdCounter = 0;

const props = defineProps({
    id: {
        type: String,
        default: undefined,
    },
    label: {
        type: String,
        default: '',
    },
    help: {
        type: String,
        default: '',
    },
    error: {
        type: String,
        default: '',
    },
    required: {
        type: Boolean,
        default: false,
    },
});

const generatedId = `form-field-${++autoIdCounter}`;
const fieldId = computed(() => props.id || generatedId);

const helpId = computed(() => (props.help ? `${fieldId.value}-help` : undefined));
const errorId = computed(() => (props.error ? `${fieldId.value}-error` : undefined));

const describedBy = computed(() => {
    const ids = [];
    if (helpId.value) ids.push(helpId.value);
    if (errorId.value) ids.push(errorId.value);
    return ids.length > 0 ? ids.join(' ') : undefined;
});
</script>

<template>
    <div class="space-y-1.5 w-full">
        <label
            v-if="label"
            :for="fieldId"
            class="block text-sm font-medium text-slate-700 dark:text-slate-300"
        >
            <span>{{ label }}</span>
            <span
                v-if="required"
                class="text-rose-500 ml-0.5"
                aria-hidden="true"
            >*</span>
            <span v-if="required" class="sr-only">(wajib diisi)</span>
        </label>

        <div>
            <slot
                :id="fieldId"
                :invalid="Boolean(error)"
                :described-by="describedBy"
            />
        </div>

        <p
            v-if="help && !error"
            :id="helpId"
            class="text-xs text-slate-500 dark:text-slate-400"
        >
            {{ help }}
        </p>

        <p
            v-if="error"
            :id="errorId"
            role="alert"
            class="text-xs text-rose-600 dark:text-rose-400 flex items-center gap-1.5 font-medium mt-1"
        >
            <AlertCircle class="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
            <span>{{ error }}</span>
        </p>
    </div>
</template>
