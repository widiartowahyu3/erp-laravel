import { describe, it, expect } from 'vitest';

/**
 * Calculates WCAG 2.1 relative luminance for a given sRGB channel.
 */
function sRGBtoLin(colorChannel) {
    if (colorChannel <= 0.04045) {
        return colorChannel / 12.92;
    }
    return Math.pow((colorChannel + 0.055) / 1.055, 2.4);
}

/**
 * Parses hex color string (#rgb, #rrggbb) to RGB tuple [0..255].
 */
function hexToRgb(hex) {
    let cleanHex = hex.replace('#', '').trim();
    if (cleanHex.length === 3) {
        cleanHex = cleanHex
            .split('')
            .map((c) => c + c)
            .join('');
    }
    const num = parseInt(cleanHex, 16);
    return [(num >> 16) & 255, (num >> 8) & 255, num & 255];
}

/**
 * Calculates relative luminance according to WCAG definition.
 */
function luminance(hex) {
    const [r, g, b] = hexToRgb(hex).map((c) => sRGBtoLin(c / 255));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/**
 * Calculates contrast ratio between two colors.
 */
function contrast(hex1, hex2) {
    const l1 = luminance(hex1);
    const l2 = luminance(hex2);
    const lighter = Math.max(l1, l2);
    const darker = Math.min(l1, l2);
    return (lighter + 0.05) / (darker + 0.05);
}

describe('Design Tokens WCAG AA Contrast Verification', () => {
    describe('Light Mode Theme Tokens', () => {
        const tokens = {
            surfaceBase: '#f8fafc',
            surfaceCard: '#ffffff',
            surfaceMuted: '#f1f5f9',
            textMain: '#0f172a',
            textMuted: '#475569',
            actionPrimary: '#4f46e5',
            actionPrimaryText: '#ffffff',
            statusSuccessBg: '#ecfdf5',
            statusSuccessText: '#065f46',
            statusWarningBg: '#fffbeb',
            statusWarningText: '#92400e',
            statusDangerBg: '#fff1f2',
            statusDangerText: '#9f1239',
            statusInfoBg: '#f0f9ff',
            statusInfoText: '#075985',
        };

        it('main text satisfies WCAG AA (>= 4.5:1) on surface-card and surface-base', () => {
            const onCard = contrast(tokens.textMain, tokens.surfaceCard);
            const onBase = contrast(tokens.textMain, tokens.surfaceBase);
            expect(onCard).toBeGreaterThanOrEqual(4.5);
            expect(onBase).toBeGreaterThanOrEqual(4.5);
        });

        it('muted text satisfies WCAG AA (>= 4.5:1) on surface-card and surface-base', () => {
            const onCard = contrast(tokens.textMuted, tokens.surfaceCard);
            const onBase = contrast(tokens.textMuted, tokens.surfaceBase);
            expect(onCard).toBeGreaterThanOrEqual(4.5);
            expect(onBase).toBeGreaterThanOrEqual(4.5);
        });

        it('action button text satisfies WCAG AA (>= 4.5:1) on action primary background', () => {
            const ratio = contrast(tokens.actionPrimaryText, tokens.actionPrimary);
            expect(ratio).toBeGreaterThanOrEqual(4.5);
        });

        it('status badges text satisfies WCAG AA (>= 4.5:1) on respective status backgrounds', () => {
            expect(contrast(tokens.statusSuccessText, tokens.statusSuccessBg)).toBeGreaterThanOrEqual(4.5);
            expect(contrast(tokens.statusWarningText, tokens.statusWarningBg)).toBeGreaterThanOrEqual(4.5);
            expect(contrast(tokens.statusDangerText, tokens.statusDangerBg)).toBeGreaterThanOrEqual(4.5);
            expect(contrast(tokens.statusInfoText, tokens.statusInfoBg)).toBeGreaterThanOrEqual(4.5);
        });
    });

    describe('Dark Mode Theme Tokens', () => {
        const tokens = {
            surfaceBase: '#0b0f19',
            surfaceCard: '#111827',
            surfaceMuted: '#1e293b',
            textMain: '#f8fafc',
            textMuted: '#94a3b8',
            actionPrimary: '#4f46e5',
            actionPrimaryText: '#ffffff',
            statusSuccessBg: '#022c22',
            statusSuccessText: '#6ee7b7',
            statusWarningBg: '#451a03',
            statusWarningText: '#fcd34d',
            statusDangerBg: '#4c0519',
            statusDangerText: '#fda4af',
            statusInfoBg: '#082f49',
            statusInfoText: '#7dd3fc',
        };

        it('dark main text satisfies WCAG AA (>= 4.5:1) on surface-card and surface-base', () => {
            const onCard = contrast(tokens.textMain, tokens.surfaceCard);
            const onBase = contrast(tokens.textMain, tokens.surfaceBase);
            expect(onCard).toBeGreaterThanOrEqual(4.5);
            expect(onBase).toBeGreaterThanOrEqual(4.5);
        });

        it('dark muted text satisfies WCAG AA (>= 4.5:1) on surface-card and surface-base', () => {
            const onCard = contrast(tokens.textMuted, tokens.surfaceCard);
            const onBase = contrast(tokens.textMuted, tokens.surfaceBase);
            expect(onCard).toBeGreaterThanOrEqual(4.5);
            expect(onBase).toBeGreaterThanOrEqual(4.5);
        });

        it('dark action button text satisfies WCAG AA (>= 4.5:1) on action primary background', () => {
            const ratio = contrast(tokens.actionPrimaryText, tokens.actionPrimary);
            expect(ratio).toBeGreaterThanOrEqual(4.5);
        });

        it('dark status badges text satisfies WCAG AA (>= 4.5:1) on respective dark status backgrounds', () => {
            expect(contrast(tokens.statusSuccessText, tokens.statusSuccessBg)).toBeGreaterThanOrEqual(4.5);
            expect(contrast(tokens.statusWarningText, tokens.statusWarningBg)).toBeGreaterThanOrEqual(4.5);
            expect(contrast(tokens.statusDangerText, tokens.statusDangerBg)).toBeGreaterThanOrEqual(4.5);
            expect(contrast(tokens.statusInfoText, tokens.statusInfoBg)).toBeGreaterThanOrEqual(4.5);
        });
    });
});
