<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property CarbonImmutable $movement_date
 * @property int $product_id
 * @property int $warehouse_id
 * @property StockMovementType $movement_type
 * @property string $quantity
 * @property string $unit_cost
 * @property string $balance_after
 * @property string $avg_cost_after
 * @property string $source_type
 * @property int $source_id
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read Product $product
 * @property-read Warehouse $warehouse
 * @property-read User|null $creator
 */
class StockMovement extends Model
{
    public const ?string UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'movement_date',
        'product_id',
        'warehouse_id',
        'movement_type',
        'quantity',
        'unit_cost',
        'balance_after',
        'avg_cost_after',
        'source_type',
        'source_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_date' => 'datetime',
            'product_id' => 'integer',
            'warehouse_id' => 'integer',
            'movement_type' => StockMovementType::class,
            'quantity' => 'string',
            'unit_cost' => 'string',
            'balance_after' => 'string',
            'avg_cost_after' => 'string',
            'source_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('stock_movements is append-only');
        });

        static::deleting(function (): void {
            throw new LogicException('stock_movements is append-only');
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
