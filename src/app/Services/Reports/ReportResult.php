<?php

declare(strict_types=1);

namespace App\Services\Reports;

final readonly class ReportResult
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $rows,
        public array $summary = [],
        public array $meta = [],
    ) {}
}
