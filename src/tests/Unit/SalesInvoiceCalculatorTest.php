<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Sales\SalesInvoiceCalculator;
use App\Support\Sales\SalesOrderCalculator;

/**
 * @param  array<string, string|int>  $overrides
 * @return array<string, string|int>
 */
function invoiceLine(array $overrides = []): array
{
    return array_merge([
        'order_item_key' => 1,
        'quantity' => '1.0000',
        'unit_price' => '1000.0000',
        'order_quantity' => '3.0000',
        'order_discount' => '100.00',
        'tax_rate_percent' => '0.00',
        'billed_quantity' => '0.0000',
        'billed_discount' => '0.00',
    ], $overrides);
}

test('billing a whole order line reproduces the sales order amounts exactly', function (): void {
    $result = SalesInvoiceCalculator::calculate([invoiceLine([
        'quantity' => '10.0000',
        'unit_price' => '12500.5000',
        'order_quantity' => '10.0000',
        'order_discount' => '1000.00',
        'tax_rate_percent' => '11.00',
    ])]);

    expect($result['items'][0])->toBe([
        'line_gross' => '125005.00',
        'discount_amount' => '1000.00',
        'line_net' => '124005.00',
        'tax_amount' => '13640.55',
        'line_total' => '137645.55',
    ]);
    expect($result['header'])->toBe([
        'subtotal' => '125005.00',
        'discount_amount' => '1000.00',
        'tax_amount' => '13640.55',
        'total' => '137645.55',
    ]);

    $order = SalesOrderCalculator::calculate([[
        'quantity' => '10.0000',
        'unit_price' => '12500.5000',
        'discount_amount' => '1000.00',
        'tax_rate_percent' => '11.00',
    ]]);

    expect($result['header'])->toBe($order['header']);
});

test('three partial billings leave the rounding residue on the last one and add up to the order discount', function (): void {
    $first = SalesInvoiceCalculator::calculate([invoiceLine()]);
    $second = SalesInvoiceCalculator::calculate([invoiceLine([
        'billed_quantity' => '1.0000',
        'billed_discount' => '33.33',
    ])]);
    $third = SalesInvoiceCalculator::calculate([invoiceLine([
        'billed_quantity' => '2.0000',
        'billed_discount' => '66.66',
    ])]);

    expect($first['items'][0]['discount_amount'])->toBe('33.33')
        ->and($second['items'][0]['discount_amount'])->toBe('33.33')
        ->and($third['items'][0]['discount_amount'])->toBe('33.34')
        ->and(bcadd(bcadd($first['items'][0]['discount_amount'], $second['items'][0]['discount_amount'], 2), $third['items'][0]['discount_amount'], 2))->toBe('100.00');
});

test('lines of one order item inside a single invoice build on each other', function (): void {
    $result = SalesInvoiceCalculator::calculate([
        invoiceLine(),
        invoiceLine(),
        invoiceLine(),
    ]);

    expect(array_column($result['items'], 'discount_amount'))->toBe(['33.33', '33.33', '33.34'])
        ->and($result['header']['discount_amount'])->toBe('100.00')
        ->and($result['header']['subtotal'])->toBe('3000.00')
        ->and($result['header']['total'])->toBe('2900.00');
});

test('separate order items keep separate running totals', function (): void {
    $result = SalesInvoiceCalculator::calculate([
        invoiceLine(['order_item_key' => 1]),
        invoiceLine(['order_item_key' => 2]),
    ]);

    expect(array_column($result['items'], 'discount_amount'))->toBe(['33.33', '33.33']);
});

test('after a partial billing is voided the residue comes from the remaining invoices only', function (): void {
    $stillOneThirdAway = SalesInvoiceCalculator::calculate([invoiceLine([
        'billed_quantity' => '1.0000',
        'billed_discount' => '33.33',
    ])]);

    $finishesTheLine = SalesInvoiceCalculator::calculate([invoiceLine([
        'quantity' => '2.0000',
        'billed_quantity' => '1.0000',
        'billed_discount' => '33.33',
    ])]);

    expect($stillOneThirdAway['items'][0]['discount_amount'])->toBe('33.33')
        ->and($finishesTheLine['items'][0]['discount_amount'])->toBe('66.67');
});

test('tax is rounded per line for no tax, 11 percent and 12 percent', function (): void {
    $line = [
        'quantity' => '1.0000',
        'unit_price' => '33333.3300',
        'order_quantity' => '1.0000',
        'order_discount' => '0.00',
    ];

    $none = SalesInvoiceCalculator::calculate([invoiceLine($line + ['tax_rate_percent' => '0.00'])]);
    $eleven = SalesInvoiceCalculator::calculate([invoiceLine(['tax_rate_percent' => '11.00'] + $line)]);
    $twelve = SalesInvoiceCalculator::calculate([invoiceLine(['tax_rate_percent' => '12.00'] + $line)]);

    expect($none['items'][0]['tax_amount'])->toBe('0.00')
        ->and($none['items'][0]['line_total'])->toBe('33333.33')
        ->and($eleven['items'][0]['tax_amount'])->toBe('3666.67')
        ->and($eleven['items'][0]['line_total'])->toBe('37000.00')
        ->and($twelve['items'][0]['tax_amount'])->toBe('4000.00')
        ->and($twelve['items'][0]['line_total'])->toBe('37333.33');
});

test('tax is charged on the amount after discount', function (): void {
    $result = SalesInvoiceCalculator::calculate([invoiceLine(['tax_rate_percent' => '11.00'])]);

    expect($result['items'][0]['line_net'])->toBe('966.67')
        ->and($result['items'][0]['tax_amount'])->toBe('106.33')
        ->and($result['items'][0]['line_total'])->toBe('1073.00');
});

test('a line without an order discount stays at zero, partially and on the last billing', function (): void {
    $partial = SalesInvoiceCalculator::calculate([invoiceLine(['order_discount' => '0.00'])]);
    $last = SalesInvoiceCalculator::calculate([invoiceLine([
        'order_discount' => '0.00',
        'billed_quantity' => '2.0000',
    ])]);

    expect($partial['items'][0]['discount_amount'])->toBe('0.00')
        ->and($last['items'][0]['discount_amount'])->toBe('0.00')
        ->and($partial['header']['discount_amount'])->toBe('0.00');
});

test('fractional quantities still add up to the order discount', function (): void {
    $first = SalesInvoiceCalculator::calculate([invoiceLine([
        'quantity' => '0.7500',
        'order_quantity' => '2.2500',
        'order_discount' => '10.00',
    ])]);
    $last = SalesInvoiceCalculator::calculate([invoiceLine([
        'quantity' => '1.5000',
        'order_quantity' => '2.2500',
        'order_discount' => '10.00',
        'billed_quantity' => '0.7500',
        'billed_discount' => $first['items'][0]['discount_amount'],
    ])]);

    expect($first['items'][0]['discount_amount'])->toBe('3.33')
        ->and($last['items'][0]['discount_amount'])->toBe('6.67');
});

test('the discount never goes below zero or above the line value', function (): void {
    $overBilled = SalesInvoiceCalculator::calculate([invoiceLine([
        'billed_quantity' => '2.0000',
        'billed_discount' => '120.00',
    ])]);
    $cheapLine = SalesInvoiceCalculator::calculate([invoiceLine([
        'unit_price' => '0.0100',
        'order_discount' => '0.03',
        'billed_quantity' => '2.0000',
        'billed_discount' => '0.00',
    ])]);

    expect($overBilled['items'][0]['discount_amount'])->toBe('0.00')
        ->and($cheapLine['items'][0]['discount_amount'])->toBe('0.01')
        ->and($cheapLine['items'][0]['line_net'])->toBe('0.00');
});
