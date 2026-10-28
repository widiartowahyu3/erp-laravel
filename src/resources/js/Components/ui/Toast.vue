<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import {
    CheckCircle2,
    AlertTriangle,
    AlertCircle,
    Info,
    X,
} from 'lucide-vue-next';
import IconButton from './IconButton.vue';

const props = defineProps({
    id: {
        type: [String, Number],
        required: true,
    },
    variant: {
        type: String,
        default: 'info',
        validator: (value) => ['success', 'error', 'warning', 'info'].includes(value),
    },
    title: {
        type: String,
        default: '',
    },
    message: {
        type: String,
        default: '',
    },
    duration: {
        type: Number,
        default: 5000,
    },
});

const emit = defineEmits(['dismiss']);

let timer = null;
let remaining = props.duration;
let startTime = null;
const isPaused = ref(false);
const toastRef = ref(null);

const iconMap = {
    success: CheckCircle2,
    error: AlertCircle,
    warning: AlertTriangle,
    info: Info,
};

const variantClasses = {
    success:
        'bg-white dark:bg-slate-900 border-emerald-500 text-slate-800 dark:text-slate-100',
    error:
        'bg-white dark:bg-slate-900 border-rose-500 text-slate-800 dark:text-slate-100',
    warning:
        'bg-white dark:bg-slate-900 border-amber-500 text-slate-800 dark:text-slate-100',
    info:
        'bg-white dark:bg-slate-900 border-sky-500 text-slate-800 dark:text-slate-100',
};

const iconClasses = {
    success: 'text-emerald-500',
    error: 'text-rose-500',
    warning: 'text-amber-500',
    info: 'text-sky-500',
};

function startTimer() {
    if (props.duration <= 0) return;
    startTime = Date.now();
    timer = setTimeout(() => {
        emit('dismiss', props.id);
    }, remaining);
}

function pauseTimer() {
    if (props.duration <= 0 || !timer) return;
    isPaused.value = true;
    clearTimeout(timer);
    remaining -= Date.now() - startTime;
}

function resumeTimer() {
    if (props.duration <= 0 || remaining <= 0) return;
    isPaused.value = false;
    startTimer();
}

onMounted(() => {
    startTimer();
    if (toastRef.value) {
        toastRef.value.addEventListener('mouseenter', pauseTimer);
        toastRef.value.addEventListener('mouseleave', resumeTimer);
    }
});

onUnmounted(() => {
    if (timer) clearTimeout(timer);
    if (toastRef.value) {
        toastRef.value.removeEventListener('mouseenter', pauseTimer);
        toastRef.value.removeEventListener('mouseleave', resumeTimer);
    }
});
</script>

<template>
    <div
        ref="toastRef"
        :role="variant === 'error' ? 'alert' : 'status'"
        :class="[
            'pointer-events-auto flex items-start gap-3 w-full max-w-sm p-4 rounded-xl shadow-lg border-l-4 border transition-all duration-200 border-slate-200 dark:border-slate-800',
            variantClasses[variant],
        ]"
    >
        <component
            :is="iconMap[variant]"
            :class="['w-5 h-5 shrink-0 mt-0.5', iconClasses[variant]]"
            aria-hidden="true"
        />
        <div class="flex-1 space-y-0.5">
            <h5 v-if="title" class="text-sm font-semibold leading-5">
                {{ title }}
            </h5>
            <p v-if="message" class="text-xs leading-relaxed text-slate-600 dark:text-slate-300">
                {{ message }}
            </p>
        </div>
        <IconButton
            type="button"
            variant="ghost"
            size="sm"
            aria-label="Tutup notifikasi"
            class="-mr-2 -mt-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
            @click="emit('dismiss', id)"
        >
            <X class="w-4 h-4" aria-hidden="true" />
        </IconButton>
    </div>
</template>
