<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SalesOrderStatus;
use App\Support\Sales\SalesOrderProgress;

test('returns current status when item list is empty for sales order', function (): void {
    expect(SalesOrderProgress::statusFor([], SalesOrderStatus::APPROVED))
        ->toBe(SalesOrderStatus::APPROVED);
});

test('returns current status unchanged if closed or void for sales order', function (): void {
    $items = [
        ['quantity' => '10.0000', 'delivered_quantity' => '10.0000'],
    ];

    expect(SalesOrderProgress::statusFor($items, SalesOrderStatus::CLOSED))
        ->toBe(SalesOrderStatus::CLOSED);

    expect(SalesOrderProgress::statusFor($items, SalesOrderStatus::VOID))
        ->toBe(SalesOrderStatus::VOID);
});

test('retains approved or rolls back to approved when all delivered quantities are zero', function (): void {
    $items = [
        ['quantity' => '10.0000', 'delivered_quantity' => '0.0000'],
        ['quantity' => '5.0000', 'delivered_quantity' => '0.0000'],
    ];

    expect(SalesOrderProgress::statusFor($items, SalesOrderStatus::APPROVED))
        ->toBe(SalesOrderStatus::APPROVED);

    expect(SalesOrderProgress::statusFor($items, SalesOrderStatus::DRAFT))
        ->toBe(SalesOrderStatus::DRAFT);

    expect(SalesOrderProgress::statusFor($items, SalesOrderStatus::PARTIALLY_DELIVERED))
        ->toBe(SalesOrderStatus::APPROVED);

    expect(SalesOrderProgress::statusFor($items, SalesOrderStatus::DELIVERED))
        ->toBe(SalesOrderStatus::APPROVED);
});

test('returns delivered status when all items are fully delivered or exceeded', function (): void {
    $items = [
        ['quantity' => '10.0000', 'delivered_quantity' => '10.0000'],
        ['quantity' => '5.0000', 'delivered_quantity' => '5.5000'],
    ];

    expect(SalesOrderProgress::statusFor($items, SalesOrderStatus::APPROVED))
        ->toBe(SalesOrderStatus::DELIVERED);

    expect(SalesOrderProgress::statusFor($items, SalesOrderStatus::PARTIALLY_DELIVERED))
        ->toBe(SalesOrderStatus::DELIVERED);
});

test('returns partially_delivered when some items are delivered but not all completed', function (): void {
    $items = [
        ['quantity' => '10.0000', 'delivered_quantity' => '10.0000'],
        ['quantity' => '5.0000', 'delivered_quantity' => '2.0000'],
    ];

    expect(SalesOrderProgress::statusFor($items, SalesOrderStatus::APPROVED))
        ->toBe(SalesOrderStatus::PARTIALLY_DELIVERED);

    expect(SalesOrderProgress::statusFor($items, SalesOrderStatus::DELIVERED))
        ->toBe(SalesOrderStatus::PARTIALLY_DELIVERED);
});
