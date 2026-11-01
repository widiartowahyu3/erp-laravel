import { describe, it, expect } from 'vitest';
import {
    subtractDecimals,
    multiplyDecimals,
    mulDecimals,
    addDecimals,
    compareDecimals,
    calculatePurchaseOrderItem,
    calculateSalesOrderItem,
} from '../decimal';

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

    it('mulDecimals acts as alias for multiplyDecimals with correct scale', () => {
        expect(mulDecimals('4.0000', '10000.0000')).toBe('40000.00');
        expect(mulDecimals('2.5', '10.55')).toBe('26.38');
        expect(mulDecimals('0', '50000')).toBe('0.00');
        expect(mulDecimals(null, '10000')).toBe('0.00');
        expect(mulDecimals(undefined, '10000')).toBe('0.00');
        expect(mulDecimals).toBe(multiplyDecimals);
    });

    it('adds decimals with correct scale and rounding', () => {
        expect(addDecimals('10.50', '5.25')).toBe('15.75');
        expect(addDecimals('0.01', '0.02')).toBe('0.03');
        expect(addDecimals('100.00', '50.00')).toBe('150.00');
    });

    it('compares decimals correctly', () => {
        expect(compareDecimals('10.00', '5.00')).toBe(1);
        expect(compareDecimals('5.00', '10.00')).toBe(-1);
        expect(compareDecimals('10.0000', '10.00')).toBe(0);
    });

    it('calculates purchase order item totals matching backend calculator', () => {
        // 10 units @ 10,000, 5,000 discount, 11% tax
        // gross = 100,000.00
        // net = 95,000.00
        // tax = round(95,000 * 0.11) = 10,450.00
        // total = 105,450.00
        const result = calculatePurchaseOrderItem('10.0000', '10000.0000', '5000.00', '11.00');
        expect(result.lineGross).toBe('100000.00');
        expect(result.discountAmount).toBe('5000.00');
        expect(result.lineNet).toBe('95000.00');
        expect(result.taxAmount).toBe('10450.00');
        expect(result.lineTotal).toBe('105450.00');
    });
});
