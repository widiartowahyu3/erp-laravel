<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GoodsReceiptStatus;
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
 * @property int $purchase_order_id
 * @property int $supplier_id
 * @property int $warehouse_id
 * @property CarbonImmutable|string $receipt_date
 * @property GoodsReceiptStatus $status
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
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Supplier $supplier
 * @property-read Warehouse $warehouse
 * @property-read Collection<int, GoodsReceiptItem> $items
 * @property-read User|null $poster
 * @property-read User|null $voider
 * @property-read User|null $creator
 * @property-read User|null $updater
 *
 * @method static void restored(\Closure $callback)
 */
class GoodsReceipt extends Model
{
    use HasAuditUsers, RecordsAudit;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'purchase_order_id',
        'supplier_id',
        'warehouse_id',
        'receipt_date',
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
            'receipt_date' => 'date',
            'status' => GoodsReceiptStatus::class,
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<GoodsReceiptItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class)->orderBy('line_no');
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

    public function isEditable(): bool
    {
        return $this->status === GoodsReceiptStatus::DRAFT;
    }
}
