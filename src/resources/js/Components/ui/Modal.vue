<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { X } from 'lucide-vue-next';
import IconButton from './IconButton.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '',
    },
    description: {
        type: String,
        default: '',
    },
    maxWidth: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg', 'xl', '2xl'].includes(value),
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['close']);

const modalRef = ref(null);
let previousActiveElement = null;

const maxWidthClasses = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
    '2xl': 'max-w-2xl',
};

function handleClose() {
    if (props.closeable) {
        emit('close');
    }
}

function handleKeydown(event) {
    if (!props.show) return;

    if (event.key === 'Escape') {
        handleClose();
        return;
    }

    if (event.key === 'Tab' && modalRef.value) {
        const focusables = modalRef.value.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        if (focusables.length === 0) return;

        const first = focusables[0];
        const last = focusables[focusables.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
}

watch(
    () => props.show,
    (isOpen) => {
        if (isOpen) {
            previousActiveElement = document.activeElement;
            document.body.classList.add('overflow-hidden');
            window.addEventListener('keydown', handleKeydown);
            nextTick(() => {
                if (modalRef.value) {
                    const focusable = modalRef.value.querySelector(
                        'button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
                    );
                    focusable?.focus();
                }
            });
        } else {
            document.body.classList.remove('overflow-hidden');
            window.removeEventListener('keydown', handleKeydown);
            if (previousActiveElement && typeof previousActiveElement.focus === 'function') {
                previousActiveElement.focus();
            }
        }
    }
);

onMounted(() => {
    if (props.show) {
        document.body.classList.add('overflow-hidden');
        window.addEventListener('keydown', handleKeydown);
    }
});

onUnmounted(() => {
    document.body.classList.remove('overflow-hidden');
    window.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="show"
            class="fixed inset-0 z-50 overflow-y-auto"
            role="dialog"
            aria-modal="true"
            :aria-label="title || 'Dialog'"
        >
            <!-- Backdrop -->
            <button
                type="button"
                tabindex="-1"
                aria-label="Tutup dialog"
                class="fixed inset-0 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-xs transition-opacity w-full h-full border-0 cursor-default p-0"
                @click="handleClose"
            />

            <!-- Modal Content Wrapper -->
            <div class="min-h-full flex items-center justify-center p-4 text-center sm:p-0 pointer-events-none">
                <div
                    ref="modalRef"
                    :class="[
                        'relative w-full rounded-2xl bg-white dark:bg-slate-900 text-left shadow-xl transition-all pointer-events-auto border border-slate-200 dark:border-slate-800 my-8 overflow-hidden',
                        maxWidthClasses[maxWidth],
                    ]"
                >
                    <!-- Header -->
                    <div
                        v-if="title || $slots.header || closeable"
                        class="px-6 pt-5 pb-4 border-b border-slate-100 dark:border-slate-800 flex items-start justify-between gap-4"
                    >
                        <div class="space-y-1">
                            <slot name="header">
                                <h3
                                    v-if="title"
                                    class="text-lg font-semibold text-slate-900 dark:text-slate-100"
                                >
                                    {{ title }}
                                </h3>
                                <p
                                    v-if="description"
                                    class="text-sm text-slate-500 dark:text-slate-400"
                                >
                                    {{ description }}
                                </p>
                            </slot>
                        </div>
                        <IconButton
                            v-if="closeable"
                            variant="ghost"
                            size="sm"
                            aria-label="Tutup dialog"
                            class="-mr-2 -mt-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                            @click="handleClose"
                        >
                            <X class="w-4 h-4" aria-hidden="true" />
                        </IconButton>
                    </div>

                    <!-- Body -->
                    <div class="px-6 py-5">
                        <slot />
                    </div>

                    <!-- Footer -->
                    <div
                        v-if="$slots.footer"
                        class="px-6 py-4 bg-slate-50/50 dark:bg-slate-800/40 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-3"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
