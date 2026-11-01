<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryOrderStatus;
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
 * @property int $sales_order_id
 * @property int $customer_id
 * @property int $warehouse_id
 * @property CarbonImmutable|string $delivery_date
 * @property string|null $shipping_address
 * @property DeliveryOrderStatus $status
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
 * @property-read SalesOrder $salesOrder
 * @property-read Customer $customer
 * @property-read Warehouse $warehouse
 * @property-read Collection<int, DeliveryOrderItem> $items
 * @property-read User|null $poster
 * @property-read User|null $voider
 * @property-read User|null $creator
 * @property-read User|null $updater
 *
 * @method static void restored(\Closure $callback)
 */
class DeliveryOrder extends Model
{
    use HasAuditUsers, RecordsAudit;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'sales_order_id',
        'customer_id',
        'warehouse_id',
        'delivery_date',
        'shipping_address',
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
            'delivery_date' => 'date',
            'status' => DeliveryOrderStatus::class,
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<DeliveryOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DeliveryOrderItem::class)->orderBy('line_no');
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
        return $this->status === DeliveryOrderStatus::DRAFT;
    }
}
