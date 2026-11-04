// Money arithmetic on two-decimal strings, in BigInt cents. Used only for on-screen
// indicators: the server stays the source of truth for validation.

const MONEY_PATTERN = /^\d+(\.\d{1,2})?$/;

function isBlank(value) {
    return value === null || value === undefined || (typeof value === 'string' && value.trim() === '');
}

export function isValidMoney(value) {
    if (isBlank(value)) {
        return true;
    }

    return typeof value === 'string' && MONEY_PATTERN.test(value.trim());
}

function toCents(value) {
    if (!isValidMoney(value)) {
        throw new RangeError(`Nilai uang tidak valid: ${String(value)}`);
    }

    if (isBlank(value)) {
        return 0n;
    }

    const [whole, fraction = ''] = value.trim().split('.');

    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}

function fromCents(cents) {
    const negative = cents < 0n;
    const digits = (negative ? -cents : cents).toString().padStart(3, '0');

    return `${negative ? '-' : ''}${digits.slice(0, -2)}.${digits.slice(-2)}`;
}

export function addMoney(a, b) {
    return fromCents(toCents(a) + toCents(b));
}

export function subtractMoney(a, b) {
    return fromCents(toCents(a) - toCents(b));
}

export function compareMoney(a, b) {
    const difference = toCents(a) - toCents(b);

    if (difference === 0n) {
        return 0;
    }

    return difference > 0n ? 1 : -1;
}

export function sumMoney(values) {
    return fromCents(values.reduce((total, value) => total + toCents(value), 0n));
}
