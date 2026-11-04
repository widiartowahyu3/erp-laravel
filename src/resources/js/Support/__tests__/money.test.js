import { describe, it, expect } from 'vitest';
import { addMoney, subtractMoney, compareMoney, sumMoney, isValidMoney } from '../money';

describe('money helper', () => {
    it('adds without float drift', () => {
        expect(addMoney('0.10', '0.20')).toBe('0.30');
    });

    it('adds and subtracts values beyond the safe integer range of floats', () => {
        expect(addMoney('90071992547409.93', '0.01')).toBe('90071992547409.94');
        expect(subtractMoney('90071992547409.94', '0.01')).toBe('90071992547409.93');
        expect(addMoney('99999999999999.99', '99999999999999.99')).toBe('199999999999999.98');
    });

    it('treats zero and blank input as zero', () => {
        expect(addMoney('0', '0.00')).toBe('0.00');
        expect(addMoney('', '1.50')).toBe('1.50');
        expect(addMoney(null, undefined)).toBe('0.00');
        expect(subtractMoney('  ', '1')).toBe('-1.00');
    });

    it('formats negative results and pads decimals', () => {
        expect(subtractMoney('1', '1.25')).toBe('-0.25');
        expect(subtractMoney('5', '5')).toBe('0.00');
        expect(addMoney('7', '0.5')).toBe('7.50');
    });

    it('compares amounts', () => {
        expect(compareMoney('1.10', '1.1')).toBe(0);
        expect(compareMoney('2', '1.99')).toBe(1);
        expect(compareMoney('1.99', '2')).toBe(-1);
    });

    it('sums a list', () => {
        expect(sumMoney(['0.10', '0.20', '', '1'])).toBe('1.30');
        expect(sumMoney([])).toBe('0.00');
    });

    it.each(['abc', '1.234', '-1', '1e3', '1,50', '.5', NaN, 1.5, {}])('rejects invalid input %s', (value) => {
        expect(isValidMoney(value)).toBe(false);
        expect(() => addMoney(value, '1')).toThrow(RangeError);
    });

    it('accepts valid input', () => {
        expect(isValidMoney('0')).toBe(true);
        expect(isValidMoney('12.5')).toBe(true);
        expect(isValidMoney('')).toBe(true);
    });
});
