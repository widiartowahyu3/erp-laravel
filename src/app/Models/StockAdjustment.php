<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockAdjustmentStatus;
use App\Models\Concerns\HasAuditUsers;
use App\Models\Concerns\RecordsAudit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $number
 * @property int $warehouse_id
 * @property CarbonImmutable $adjustment_date
 * @property string $reason
 * @property StockAdjustmentStatus $status
 * @property string|null $notes
 * @property int|null $posted_by
 * @property CarbonImmutable|null $posted_at
 * @property int|null $voided_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Warehouse $warehouse
 * @property-read Collection<int, StockAdjustmentItem> $items
 * @property-read User|null $poster
 * @property-read User|null $voider
 *
 * @method static void restored(\Closure $callback)
 */
class StockAdjustment extends Model
{
    use HasAuditUsers, RecordsAudit;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'warehouse_id',
        'adjustment_date',
        'reason',
        'status',
        'notes',
        'posted_by',
        'posted_at',
        'voided_by',
        'voided_at',
        'void_reason',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'warehouse_id' => 'integer',
            'adjustment_date' => 'date',
            'status' => StockAdjustmentStatus::class,
            'posted_by' => 'integer',
            'posted_at' => 'datetime',
            'voided_by' => 'integer',
            'voided_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<StockAdjustmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
