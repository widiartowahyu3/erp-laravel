<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: {
        type: [String, Number],
        default: null,
    },
    showSign: {
        type: Boolean,
        default: false,
    },
});

const formatted = computed(() => {
    if (props.value === null || props.value === undefined || props.value === '') {
        return '-';
    }
    const s = String(props.value).trim();
    if (!/^[+-]?[0-9]+(\.[0-9]+)?$/.test(s)) {
        return '-';
    }
    const isNegative = s.startsWith('-');
    const clean = isNegative || s.startsWith('+') ? s.slice(1) : s;
    const [intPart, rawDec = ''] = clean.split('.');

    const formattedInt = new Intl.NumberFormat('id-ID').format(BigInt(intPart));
    const trimmedDec = rawDec.replace(/0+$/, '');
    const decFormatted = trimmedDec ? `,${trimmedDec}` : '';
    const sign = isNegative ? '-' : (props.showSign ? '+' : '');

    return `${sign}${formattedInt}${decFormatted}%`;
});
</script>

<template>
    <span class="tabular-nums whitespace-nowrap">{{ formatted }}</span>
</template>
