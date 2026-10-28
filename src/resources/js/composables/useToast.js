import { ref } from 'vue';

let toastIdCounter = 0;
const toasts = ref([]);

function addToast({ variant = 'info', title = '', message = '', duration = 5000 }) {
    const id = `toast-${++toastIdCounter}`;
    toasts.value.push({
        id,
        variant,
        title,
        message,
        duration,
    });
    return id;
}

function removeToast(id) {
    toasts.value = toasts.value.filter((t) => t.id !== id);
}

function clearAll() {
    toasts.value = [];
}

export function useToast() {
    return {
        toasts,
        addToast,
        removeToast,
        clearAll,
        success: (message, title = 'Berhasil', duration = 5000) =>
            addToast({ variant: 'success', title, message, duration }),
        error: (message, title = 'Galat', duration = 5000) =>
            addToast({ variant: 'error', title, message, duration }),
        warning: (message, title = 'Peringatan', duration = 5000) =>
            addToast({ variant: 'warning', title, message, duration }),
        info: (message, title = 'Informasi', duration = 5000) =>
            addToast({ variant: 'info', title, message, duration }),
    };
}
