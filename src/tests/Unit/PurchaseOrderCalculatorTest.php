<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Purchasing\PurchaseOrderCalculator;

test('calculates basic line item without discount and tax', function (): void {
    $result = PurchaseOrderCalculator::calculate([
        [
            'quantity' => '10.0000',
            'unit_price' => '15000.0000',
        ],
    ]);

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['line_gross'])->toBe('150000.00');
    expect($result['items'][0]['discount_amount'])->toBe('0.00');
    expect($result['items'][0]['line_net'])->toBe('150000.00');
    expect($result['items'][0]['tax_amount'])->toBe('0.00');
    expect($result['items'][0]['line_total'])->toBe('150000.00');

    expect($result['header']['subtotal'])->toBe('150000.00');
    expect($result['header']['discount_amount'])->toBe('0.00');
    expect($result['header']['tax_amount'])->toBe('0.00');
    expect($result['header']['total'])->toBe('150000.00');
});

test('calculates line item with discount and 11% tax correctly', function (): void {
    $result = PurchaseOrderCalculator::calculate([
        [
            'quantity' => '5.0000',
            'unit_price' => '20000.0000', // gross 100,000.00
            'discount_amount' => '10000.00', // net 90,000.00
            'tax_rate_percent' => '11.00', // tax 90,000 * 0.11 = 9,900.00
        ],
    ]);

    expect($result['items'][0]['line_gross'])->toBe('100000.00');
    expect($result['items'][0]['discount_amount'])->toBe('10000.00');
    expect($result['items'][0]['line_net'])->toBe('90000.00');
    expect($result['items'][0]['tax_amount'])->toBe('9900.00');
    expect($result['items'][0]['line_total'])->toBe('99900.00');

    expect($result['header']['subtotal'])->toBe('100000.00');
    expect($result['header']['discount_amount'])->toBe('10000.00');
    expect($result['header']['tax_amount'])->toBe('9900.00');
    expect($result['header']['total'])->toBe('99900.00');
});

test('calculates multiple lines and sums up header accurately', function (): void {
    $result = PurchaseOrderCalculator::calculate([
        [
            'quantity' => '2.0000',
            'unit_price' => '10000.0000', // gross 20,000.00, tax 0
            'discount_amount' => '0.00',
            'tax_rate_percent' => '0.00',
        ],
        [
            'quantity' => '3.0000',
            'unit_price' => '50000.0000', // gross 150,000.00, disc 20,000.00, net 130,000.00, tax 11% = 14,300.00, total 144,300.00
            'discount_amount' => '20000.00',
            'tax_rate_percent' => '11.00',
        ],
    ]);

    expect($result['header']['subtotal'])->toBe('170000.00');
    expect($result['header']['discount_amount'])->toBe('20000.00');
    expect($result['header']['tax_amount'])->toBe('14300.00');
    expect($result['header']['total'])->toBe('164300.00');
});

test('clamps net amount to zero when discount exceeds gross', function (): void {
    $result = PurchaseOrderCalculator::calculate([
        [
            'quantity' => '1.0000',
            'unit_price' => '1000.0000',
            'discount_amount' => '2000.00',
            'tax_rate_percent' => '11.00',
        ],
    ]);

    expect($result['items'][0]['line_gross'])->toBe('1000.00');
    expect($result['items'][0]['line_net'])->toBe('0.00');
    expect($result['items'][0]['tax_amount'])->toBe('0.00');
    expect($result['items'][0]['line_total'])->toBe('0.00');
});

test('netUnitCost calculates net unit cost after discount to 4 decimal places with half-up rounding', function (): void {
    // qty 4, price 2500.00, disc 1000.00 -> '2250.0000' (bersih 9000 / 4)
    expect(PurchaseOrderCalculator::netUnitCost('4.0000', '2500.00', '1000.00'))->toBe('2250.0000');

    // qty 8, price 1.00, disc 3.00 -> '0.6250' (bersih 5 / 8)
    expect(PurchaseOrderCalculator::netUnitCost('8.0000', '1.00', '3.00'))->toBe('0.6250');

    // qty 3, price 1000.00, disc 1.00 -> '999.6667' (bersih 2999 / 3, pembulatan half-up ke 4 desimal)
    expect(PurchaseOrderCalculator::netUnitCost('3.0000', '1000.00', '1.00'))->toBe('999.6667');

    // disc 0 -> sama dengan harga ('1000.0000')
    expect(PurchaseOrderCalculator::netUnitCost('5.0000', '1000.00', '0.00'))->toBe('1000.0000');

    // disc = gross -> '0.0000'
    expect(PurchaseOrderCalculator::netUnitCost('2.0000', '500.00', '1000.00'))->toBe('0.0000');
});
