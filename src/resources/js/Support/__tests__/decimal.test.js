import { describe, it, expect } from 'vitest';
import { subtractDecimals, multiplyDecimals } from '../decimal';

describe('decimal support utilities', () => {
    it('subtracts decimals with correct scale and rounding', () => {
        expect(subtractDecimals('10.5000', '5.2500')).toBe('5.2500');
        expect(subtractDecimals('5.0000', '10.0000')).toBe('-5.0000');
        expect(subtractDecimals('0', '0')).toBe('0.0000');
        expect(subtractDecimals('12.3456', '0.0001')).toBe('12.3455');
    });

    it('multiplies decimals without floating point error', () => {
        expect(multiplyDecimals('10.0000', '15000.0000')).toBe('150000.00');
        expect(multiplyDecimals('2.5000', '10.5000')).toBe('26.25');
        expect(multiplyDecimals('0.1000', '0.2000')).toBe('0.02');
        expect(multiplyDecimals('0', '1000.0000')).toBe('0.00');
    });
});
