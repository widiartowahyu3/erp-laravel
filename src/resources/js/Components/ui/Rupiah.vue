<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: {
        type: [String, Number],
        default: null,
    },
    showSymbol: {
        type: Boolean,
        default: true,
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
    const [intPart, decPart = ''] = clean.split('.');

    const formattedInt = new Intl.NumberFormat('id-ID').format(BigInt(intPart));
    const twoDec = decPart.padEnd(2, '0').slice(0, 2);
    const hasCents = twoDec !== '00' && decPart.length > 0;
    const decFormatted = hasCents ? `,${twoDec}` : '';
    const sign = isNegative ? '-' : '';
    const symbol = props.showSymbol ? 'Rp ' : '';

    return `${sign}${symbol}${formattedInt}${decFormatted}`;
});
</script>

<template>
    <span class="tabular-nums whitespace-nowrap">{{ formatted }}</span>
</template>
