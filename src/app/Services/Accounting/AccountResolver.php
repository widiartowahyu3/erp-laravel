<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\BusinessRuleException;
use App\Models\ChartOfAccount;
use LogicException;

/**
 * Resolves logical account keys and account codes to postable account ids.
 * Results are cached on this instance only, never across requests.
 */
final class AccountResolver
{
    /**
     * @var array<string, int>
     */
    private array $idsByCode = [];

    /**
     * Resolve a logical key from config/accounting.php to an account id.
     *
     * @throws LogicException When the key is not mapped.
     * @throws BusinessRuleException When the mapped account cannot be posted to.
     */
    public function id(string $key): int
    {
        $code = config("accounting.accounts.{$key}");

        if (! is_string($code)) {
            throw new LogicException("Unknown accounting key '{$key}'.");
        }

        return $this->idByCode($code);
    }

    /**
     * Resolve an account code to an account id.
     *
     * @throws BusinessRuleException When the account is missing, inactive or a header account.
     */
    public function idByCode(string $code): int
    {
        if (isset($this->idsByCode[$code])) {
            return $this->idsByCode[$code];
        }

        $account = ChartOfAccount::query()->where('code', $code)->first();

        if ($account === null) {
            throw new BusinessRuleException("Akun {$code} tidak ditemukan pada bagan akun.", 422, 'account_id');
        }

        if (! $account->is_active) {
            throw new BusinessRuleException("Akun {$code} tidak aktif.", 422, 'account_id');
        }

        if (! $account->is_postable) {
            throw new BusinessRuleException("Akun {$code} adalah akun induk dan tidak dapat diposting.", 422, 'account_id');
        }

        return $this->idsByCode[$code] = (int) $account->id;
    }
}
