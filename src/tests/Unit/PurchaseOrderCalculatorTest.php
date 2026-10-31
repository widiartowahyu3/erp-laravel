<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Decimal;
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

test('throws InvalidArgumentException when line discount exceeds line gross', function (): void {
    expect(fn () => PurchaseOrderCalculator::calculate([
        [
            'quantity' => '1.0000',
            'unit_price' => '1000.0000',
            'discount_amount' => '2000.00',
            'tax_rate_percent' => '11.00',
        ],
    ]))->toThrow(\InvalidArgumentException::class, 'Diskon tidak boleh melebihi nilai baris.');
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

test('boundary discount tests and mathematical invariants across 6 valid combinations', function (): void {
    // 1. Boundary: discount = 0
    $calcZeroDisc = PurchaseOrderCalculator::calculate([[
        'quantity' => '2.0000',
        'unit_price' => '5000.00',
        'discount_amount' => '0.00',
        'tax_rate_percent' => '11.00',
    ]]);
    expect($calcZeroDisc['items'][0]['line_gross'])->toBe('10000.00');
    expect($calcZeroDisc['items'][0]['discount_amount'])->toBe('0.00');
    expect($calcZeroDisc['items'][0]['line_net'])->toBe('10000.00');

    // 2. Boundary: discount = gross (100% discount)
    $calcFullDisc = PurchaseOrderCalculator::calculate([[
        'quantity' => '2.0000',
        'unit_price' => '5000.00', // gross 10,000.00
        'discount_amount' => '10000.00',
        'tax_rate_percent' => '11.00',
    ]]);
    expect($calcFullDisc['items'][0]['line_gross'])->toBe('10000.00');
    expect($calcFullDisc['items'][0]['discount_amount'])->toBe('10000.00');
    expect($calcFullDisc['items'][0]['line_net'])->toBe('0.00');
    expect($calcFullDisc['items'][0]['tax_amount'])->toBe('0.00');
    expect($calcFullDisc['items'][0]['line_total'])->toBe('0.00');
    expect($calcFullDisc['header']['subtotal'])->toBe('10000.00');
    expect($calcFullDisc['header']['discount_amount'])->toBe('10000.00');
    expect($calcFullDisc['header']['tax_amount'])->toBe('0.00');
    expect($calcFullDisc['header']['total'])->toBe('0.00');

    // 3. Boundary: discount = gross - 0.01 (allowed)
    $calcAlmostFull = PurchaseOrderCalculator::calculate([[
        'quantity' => '2.0000',
        'unit_price' => '5000.00', // gross 10,000.00
        'discount_amount' => '9999.99',
        'tax_rate_percent' => '0.00',
    ]]);
    expect($calcAlmostFull['items'][0]['line_net'])->toBe('0.01');
    expect($calcAlmostFull['header']['total'])->toBe('0.01');

    // 4. Boundary: discount = gross + 0.01 (rejected)
    expect(fn () => PurchaseOrderCalculator::calculate([[
        'quantity' => '2.0000',
        'unit_price' => '5000.00', // gross 10,000.00
        'discount_amount' => '10000.01',
    ]]))->toThrow(\InvalidArgumentException::class, 'Diskon tidak boleh melebihi nilai baris.');

    // 6 Valid Combinations verifying both invariants:
    // Invariant A: subtotal - discount_amount + tax_amount === total
    // Invariant B: sum(line_total) === total
    $combinations = [
        // Combo 1: No discount, no tax
        [
            ['quantity' => '5.0000', 'unit_price' => '12000.00', 'discount_amount' => '0.00', 'tax_rate_percent' => '0.00'],
        ],
        // Combo 2: No discount, 11% tax
        [
            ['quantity' => '4.0000', 'unit_price' => '25000.00', 'discount_amount' => '0.00', 'tax_rate_percent' => '11.00'],
        ],
        // Combo 3: Partial discount, 11% tax
        [
            ['quantity' => '10.0000', 'unit_price' => '15000.00', 'discount_amount' => '15000.00', 'tax_rate_percent' => '11.00'],
        ],
        // Combo 4: 100% discount, 11% tax
        [
            ['quantity' => '3.0000', 'unit_price' => '8000.00', 'discount_amount' => '24000.00', 'tax_rate_percent' => '11.00'],
        ],
        // Combo 5: Near 100% discount with fractional quantity, 12% tax
        [
            ['quantity' => '2.5000', 'unit_price' => '4000.00', 'discount_amount' => '9999.50', 'tax_rate_percent' => '12.00'],
        ],
        // Combo 6: Multi-item mixed scenario
        [
            ['quantity' => '2.0000', 'unit_price' => '50000.00', 'discount_amount' => '10000.00', 'tax_rate_percent' => '11.00'],
            ['quantity' => '1.0000', 'unit_price' => '30000.00', 'discount_amount' => '30000.00', 'tax_rate_percent' => '11.00'],
            ['quantity' => '6.0000', 'unit_price' => '1500.00', 'discount_amount' => '500.00', 'tax_rate_percent' => '0.00'],
        ],
    ];

    foreach ($combinations as $items) {
        $res = PurchaseOrderCalculator::calculate($items);
        $header = $res['header'];

        // Invariant A: subtotal - discount_amount + tax_amount === total
        $computedHeaderTotal = Decimal::add(
            Decimal::sub($header['subtotal'], $header['discount_amount'], Decimal::SCALE_MONEY),
            $header['tax_amount'],
            Decimal::SCALE_MONEY
        );
        expect($header['total'])->toBe($computedHeaderTotal);

        // Invariant B: sum(line_total) === total
        $sumLines = array_reduce(
            $res['items'],
            fn (string $carry, array $it): string => Decimal::add($carry, $it['line_total'], Decimal::SCALE_MONEY),
            '0.00'
        );
        expect($header['total'])->toBe($sumLines);
    }
});
