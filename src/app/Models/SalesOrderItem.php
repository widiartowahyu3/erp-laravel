<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sales_order_id
 * @property int $line_no
 * @property int $product_id
 * @property string $quantity
 * @property string $delivered_quantity
 * @property string $unit_price
 * @property string $discount_amount
 * @property int|null $tax_rate_id
 * @property string $tax_amount
 * @property string $line_total
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SalesOrder $salesOrder
 * @property-read Product $product
 * @property-read TaxRate|null $taxRate
 */
class SalesOrderItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'sales_order_id',
        'line_no',
        'product_id',
        'quantity',
        'delivered_quantity',
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
            'delivered_quantity' => 'string',
            'unit_price' => 'string',
            'discount_amount' => 'string',
            'tax_amount' => 'string',
            'line_total' => 'string',
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
        $rem = Decimal::sub($this->quantity, $this->delivered_quantity, Decimal::SCALE_QUANTITY);

        return Decimal::isNegative($rem) ? '0.0000' : $rem;
    }

    public function isFullyDelivered(): bool
    {
        return Decimal::cmp($this->delivered_quantity, $this->quantity) >= 0;
    }
}
