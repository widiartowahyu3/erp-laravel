<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
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
 * @property int $supplier_id
 * @property int $warehouse_id
 * @property CarbonImmutable|string $order_date
 * @property CarbonImmutable|string|null $expected_date
 * @property int|null $payment_term_id
 * @property PurchaseOrderStatus $status
 * @property string $subtotal
 * @property string $discount_amount
 * @property string $tax_amount
 * @property string $total
 * @property string|null $notes
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $voided_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Supplier $supplier
 * @property-read Warehouse $warehouse
 * @property-read PaymentTerm|null $paymentTerm
 * @property-read Collection<int, PurchaseOrderItem> $items
 * @property-read Collection<int, GoodsReceipt> $goodsReceipts
 * @property-read User|null $approver
 * @property-read User|null $voider
 * @property-read User|null $creator
 * @property-read User|null $updater
 *
 * @method static void restored(\Closure $callback)
 */
class PurchaseOrder extends Model
{
    use HasAuditUsers, RecordsAudit;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'supplier_id',
        'warehouse_id',
        'order_date',
        'expected_date',
        'payment_term_id',
        'status',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'notes',
        'approved_by',
        'approved_at',
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
            'order_date' => 'date',
            'expected_date' => 'date',
            'status' => PurchaseOrderStatus::class,
            'subtotal' => 'string',
            'discount_amount' => 'string',
            'tax_amount' => 'string',
            'total' => 'string',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
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
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    /**
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('line_no');
    }

    /**
     * @return HasMany<GoodsReceipt, $this>
     */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
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
        return $this->status === PurchaseOrderStatus::DRAFT;
    }
}
