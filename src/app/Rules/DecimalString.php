<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Decimal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final class DecimalString implements ValidationRule
{
    /**
     * @param  int  $maxInteger  Maximum number of digits before the decimal point
     * @param  int  $scale  Maximum number of fractional decimal places
     * @param  string|null  $min  Minimum allowable value (inclusive) or null
     * @param  string|null  $max  Maximum allowable value (inclusive) or null
     */
    public function __construct(
        private readonly int $maxInteger = 16,
        private readonly int $scale = 2,
        private readonly ?string $min = '0',
        private readonly ?string $max = null,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            $fail('Kolom :attribute harus berupa teks desimal yang valid.');

            return;
        }

        $str = trim((string) $value);

        // Strict signed or unsigned numeric string pattern
        if (! preg_match('/^-?\d+(\.\d+)?$/', $str)) {
            $fail('Format angka pada kolom :attribute tidak valid.');

            return;
        }

        // Split into sign, integer, and fractional parts
        $unsigned = ltrim($str, '-');
        $parts = explode('.', $unsigned);
        $integerPart = $parts[0];
        $fractionalPart = $parts[1] ?? '';

        if (strlen($integerPart) > $this->maxInteger) {
            $fail("Jumlah digit bulat pada kolom :attribute melebihi batas maksimal {$this->maxInteger} digit.");

            return;
        }

        if (strlen($fractionalPart) > $this->scale) {
            $fail("Jumlah angka desimal di belakang koma pada kolom :attribute melebihi batas maksimal {$this->scale} digit.");

            return;
        }

        if ($this->min !== null && Decimal::cmp($str, $this->min) < 0) {
            $fail("Nilai kolom :attribute tidak boleh kurang dari {$this->min}.");

            return;
        }

        if ($this->max !== null && Decimal::cmp($str, $this->max) > 0) {
            $fail("Nilai kolom :attribute tidak boleh lebih dari {$this->max}.");

            return;
        }
    }
}
