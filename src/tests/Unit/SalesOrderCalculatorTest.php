<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Decimal;
use App\Support\Sales\SalesOrderCalculator;
use InvalidArgumentException;

test('calculates basic line item without discount and tax for sales order', function (): void {
    $result = SalesOrderCalculator::calculate([
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

test('calculates line item with discount and 11% tax correctly for sales order', function (): void {
    $result = SalesOrderCalculator::calculate([
        [
            'quantity' => '5.0000',
            'unit_price' => '20000.0000',
            'discount_amount' => '10000.00',
            'tax_rate_percent' => '11.00',
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

test('throws InvalidArgumentException when line discount exceeds line gross in sales order', function (): void {
    expect(fn () => SalesOrderCalculator::calculate([
        [
            'quantity' => '1.0000',
            'unit_price' => '1000.0000',
            'discount_amount' => '2000.00',
            'tax_rate_percent' => '11.00',
        ],
    ]))->toThrow(InvalidArgumentException::class, 'Diskon tidak boleh melebihi nilai baris.');
});

test('netUnitPrice calculates net price after discount to 4 decimal places with half-up rounding', function (): void {
    expect(SalesOrderCalculator::netUnitPrice('4.0000', '2500.00', '1000.00'))->toBe('2250.0000');
    expect(SalesOrderCalculator::netUnitPrice('8.0000', '1.00', '3.00'))->toBe('0.6250');
    expect(SalesOrderCalculator::netUnitPrice('3.0000', '1000.00', '1.00'))->toBe('999.6667');
    expect(SalesOrderCalculator::netUnitPrice('5.0000', '1000.00', '0.00'))->toBe('1000.0000');
    expect(SalesOrderCalculator::netUnitPrice('2.0000', '500.00', '1000.00'))->toBe('0.0000');
});

test('mathematical invariants hold for sales order calculation', function (): void {
    $items = [
        ['quantity' => '2.0000', 'unit_price' => '50000.00', 'discount_amount' => '10000.00', 'tax_rate_percent' => '11.00'],
        ['quantity' => '1.0000', 'unit_price' => '30000.00', 'discount_amount' => '30000.00', 'tax_rate_percent' => '11.00'],
        ['quantity' => '6.0000', 'unit_price' => '1500.00', 'discount_amount' => '500.00', 'tax_rate_percent' => '0.00'],
    ];

    $res = SalesOrderCalculator::calculate($items);
    $header = $res['header'];

    $computedHeaderTotal = Decimal::add(
        Decimal::sub($header['subtotal'], $header['discount_amount'], Decimal::SCALE_MONEY),
        $header['tax_amount'],
        Decimal::SCALE_MONEY
    );
    expect($header['total'])->toBe($computedHeaderTotal);

    $sumLines = array_reduce(
        $res['items'],
        fn (string $carry, array $it): string => Decimal::add($carry, $it['line_total'], Decimal::SCALE_MONEY),
        '0.00'
    );
    expect($header['total'])->toBe($sumLines);
});
