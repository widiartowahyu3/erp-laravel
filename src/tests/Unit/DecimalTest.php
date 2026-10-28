<?php

declare(strict_types=1);

use App\Support\Decimal;

test('constants have expected scale values', function () {
    expect(Decimal::SCALE_MONEY)->toBe(2)
        ->and(Decimal::SCALE_QUANTITY)->toBe(4)
        ->and(Decimal::SCALE_COST)->toBe(4)
        ->and(Decimal::SCALE_PERCENT)->toBe(2);
});

test('add calculates 0.1 + 0.2 as 0.30 with default money scale', function () {
    $result = Decimal::add('0.1', '0.2');

    expect($result)->toBe('0.30');
});

test('add handles large 18 digit values without precision loss', function () {
    $a = '999999999999999999';
    $b = '1';

    $result = Decimal::add($a, $b, Decimal::SCALE_MONEY);

    expect($result)->toBe('1000000000000000000.00');
});

test('sub handles standard, negative and multi-precision subtractions', function () {
    expect(Decimal::sub('10.00', '3.50'))->toBe('6.50')
        ->and(Decimal::sub('3.50', '10.00'))->toBe('-6.50')
        ->and(Decimal::sub('100', '0.0001', Decimal::SCALE_QUANTITY))->toBe('99.9999');
});

test('round performs half-up away from zero for positive and negative values', function (
    string $input,
    int $scale,
    string $expected
) {
    expect(Decimal::round($input, $scale))->toBe($expected);
})->with([
    ['1.005', 2, '1.01'],
    ['-1.005', 2, '-1.01'],
    ['1.004', 2, '1.00'],
    ['-1.004', 2, '-1.00'],
    ['1.0055', 3, '1.006'],
    ['-1.0055', 3, '-1.006'],
    ['2.5', 0, '3'],
    ['-2.5', 0, '-3'],
    ['2.4', 0, '2'],
    ['-2.4', 0, '-2'],
    ['0.000', 2, '0.00'],
    ['-0.004', 2, '0.00'],
]);

test('div computes high scale division accurately', function () {
    $result = Decimal::div('1', '7', 8);

    expect($result)->toBe('0.14285714');
});

test('div throws DivisionByZeroError on zero divisor', function () {
    Decimal::div('100.00', '0');
})->throws(DivisionByZeroError::class);

test('div throws DivisionByZeroError on zero string variants', function () {
    Decimal::div('100.00', '0.0000');
})->throws(DivisionByZeroError::class);

test('cmp compares numbers with different scales accurately', function (
    string $a,
    string $b,
    int $expected
) {
    expect(Decimal::cmp($a, $b))->toBe($expected);
})->with([
    ['1.00', '1.0000', 0],
    ['2.5', '2.49', 1],
    ['2.49', '2.5', -1],
    ['-10.00', '-5.00', -1],
    ['-5.00', '-10.00', 1],
    ['0', '-0.00', 0],
]);

test('mul computes line_total from quantity and unit_price rounded to 2 decimals', function () {
    $qty = '3.5000';
    $unitPrice = '12.5050';

    // 3.5000 * 12.5050 = 43.7675 -> rounded to 2 decimals is 43.77
    $lineTotal = Decimal::mul($qty, $unitPrice, Decimal::SCALE_MONEY);

    expect($lineTotal)->toBe('43.77');
});

test('isNegative and isZero evaluate values correctly', function () {
    expect(Decimal::isNegative('-0.0001'))->toBeTrue()
        ->and(Decimal::isNegative('-1'))->toBeTrue()
        ->and(Decimal::isNegative('0'))->toBeFalse()
        ->and(Decimal::isNegative('-0.00'))->toBeFalse()
        ->and(Decimal::isNegative('0.0001'))->toBeFalse()
        ->and(Decimal::isZero('0'))->toBeTrue()
        ->and(Decimal::isZero('0.0000'))->toBeTrue()
        ->and(Decimal::isZero('-0.00'))->toBeTrue()
        ->and(Decimal::isZero('0.0001'))->toBeFalse()
        ->and(Decimal::isZero('-0.0001'))->toBeFalse();
});

test('normalize strips redundant trailing zeros and decimal point', function (string $input, string $expected) {
    expect(Decimal::normalize($input))->toBe($expected);
})->with([
    ['12.3400', '12.34'],
    ['12.0000', '12'],
    ['0.0000', '0'],
    ['-0.00', '0'],
    ['-12.30', '-12.3'],
    ['-100.000', '-100'],
    ['500', '500'],
]);

test('strictly rejects float inputs with TypeError or InvalidArgumentException', function () {
    // Calling via variable to bypass strict PHPStan check if any
    $fn = fn ($v) => Decimal::add($v, '1.00');

    expect(fn () => $fn(0.1))->toThrow(TypeError::class);
});

test('strictly rejects non-numeric string inputs', function (string $invalid) {
    expect(fn () => Decimal::add($invalid, '1.00'))->toThrow(InvalidArgumentException::class);
})->with([
    'letters' => ['abc'],
    'multiple dots' => ['12.34.56'],
    'empty string' => [''],
    'scientific notation' => ['1e5'],
    'whitespace only' => ['   '],
]);
