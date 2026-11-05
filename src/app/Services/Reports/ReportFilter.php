<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;

/**
 * What a report was asked for. Reports only read, so this holds no behaviour beyond checking its own period.
 */
final readonly class ReportFilter
{
    private const int MAX_PERIOD_YEARS = 5;

    public function __construct(
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?string $asOf = null,
        public ?int $accountId = null,
        public ?int $customerId = null,
        public int $page = 1,
        public int $perPage = 50,
    ) {}

    /**
     * @return array{string, string} the checked start and end dates
     *
     * @throws BusinessRuleException
     */
    public function period(): array
    {
        if ($this->dateFrom === null || $this->dateFrom === '') {
            throw new BusinessRuleException('Tanggal awal wajib diisi.', 422, 'date_from');
        }

        if ($this->dateTo === null || $this->dateTo === '') {
            throw new BusinessRuleException('Tanggal akhir wajib diisi.', 422, 'date_to');
        }

        $from = CarbonImmutable::parse($this->dateFrom)->startOfDay();
        $to = CarbonImmutable::parse($this->dateTo)->startOfDay();

        if ($from->greaterThan($to)) {
            throw new BusinessRuleException('Tanggal akhir tidak boleh sebelum tanggal awal.', 422, 'date_to');
        }

        if ($to->greaterThan($from->addYears(self::MAX_PERIOD_YEARS))) {
            throw new BusinessRuleException('Rentang tanggal maksimal 5 tahun.', 422, 'date_to');
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * The balance date, today by default.
     */
    public function balanceDate(): string
    {
        return $this->asOf === null || $this->asOf === ''
            ? now()->toDateString()
            : CarbonImmutable::parse($this->asOf)->toDateString();
    }
}
