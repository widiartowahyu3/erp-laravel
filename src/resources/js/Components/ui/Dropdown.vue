<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue';

const props = defineProps({
    align: {
        type: String,
        default: 'right',
        validator: (value) => ['left', 'right'].includes(value),
    },
    width: {
        type: String,
        default: '48',
    },
});

const isOpen = ref(false);
const dropdownRef = ref(null);
const triggerRef = ref(null);

const widthClasses = {
    48: 'w-48',
    56: 'w-56',
    64: 'w-64',
    auto: 'w-auto',
};

const alignClasses = {
    left: 'left-0 origin-top-left',
    right: 'right-0 origin-top-right',
};

function toggle() {
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        nextTick(() => {
            const firstItem = dropdownRef.value?.querySelector('button, [href], [tabindex]:not([tabindex="-1"])');
            firstItem?.focus();
        });
    }
}

function close() {
    if (isOpen.value) {
        isOpen.value = false;
        const triggerEl = triggerRef.value?.querySelector('button, [href], [tabindex]') || triggerRef.value;
        triggerEl?.focus?.();
    }
}

function handleClickOutside(event) {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
        close();
    }
}

function handleKeydown(event) {
    if (!isOpen.value) return;

    if (event.key === 'Escape') {
        event.preventDefault();
        close();
        return;
    }

    if (event.key === 'Tab') {
        close();
        return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const items = Array.from(
            dropdownRef.value?.querySelectorAll('button:not([disabled]), [href]:not([disabled]), [tabindex]:not([tabindex="-1"])') || []
        );
        if (items.length === 0) return;

        const currentIndex = items.indexOf(document.activeElement);
        let nextIndex;

        if (event.key === 'ArrowDown') {
            nextIndex = currentIndex < items.length - 1 ? currentIndex + 1 : 0;
        } else {
            nextIndex = currentIndex > 0 ? currentIndex - 1 : items.length - 1;
        }

        items[nextIndex]?.focus();
    }
}

onMounted(() => {
    window.addEventListener('click', handleClickOutside, true);
    window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    window.removeEventListener('click', handleClickOutside, true);
    window.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
    <div ref="dropdownRef" class="relative inline-block text-left">
        <div ref="triggerRef" aria-haspopup="true" :aria-expanded="isOpen">
            <slot
                name="trigger"
                :open="isOpen"
                :toggle="toggle"
                :close="close"
            />
        </div>

        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="transform scale-95 opacity-0"
            enter-to-class="transform scale-100 opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="transform scale-100 opacity-100"
            leave-to-class="transform scale-95 opacity-0"
        >
            <div
                v-if="isOpen"
                role="menu"
                tabindex="-1"
                :class="[
                    'absolute z-50 mt-1 rounded-xl bg-white dark:bg-slate-900 shadow-lg border border-slate-200 dark:border-slate-800 py-1.5 focus:outline-none',
                    widthClasses[width] || 'w-48',
                    alignClasses[align],
                ]"
            >
                <slot :close="close" />
            </div>
        </Transition>
    </div>
</template>
