<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessRuleException;
use App\Support\Purchasing\PurchaseInvoiceCalculator;

/**
 * @param  array<string, string|int>  $overrides
 * @return array<string, string|int>
 */
function purchaseLine(array $overrides = []): array
{
    return array_merge([
        'order_item_key' => 1,
        'receipt_item_key' => 1,
        'quantity' => '3.0000',
        'unit_price' => '100.0000',
        'order_quantity' => '3.0000',
        'order_discount' => '0.00',
        'tax_rate_percent' => '0.00',
        'billed_quantity' => '0.0000',
        'billed_discount' => '0.00',
        'billed_lines' => 0,
        'receipt_quantity' => '3.0000',
        'unit_cost' => '100.0000',
        'recognized_quantity' => '0.0000',
        'recognized_value' => '0.00',
    ], $overrides);
}

test('billing a whole line reproduces the order amounts and recognizes the receipt value', function (): void {
    $result = PurchaseInvoiceCalculator::calculate([purchaseLine([
        'quantity' => '10.0000',
        'unit_price' => '12500.5000',
        'order_quantity' => '10.0000',
        'order_discount' => '1000.00',
        'tax_rate_percent' => '11.00',
        'receipt_quantity' => '10.0000',
        'unit_cost' => '12400.5000',
    ])]);

    expect($result['items'][0]['line_gross'])->toBe('125005.00')
        ->and($result['items'][0]['discount_amount'])->toBe('1000.00')
        ->and($result['items'][0]['tax_amount'])->toBe('13640.55')
        ->and($result['items'][0]['line_total'])->toBe('137645.55')
        ->and($result['items'][0]['goods_value'])->toBe('124005.00')
        ->and($result['header']['total'])->toBe('137645.55')
        ->and($result['goods_value'])->toBe('124005.00')
        ->and($result['difference'])->toBe('0.00');
});

test('three partial billings leave the discount residue on the last and add up to the order discount', function (): void {
    $shared = [
        'quantity' => '1.0000',
        'unit_price' => '1000.0000',
        'order_discount' => '100.00',
        'unit_cost' => '966.6667',
    ];

    $first = PurchaseInvoiceCalculator::calculate([purchaseLine($shared)]);
    $second = PurchaseInvoiceCalculator::calculate([purchaseLine($shared + [
        'billed_quantity' => '1.0000',
        'billed_discount' => '33.33',
        'billed_lines' => 1,
        'recognized_quantity' => '1.0000',
        'recognized_value' => '966.67',
    ])]);
    $third = PurchaseInvoiceCalculator::calculate([purchaseLine($shared + [
        'billed_quantity' => '2.0000',
        'billed_discount' => '66.66',
        'billed_lines' => 2,
        'recognized_quantity' => '2.0000',
        'recognized_value' => '1933.34',
    ])]);

    expect($first['items'][0]['discount_amount'])->toBe('33.33')
        ->and($second['items'][0]['discount_amount'])->toBe('33.33')
        ->and($third['items'][0]['discount_amount'])->toBe('33.34');

    // The receipt was journaled at V(3 x 966.6667) = 2900.00, and the last billing takes what is left of it.
    expect($first['goods_value'])->toBe('966.67')
        ->and($second['goods_value'])->toBe('966.67')
        ->and($third['goods_value'])->toBe('966.66')
        ->and(bcadd(bcadd($first['goods_value'], $second['goods_value'], 2), $third['goods_value'], 2))->toBe('2900.00');
});

test('a rounding difference between net and recognized value is reported with its sign', function (): void {
    $over = PurchaseInvoiceCalculator::calculate([purchaseLine([
        'quantity' => '1500.0000',
        'unit_price' => '12500.0000',
        'order_quantity' => '1500.0000',
        'order_discount' => '1000.00',
        'receipt_quantity' => '1500.0000',
        'unit_cost' => '12499.3333',
    ])]);

    expect($over['header']['subtotal'])->toBe('18750000.00')
        ->and($over['goods_value'])->toBe('18748999.95')
        ->and($over['difference'])->toBe('0.05');

    $under = PurchaseInvoiceCalculator::calculate([purchaseLine([
        'quantity' => '3000.0000',
        'unit_price' => '100.0000',
        'order_quantity' => '3000.0000',
        'order_discount' => '100.00',
        'receipt_quantity' => '3000.0000',
        'unit_cost' => '99.9667',
    ])]);

    expect($under['goods_value'])->toBe('299900.10')
        ->and($under['difference'])->toBe('-0.10');
});

test('the tolerance follows the quantity, and a difference above it is refused', function (): void {
    $line = [
        'quantity' => '3000.0000',
        'unit_price' => '100.0000',
        'order_quantity' => '3000.0000',
        'order_discount' => '0.00',
        'receipt_quantity' => '3000.0000',
    ];

    // 0.01 + 3000 x 0.00005 = 0.16
    $atTheLimit = PurchaseInvoiceCalculator::calculate([purchaseLine($line + ['unit_cost' => '99.99995'])]);

    expect($atTheLimit['difference'])->toBe('0.15');

    expect(fn () => PurchaseInvoiceCalculator::calculate([purchaseLine($line + ['unit_cost' => '99.9999'])]))
        ->toThrow(BusinessRuleException::class, 'selisih');
});

test('a small line keeps the narrow tolerance of one cent', function (): void {
    $withinCent = PurchaseInvoiceCalculator::calculate([purchaseLine([
        'quantity' => '1.0000',
        'order_quantity' => '1.0000',
        'receipt_quantity' => '1.0000',
        'unit_cost' => '99.9900',
    ])]);

    expect($withinCent['difference'])->toBe('0.01');

    expect(fn () => PurchaseInvoiceCalculator::calculate([purchaseLine([
        'quantity' => '1.0000',
        'order_quantity' => '1.0000',
        'receipt_quantity' => '1.0000',
        'unit_cost' => '99.9800',
    ])]))->toThrow(BusinessRuleException::class);
});

test('the billing that completes a line may carry the residue of many earlier billings', function (): void {
    $result = PurchaseInvoiceCalculator::calculate([purchaseLine([
        'quantity' => '1.0000',
        'unit_price' => '100.0000',
        'order_quantity' => '3000.0000',
        'order_discount' => '100.00',
        'billed_quantity' => '2999.0000',
        'billed_discount' => '89.97',
        'billed_lines' => 2999,
        'receipt_quantity' => '3000.0000',
        'unit_cost' => '99.9667',
        'recognized_quantity' => '2999.0000',
        'recognized_value' => '299810.03',
    ])]);

    expect($result['items'][0]['discount_amount'])->toBe('10.03')
        ->and($result['goods_value'])->toBe('90.07')
        ->and($result['difference'])->toBe('-0.10');
});

test('lines of one receipt item inside a single invoice build on each other', function (): void {
    $shared = ['quantity' => '1.0000', 'unit_cost' => '66.6667', 'order_quantity' => '3.0000', 'unit_price' => '66.6667'];

    $result = PurchaseInvoiceCalculator::calculate([
        purchaseLine($shared),
        purchaseLine($shared),
        purchaseLine($shared),
    ]);

    expect(array_column($result['items'], 'goods_value'))->toBe(['66.67', '66.67', '66.66'])
        ->and($result['goods_value'])->toBe('200.00');
});

test('two receipts of one order line keep separate recognized values', function (): void {
    $result = PurchaseInvoiceCalculator::calculate([
        purchaseLine(['receipt_item_key' => 1, 'quantity' => '1.0000', 'receipt_quantity' => '1.0000', 'unit_cost' => '66.6667', 'unit_price' => '66.6667', 'order_quantity' => '3.0000']),
        purchaseLine(['receipt_item_key' => 2, 'quantity' => '2.0000', 'receipt_quantity' => '2.0000', 'unit_cost' => '66.6667', 'unit_price' => '66.6667', 'order_quantity' => '3.0000']),
    ]);

    expect(array_column($result['items'], 'goods_value'))->toBe(['66.67', '133.33']);
});

test('no difference is reported on a clean line', function (): void {
    $result = PurchaseInvoiceCalculator::calculate([purchaseLine()]);

    expect($result['goods_value'])->toBe('300.00')
        ->and($result['difference'])->toBe('0.00');
});
