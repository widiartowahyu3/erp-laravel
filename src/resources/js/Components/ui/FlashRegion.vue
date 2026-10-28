<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Toast from './Toast.vue';

let toastCounter = 0;
const toasts = ref([]);

function addToast(toast) {
    const id = `toast-${++toastCounter}`;
    toasts.value.push({
        id,
        duration: 5000,
        ...toast,
    });
}

function removeToast(id) {
    toasts.value = toasts.value.filter((t) => t.id !== id);
}

const page = usePage();

watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) return;
        if (flash.success) {
            addToast({ variant: 'success', title: 'Berhasil', message: flash.success });
        }
        if (flash.error) {
            addToast({ variant: 'error', title: 'Galat', message: flash.error });
        }
        if (flash.warning) {
            addToast({ variant: 'warning', title: 'Peringatan', message: flash.warning });
        }
        if (flash.info) {
            addToast({ variant: 'info', title: 'Informasi', message: flash.info });
        }
    },
    { deep: true, immediate: true }
);

defineExpose({
    addToast,
    removeToast,
    toasts,
});
</script>

<template>
    <div
        aria-live="polite"
        aria-atomic="true"
        class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 max-w-sm w-full pointer-events-none"
    >
        <TransitionGroup
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="transform translate-y-2 opacity-0"
            enter-to-class="transform translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="transform translate-y-2 opacity-0"
        >
            <Toast
                v-for="toast in toasts"
                :id="toast.id"
                :key="toast.id"
                :variant="toast.variant"
                :title="toast.title"
                :message="toast.message"
                :duration="toast.duration"
                @dismiss="removeToast"
            />
        </TransitionGroup>
    </div>
</template>
