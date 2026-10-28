/**
 * Formats a numeric value or decimal string as Indonesian Rupiah (IDR).
 * Strictly float-safe by parsing integer and fractional parts as BigInt and strings.
 * Rejects invalid non-numeric inputs with fallback '-'.
 */
export function formatRupiah(val, showSymbol = true) {
    if (val === null || val === undefined || val === '') return '-';
    const s = String(val).trim();
    if (!/^[+-]?[0-9]+(\.[0-9]+)?$/.test(s)) return '-';

    const isNegative = s.startsWith('-');
    const clean = isNegative || s.startsWith('+') ? s.slice(1) : s;
    const [intPart, decPart = ''] = clean.split('.');

    const formattedInt = new Intl.NumberFormat('id-ID').format(BigInt(intPart));
    const twoDec = decPart.padEnd(2, '0').slice(0, 2);
    const hasCents = twoDec !== '00' && decPart.length > 0;
    const decFormatted = hasCents ? `,${twoDec}` : '';
    const sign = isNegative ? '-' : '';
    const symbol = showSymbol ? 'Rp ' : '';

    return `${sign}${symbol}${formattedInt}${decFormatted}`;
}

/**
 * Formats a quantity value with Indonesian thousands separators and dynamic trailing zero stripping.
 * Example: 10.5000 -> 10,5.
 */
export function formatKuantitas(val, unit = '') {
    if (val === null || val === undefined || val === '') return '-';
    const s = String(val).trim();
    if (!/^[+-]?[0-9]+(\.[0-9]+)?$/.test(s)) return '-';

    const isNegative = s.startsWith('-');
    const clean = isNegative || s.startsWith('+') ? s.slice(1) : s;
    const [intPart, rawDec = ''] = clean.split('.');

    const formattedInt = new Intl.NumberFormat('id-ID').format(BigInt(intPart));
    const trimmedDec = rawDec.replace(/0+$/, '');
    const decFormatted = trimmedDec ? `,${trimmedDec}` : '';
    const sign = isNegative ? '-' : '';
    const unitPart = unit ? ` ${unit}` : '';

    return `${sign}${formattedInt}${decFormatted}${unitPart}`;
}

/**
 * Formats a date string or Date object into Indonesian locale format.
 * Formats: 'short' (28 Okt 2026), 'medium' (28 Oktober 2026), 'datetime' (28 Okt 2026, 14:30 WIB).
 */
export function formatTanggal(val, format = 'medium') {
    if (!val) return '-';
    const date = val instanceof Date ? val : new Date(val);
    if (isNaN(date.getTime())) return '-';

    if (format === 'short') {
        return new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }).format(date);
    }

    if (format === 'datetime') {
        const datePart = new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }).format(date);

        const timePart = new Intl.DateTimeFormat('id-ID', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).format(date);

        return `${datePart}, ${timePart} WIB`;
    }

    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(date);
}

/**
 * Formats a percentage value.
 * Example: 11 -> 11%, 11.5 -> 11,5%.
 */
export function formatPersen(val, showSign = false) {
    if (val === null || val === undefined || val === '') return '-';
    const s = String(val).trim();
    if (!/^[+-]?[0-9]+(\.[0-9]+)?$/.test(s)) return '-';

    const isNegative = s.startsWith('-');
    const clean = isNegative || s.startsWith('+') ? s.slice(1) : s;
    const [intPart, rawDec = ''] = clean.split('.');

    const formattedInt = new Intl.NumberFormat('id-ID').format(BigInt(intPart));
    const trimmedDec = rawDec.replace(/0+$/, '');
    const decFormatted = trimmedDec ? `,${trimmedDec}` : '';
    const sign = isNegative ? '-' : (showSign ? '+' : '');

    return `${sign}${formattedInt}${decFormatted}%`;
}

export function useFormatters() {
    return {
        formatRupiah,
        formatKuantitas,
        formatTanggal,
        formatPersen,
    };
}
