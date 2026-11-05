<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\Decimal;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stock per warehouse and product. It holds quantities only, because warehouse staff read it and may not see cost.
 * A product's minimum applies per warehouse, the same way the stock list reads it.
 */
final class InventorySummaryReport
{
    public function handle(ReportFilter $filter): ReportResult
    {
        $counts = $this->balances($filter)
            ->selectRaw('COUNT(*) as line_count, COUNT(DISTINCT b.product_id) as product_count, COUNT(DISTINCT b.warehouse_id) as warehouse_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN b.quantity < p.min_stock THEN 1 ELSE 0 END), 0) as below_minimum_count')
            ->first();

        $total = (int) $counts->line_count;
        $perPage = max(1, $filter->perPage);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $filter->page), $lastPage);
        $offset = ($page - 1) * $perPage;

        $rows = $this->ordered($filter)->offset($offset)->limit($perPage)->get()
            ->map(fn (object $line): array => $this->rowFor($line))
            ->all();

        return new ReportResult(
            $rows,
            [
                'product_count' => (int) $counts->product_count,
                'warehouse_count' => (int) $counts->warehouse_count,
                'below_minimum_count' => (int) $counts->below_minimum_count,
            ],
            [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? null : $offset + 1,
                'to' => $total === 0 ? null : $offset + count($rows),
            ]
        );
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function exportRows(ReportFilter $filter): Generator
    {
        foreach ($this->ordered($filter)->cursor() as $line) {
            yield $this->rowFor($line);
        }
    }

    public function lineCount(ReportFilter $filter): int
    {
        return $this->balances($filter)->count();
    }

    private function balances(ReportFilter $filter): Builder
    {
        return DB::table('stock_balances as b')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->when($filter->warehouseId !== null, fn (Builder $query) => $query->where('b.warehouse_id', $filter->warehouseId))
            ->when($filter->belowMinimumOnly, fn (Builder $query) => $query->whereColumn('b.quantity', '<', 'p.min_stock'));
    }

    private function ordered(ReportFilter $filter): Builder
    {
        return $this->balances($filter)
            ->orderBy('w.code')
            ->orderBy('p.sku')
            ->select(['b.warehouse_id', 'w.code as warehouse_code', 'w.name as warehouse_name', 'b.product_id', 'p.sku', 'p.name as product_name', 'b.quantity', 'p.min_stock']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(object $line): array
    {
        return [
            'warehouse_id' => (int) $line->warehouse_id,
            'warehouse_code' => (string) $line->warehouse_code,
            'warehouse_name' => (string) $line->warehouse_name,
            'product_id' => (int) $line->product_id,
            'sku' => (string) $line->sku,
            'product_name' => (string) $line->product_name,
            'quantity' => Decimal::round((string) $line->quantity, Decimal::SCALE_QUANTITY),
            'min_stock' => Decimal::round((string) $line->min_stock, Decimal::SCALE_QUANTITY),
            'below_minimum' => Decimal::cmp((string) $line->quantity, (string) $line->min_stock) < 0,
        ];
    }
}
