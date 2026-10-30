<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_adjustment_id
 * @property int $line_no
 * @property int $product_id
 * @property string $system_quantity
 * @property string $counted_quantity
 * @property string $difference_quantity
 * @property string $unit_cost
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StockAdjustment $adjustment
 * @property-read Product $product
 */
class StockAdjustmentItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'stock_adjustment_id',
        'line_no',
        'product_id',
        'system_quantity',
        'counted_quantity',
        'difference_quantity',
        'unit_cost',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock_adjustment_id' => 'integer',
            'line_no' => 'integer',
            'product_id' => 'integer',
            'system_quantity' => 'string',
            'counted_quantity' => 'string',
            'difference_quantity' => 'string',
            'unit_cost' => 'string',
        ];
    }

    /**
     * @return BelongsTo<StockAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
