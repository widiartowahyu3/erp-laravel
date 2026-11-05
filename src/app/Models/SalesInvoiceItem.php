<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sales_invoice_id
 * @property int $line_no
 * @property int|null $product_id
 * @property int|null $delivery_order_item_id
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $discount_amount
 * @property int|null $tax_rate_id
 * @property string $tax_amount
 * @property string $line_total
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SalesInvoice $salesInvoice
 * @property-read Product|null $product
 * @property-read DeliveryOrderItem|null $deliveryOrderItem
 * @property-read TaxRate|null $taxRate
 */
class SalesInvoiceItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'sales_invoice_id',
        'line_no',
        'product_id',
        'delivery_order_item_id',
        'description',
        'quantity',
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
            'unit_price' => 'string',
            'discount_amount' => 'string',
            'tax_amount' => 'string',
            'line_total' => 'string',
        ];
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<DeliveryOrderItem, $this>
     */
    public function deliveryOrderItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrderItem::class);
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
