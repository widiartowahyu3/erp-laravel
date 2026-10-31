<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Decimal;
use App\Support\Purchasing\PurchaseOrderCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int $line_no
 * @property int $product_id
 * @property string $quantity
 * @property string $received_quantity
 * @property string $unit_price
 * @property string $discount_amount
 * @property int|null $tax_rate_id
 * @property string $tax_amount
 * @property string $line_total
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Product $product
 * @property-read TaxRate|null $taxRate
 */
class PurchaseOrderItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'purchase_order_id',
        'line_no',
        'product_id',
        'quantity',
        'received_quantity',
        'unit_price',
        'discount_amount',
        'tax_rate_id',
        'tax_amount',
        'line_total',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'string',
            'received_quantity' => 'string',
            'unit_price' => 'string',
            'discount_amount' => 'string',
            'tax_amount' => 'string',
            'line_total' => 'string',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function remainingQuantity(): string
    {
        $rem = Decimal::sub($this->quantity, $this->received_quantity, Decimal::SCALE_QUANTITY);

        return Decimal::isNegative($rem) ? '0.0000' : $rem;
    }

    public function isFullyReceived(): bool
    {
        return Decimal::cmp($this->received_quantity, $this->quantity) >= 0;
    }

    public function netUnitCost(): string
    {
        return PurchaseOrderCalculator::netUnitCost($this->quantity, $this->unit_price, $this->discount_amount);
    }
}
