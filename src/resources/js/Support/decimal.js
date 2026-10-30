/**
 * Decimal math utilities using BigInt for frontend display and form computation.
 * Avoids JavaScript IEEE 754 floating-point inaccuracies.
 */

function normalizeDecimal(val) {
    if (val === null || val === undefined || val === '') return '0';
    const s = String(val).trim();
    return /^[+-]?[0-9]+(\.[0-9]+)?$/.test(s) ? s : '0';
}

function parseToScaledBigInt(val, scale) {
    const s = normalizeDecimal(val);
    const isNegative = s.startsWith('-');
    const clean = isNegative || s.startsWith('+') ? s.slice(1) : s;
    const [intPart, decPart = ''] = clean.split('.');
    const paddedDec = decPart.padEnd(scale, '0').slice(0, scale);
    const big = BigInt(intPart + paddedDec);
    return isNegative ? -big : big;
}

function scaledBigIntToString(big, scale) {
    const isNegative = big < 0n;
    const abs = isNegative ? -big : big;
    let s = abs.toString();
    if (scale > 0) {
        while (s.length <= scale) s = '0' + s;
        const whole = s.slice(0, s.length - scale);
        const frac = s.slice(s.length - scale);
        return (isNegative ? '-' : '') + whole + '.' + frac;
    }
    return (isNegative ? '-' : '') + s;
}

function roundScaled(big, currentScale, targetScale) {
    const isNegative = big < 0n;
    const abs = isNegative ? -big : big;
    if (currentScale === targetScale) {
        return scaledBigIntToString(big, targetScale);
    }
    if (currentScale > targetScale) {
        const diff = currentScale - targetScale;
        const divisor = 10n ** BigInt(diff);
        const half = divisor / 2n;
        const remainder = abs % divisor;
        let rounded = abs / divisor;
        if (remainder >= half) {
            rounded += 1n;
        }
        return scaledBigIntToString(isNegative ? -rounded : rounded, targetScale);
    }
    const diff = targetScale - currentScale;
    const multiplier = 10n ** BigInt(diff);
    return scaledBigIntToString(isNegative ? -(abs * multiplier) : abs * multiplier, targetScale);
}

export function subtractDecimals(a, b, scale = 4) {
    const scaleFactor = scale + 4;
    const bigA = parseToScaledBigInt(a, scaleFactor);
    const bigB = parseToScaledBigInt(b, scaleFactor);
    const diff = bigA - bigB;
    return roundScaled(diff, scaleFactor, scale);
}

export function multiplyDecimals(a, b, scale = 2) {
    const scaleA = 4;
    const scaleB = 4;
    const bigA = parseToScaledBigInt(a, scaleA);
    const bigB = parseToScaledBigInt(b, scaleB);
    const product = bigA * bigB; // scale is scaleA + scaleB = 8
    return roundScaled(product, scaleA + scaleB, scale);
}
