<?php

declare(strict_types=1);

namespace App\Support;

final class Decimal
{
    public const int SCALE_MONEY = 2;

    public const int SCALE_QUANTITY = 4;

    public const int SCALE_COST = 4;

    public const int SCALE_PERCENT = 2;

    private function __construct() {}

    /**
     * Add two decimal values and return the result formatted to the given scale.
     */
    public static function add(string|int $a, string|int $b, int $scale = self::SCALE_MONEY): string
    {
        $valA = self::sanitize($a);
        $valB = self::sanitize($b);

        $sum = bcadd($valA, $valB, $scale + 4);

        return self::round($sum, $scale);
    }

    /**
     * Subtract $b from $a and return the result formatted to the given scale.
     */
    public static function sub(string|int $a, string|int $b, int $scale = self::SCALE_MONEY): string
    {
        $valA = self::sanitize($a);
        $valB = self::sanitize($b);

        $diff = bcsub($valA, $valB, $scale + 4);

        return self::round($diff, $scale);
    }

    /**
     * Multiply two decimal values and return the result formatted to the given scale.
     */
    public static function mul(string|int $a, string|int $b, int $scale = self::SCALE_MONEY): string
    {
        $valA = self::sanitize($a);
        $valB = self::sanitize($b);

        $product = bcmul($valA, $valB, $scale + 4);

        return self::round($product, $scale);
    }

    /**
     * Divide $a by $b and return the result formatted to the given scale.
     *
     * @throws \DivisionByZeroError
     */
    public static function div(string|int $a, string|int $b, int $scale = self::SCALE_MONEY): string
    {
        $valA = self::sanitize($a);
        $valB = self::sanitize($b);

        if (self::isZero($valB)) {
            throw new \DivisionByZeroError('Division by zero in decimal arithmetic.');
        }

        $quotient = bcdiv($valA, $valB, $scale + 4);

        return self::round($quotient, $scale);
    }

    /**
     * Compare $a and $b. Returns -1 if $a < $b, 0 if $a == $b, 1 if $a > $b.
     */
    public static function cmp(string|int $a, string|int $b, ?int $scale = null): int
    {
        $valA = self::sanitize($a);
        $valB = self::sanitize($b);

        $effectiveScale = $scale ?? max(self::detectScale($valA), self::detectScale($valB));

        return bccomp($valA, $valB, $effectiveScale);
    }

    /**
     * Round a decimal value half-up away from zero to the given scale.
     */
    public static function round(string|int $value, int $scale = self::SCALE_MONEY): string
    {
        if ($scale < 0) {
            throw new \InvalidArgumentException("Scale must be non-negative, got {$scale}.");
        }

        $val = self::sanitize($value);
        $isNegative = str_starts_with($val, '-');
        $abs = $isNegative ? substr($val, 1) : $val;

        $half = $scale === 0 ? '0.5' : '0.'.str_repeat('0', $scale).'5';
        $added = bcadd($abs, $half, $scale + 1);
        $rounded = bcadd($added, '0', $scale);

        if (bccomp($rounded, '0', $scale) === 0) {
            return $rounded;
        }

        return $isNegative ? '-'.$rounded : $rounded;
    }

    /**
     * Check if a decimal value is strictly negative (< 0).
     */
    public static function isNegative(string|int $value): bool
    {
        $val = self::sanitize($value);
        $scale = max(self::detectScale($val), 4);

        return bccomp($val, '0', $scale) < 0;
    }

    /**
     * Check if a decimal value is numerically zero (== 0).
     */
    public static function isZero(string|int $value): bool
    {
        $val = self::sanitize($value);
        $scale = max(self::detectScale($val), 4);

        return bccomp($val, '0', $scale) === 0;
    }

    /**
     * Normalize a decimal string by stripping redundant trailing zeros and decimal point.
     */
    public static function normalize(string|int $value): string
    {
        $val = self::sanitize($value);

        if (self::isZero($val)) {
            return '0';
        }

        $isNegative = str_starts_with($val, '-');
        $abs = $isNegative ? substr($val, 1) : $val;

        if (str_contains($abs, '.')) {
            $abs = rtrim(rtrim($abs, '0'), '.');
        }

        return $isNegative ? '-'.$abs : $abs;
    }

    /**
     * Validate and sanitize input to a clean decimal string.
     * Strictly rejects floats and non-numeric strings.
     */
    private static function sanitize(mixed $value): string
    {
        if (is_float($value)) {
            throw new \InvalidArgumentException('Floating-point values are strictly forbidden in decimal arithmetic.');
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new \InvalidArgumentException('Value must be a numeric string or integer.');
        }

        $str = trim((string) $value);

        if ($str === '' || preg_match('/^[+-]?[0-9]+(\.[0-9]+)?$/', $str) !== 1) {
            throw new \InvalidArgumentException("Invalid numeric string: '{$value}'");
        }

        if (str_starts_with($str, '+')) {
            $str = substr($str, 1);
        }

        return $str;
    }

    /**
     * Detect the number of decimal digits in a decimal string.
     */
    private static function detectScale(string $val): int
    {
        $dotPos = strpos($val, '.');

        return $dotPos === false ? 0 : strlen($val) - $dotPos - 1;
    }
}
