<script setup>
import { computed } from 'vue';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({
    permission: {
        type: String,
        default: undefined,
    },
    any: {
        type: Array,
        default: undefined,
    },
    all: {
        type: Array,
        default: undefined,
    },
});

const { can, canAny, canAll } = usePermission();

const isAllowed = computed(() => {
    if (props.permission) {
        return can(props.permission);
    }
    if (props.any && Array.isArray(props.any)) {
        return canAny(props.any);
    }
    if (props.all && Array.isArray(props.all)) {
        return canAll(props.all);
    }
    return true;
});
</script>

<template>
    <slot v-if="isAllowed" />
    <slot v-else name="fallback" />
</template>
