<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Inventory\MovingAverageCost;
use InvalidArgumentException;
use TypeError;

test('afterReceipt computes moving average cost for exact reference values', function (): void {
    // 0.0000 @ 0.0000 + 10 @ 1000 -> 1000.0000
    expect(MovingAverageCost::afterReceipt('0.0000', '0.0000', '10', '1000'))
        ->toBe('1000.0000');

    // 10 @ 1000 + 5 @ 1300 -> 1100.0000
    expect(MovingAverageCost::afterReceipt('10', '1000', '5', '1300'))
        ->toBe('1100.0000');

    // 11 @ 1100 + 3 @ 1000 -> 1078.5714
    expect(MovingAverageCost::afterReceipt('11', '1100', '3', '1000'))
        ->toBe('1078.5714');

    // 10 @ 1100 + 2 @ 1200 -> 1116.6667
    expect(MovingAverageCost::afterReceipt('10', '1100', '2', '1200'))
        ->toBe('1116.6667');

    // 1 @ 1.0000 + 2 @ 1.0001 -> 1.0001
    expect(MovingAverageCost::afterReceipt('1', '1.0000', '2', '1.0001'))
        ->toBe('1.0001');

    // qty lama 0 (avg lama berapa pun) + 2 @ 500 -> 500.0000
    expect(MovingAverageCost::afterReceipt('0', '9999.9999', '2', '500'))
        ->toBe('500.0000');
    expect(MovingAverageCost::afterReceipt('0.0000', '1234.5678', '2', '500'))
        ->toBe('500.0000');
});

test('afterReceipt strictly rejects invalid inputs with specific exceptions and messages', function (): void {
    // inQuantity 0
    expect(fn () => MovingAverageCost::afterReceipt('10', '1000', '0', '1000'))
        ->toThrow(InvalidArgumentException::class, "inQuantity must be greater than zero, got '0'.");

    // inQuantity negatif
    expect(fn () => MovingAverageCost::afterReceipt('10', '1000', '-5', '1000'))
        ->toThrow(InvalidArgumentException::class, "inQuantity must be greater than zero, got '-5'.");

    // inUnitCost negatif
    expect(fn () => MovingAverageCost::afterReceipt('10', '1000', '5', '-100'))
        ->toThrow(InvalidArgumentException::class, "inUnitCost must be non-negative, got '-100'.");

    // oldQuantity negatif
    expect(fn () => MovingAverageCost::afterReceipt('-1', '1000', '5', '1000'))
        ->toThrow(InvalidArgumentException::class, "oldQuantity must be non-negative, got '-1'.");

    // oldAvgCost negatif
    expect(fn () => MovingAverageCost::afterReceipt('10', '-100', '5', '1000'))
        ->toThrow(InvalidArgumentException::class, "oldAvgCost must be non-negative, got '-100'.");

    // non-angka
    expect(fn () => MovingAverageCost::afterReceipt('abc', '1000', '5', '1000'))
        ->toThrow(InvalidArgumentException::class, "Invalid numeric string: 'abc'");
    expect(fn () => MovingAverageCost::afterReceipt('10', '1000', 'xyz', '1000'))
        ->toThrow(InvalidArgumentException::class, "Invalid numeric string: 'xyz'");

    // float rejected with TypeError due to strict_types
    $fn = fn ($val) => MovingAverageCost::afterReceipt('10', '1000', $val, '1000');
    expect(fn () => $fn(1.5))->toThrow(TypeError::class);
});
