import { ref } from 'vue';

const state = ref({
    show: false,
    title: 'Konfirmasi Tindakan',
    message: 'Apakah Anda yakin ingin melanjutkan tindakan ini?',
    confirmText: 'Ya, Lanjutkan',
    cancelText: 'Batal',
    variant: 'primary',
    requiresReason: false,
    reasonLabel: 'Alasan',
    reasonPlaceholder: 'Masukkan alasan...',
    resolve: null,
});

export function useConfirm() {
    function confirm(options = {}) {
        return new Promise((resolve) => {
            state.value = {
                show: true,
                title: options.title || 'Konfirmasi Tindakan',
                message: options.message || 'Apakah Anda yakin ingin melanjutkan tindakan ini?',
                confirmText: options.confirmText || 'Ya, Lanjutkan',
                cancelText: options.cancelText || 'Batal',
                variant: options.variant || 'primary',
                requiresReason: Boolean(options.requiresReason),
                reasonLabel: options.reasonLabel || 'Alasan',
                reasonPlaceholder: options.reasonPlaceholder || 'Masukkan alasan...',
                resolve,
            };
        });
    }

    function onConfirm(reason) {
        if (state.value.resolve) {
            if (state.value.requiresReason) {
                state.value.resolve({ confirmed: true, reason });
            } else {
                state.value.resolve(true);
            }
        }
        state.value.show = false;
    }

    function onCancel() {
        if (state.value.resolve) {
            if (state.value.requiresReason) {
                state.value.resolve({ confirmed: false, reason: null });
            } else {
                state.value.resolve(false);
            }
        }
        state.value.show = false;
    }

    return {
        confirm,
        state,
        onConfirm,
        onCancel,
    };
}
