<script setup>
import { ref, watch } from 'vue';
import Modal from './Modal.vue';
import Button from './Button.vue';
import FormField from './FormField.vue';
import Textarea from './Textarea.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: 'Konfirmasi Tindakan',
    },
    message: {
        type: String,
        default: 'Apakah Anda yakin ingin melanjutkan tindakan ini?',
    },
    confirmText: {
        type: String,
        default: 'Ya, Lanjutkan',
    },
    cancelText: {
        type: String,
        default: 'Batal',
    },
    variant: {
        type: String,
        default: 'primary',
        validator: (value) => ['primary', 'danger'].includes(value),
    },
    loading: {
        type: Boolean,
        default: false,
    },
    requiresReason: {
        type: Boolean,
        default: false,
    },
    reasonLabel: {
        type: String,
        default: 'Alasan Pembatalan / Void',
    },
    reasonPlaceholder: {
        type: String,
        default: 'Jelaskan alasan tindakan ini...',
    },
});

const emit = defineEmits(['confirm', 'cancel', 'close']);

const reason = ref('');

watch(
    () => props.show,
    (isOpen) => {
        if (isOpen) {
            reason.value = '';
        }
    }
);

function handleConfirm() {
    emit('confirm', props.requiresReason ? reason.value.trim() : undefined);
}

function handleCancel() {
    emit('cancel');
    emit('close');
}
</script>

<template>
    <Modal
        :show="show"
        :title="title"
        max-width="md"
        @close="handleCancel"
    >
        <div class="space-y-4">
            <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                {{ message }}
            </p>

            <FormField
                v-if="requiresReason"
                :label="reasonLabel"
                required
            >
                <template #default="{ id, invalid }">
                    <Textarea
                        :id="id"
                        v-model="reason"
                        :placeholder="reasonPlaceholder"
                        :invalid="invalid"
                        :rows="3"
                        required
                    />
                </template>
            </FormField>
        </div>

        <template #footer>
            <Button
                variant="secondary"
                :disabled="loading"
                @click="handleCancel"
            >
                {{ cancelText }}
            </Button>
            <Button
                :variant="variant === 'danger' ? 'danger' : 'primary'"
                :loading="loading"
                :disabled="loading || (requiresReason && !reason.trim())"
                @click="handleConfirm"
            >
                {{ confirmText }}
            </Button>
        </template>
    </Modal>
</template>
