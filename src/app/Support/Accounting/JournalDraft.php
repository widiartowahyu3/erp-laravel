<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Support\Decimal;

/**
 * Plain description of an automatic journal. Accounts are logical keys from
 * config/accounting.php or account codes; zero amounts produce no line.
 */
final class JournalDraft
{
    /**
     * @var list<array{account: string, debit: string, credit: string, description: string|null}>
     */
    private array $lines = [];

    public function __construct(
        public readonly string $description
    ) {}

    public function debit(string $account, string $amount, ?string $description = null): self
    {
        return $this->addLine($account, $amount, '0.00', $description);
    }

    public function credit(string $account, string $amount, ?string $description = null): self
    {
        return $this->addLine($account, '0.00', $amount, $description);
    }

    /**
     * @return list<array{account: string, debit: string, credit: string, description: string|null}>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    private function addLine(string $account, string $debit, string $credit, ?string $description): self
    {
        $debit = Decimal::round($debit, Decimal::SCALE_MONEY);
        $credit = Decimal::round($credit, Decimal::SCALE_MONEY);

        if (Decimal::isZero($debit) && Decimal::isZero($credit)) {
            return $this;
        }

        $this->lines[] = [
            'account' => $account,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
        ];

        return $this;
    }
}
