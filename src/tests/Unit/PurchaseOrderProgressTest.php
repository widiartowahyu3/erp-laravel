<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PurchaseOrderStatus;
use App\Support\Purchasing\PurchaseOrderProgress;

test('returns current status when item list is empty', function (): void {
    expect(PurchaseOrderProgress::statusFor([], PurchaseOrderStatus::APPROVED))
        ->toBe(PurchaseOrderStatus::APPROVED);
});

test('returns current status unchanged if closed or void', function (): void {
    $items = [
        ['quantity' => '10.0000', 'received_quantity' => '10.0000'],
    ];

    expect(PurchaseOrderProgress::statusFor($items, PurchaseOrderStatus::CLOSED))
        ->toBe(PurchaseOrderStatus::CLOSED);

    expect(PurchaseOrderProgress::statusFor($items, PurchaseOrderStatus::VOID))
        ->toBe(PurchaseOrderStatus::VOID);
});

test('retains approved or rolls back to approved when all received quantities are zero', function (): void {
    $items = [
        ['quantity' => '10.0000', 'received_quantity' => '0.0000'],
        ['quantity' => '5.0000', 'received_quantity' => '0.0000'],
    ];

    expect(PurchaseOrderProgress::statusFor($items, PurchaseOrderStatus::APPROVED))
        ->toBe(PurchaseOrderStatus::APPROVED);

    expect(PurchaseOrderProgress::statusFor($items, PurchaseOrderStatus::DRAFT))
        ->toBe(PurchaseOrderStatus::DRAFT);

    expect(PurchaseOrderProgress::statusFor($items, PurchaseOrderStatus::PARTIALLY_RECEIVED))
        ->toBe(PurchaseOrderStatus::APPROVED);

    expect(PurchaseOrderProgress::statusFor($items, PurchaseOrderStatus::RECEIVED))
        ->toBe(PurchaseOrderStatus::APPROVED);
});

test('returns received status when all items are fully received or exceeded', function (): void {
    $items = [
        ['quantity' => '10.0000', 'received_quantity' => '10.0000'],
        ['quantity' => '5.0000', 'received_quantity' => '5.5000'],
    ];

    expect(PurchaseOrderProgress::statusFor($items, PurchaseOrderStatus::APPROVED))
        ->toBe(PurchaseOrderStatus::RECEIVED);

    expect(PurchaseOrderProgress::statusFor($items, PurchaseOrderStatus::PARTIALLY_RECEIVED))
        ->toBe(PurchaseOrderStatus::RECEIVED);
});

test('returns partially_received when some items are received but not all completed', function (): void {
    $items = [
        ['quantity' => '10.0000', 'received_quantity' => '10.0000'],
        ['quantity' => '5.0000', 'received_quantity' => '2.0000'],
    ];

    expect(PurchaseOrderProgress::statusFor($items, PurchaseOrderStatus::APPROVED))
        ->toBe(PurchaseOrderStatus::PARTIALLY_RECEIVED);

    expect(PurchaseOrderProgress::statusFor($items, PurchaseOrderStatus::RECEIVED))
        ->toBe(PurchaseOrderStatus::PARTIALLY_RECEIVED);
});
