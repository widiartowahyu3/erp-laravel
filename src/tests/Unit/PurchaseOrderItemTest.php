<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PurchaseOrderItem;

test('PurchaseOrderItem remainingQuantity and isFullyReceived maintain received <= quantity invariant', function (): void {
    // 1. Normal partial received
    $item1 = new PurchaseOrderItem([
        'quantity' => '10.0000',
        'received_quantity' => '4.0000',
    ]);
    expect($item1->remainingQuantity())->toBe('6.0000')
        ->and($item1->isFullyReceived())->toBeFalse();

    // 2. Fully received
    $item2 = new PurchaseOrderItem([
        'quantity' => '10.0000',
        'received_quantity' => '10.0000',
    ]);
    expect($item2->remainingQuantity())->toBe('0.0000')
        ->and($item2->isFullyReceived())->toBeTrue();

    // 3. Corrupted or over-received invariant clamp
    $item3 = new PurchaseOrderItem([
        'quantity' => '10.0000',
        'received_quantity' => '12.5000',
    ]);
    expect($item3->remainingQuantity())->toBe('0.0000')
        ->and($item3->isFullyReceived())->toBeTrue();
});
