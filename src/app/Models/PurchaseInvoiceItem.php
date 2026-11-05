<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_invoice_id
 * @property int $line_no
 * @property int|null $product_id
 * @property int|null $goods_receipt_item_id
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $discount_amount
 * @property int|null $tax_rate_id
 * @property string $tax_amount
 * @property string $line_total
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PurchaseInvoice $purchaseInvoice
 * @property-read Product|null $product
 * @property-read GoodsReceiptItem|null $goodsReceiptItem
 * @property-read TaxRate|null $taxRate
 */
class PurchaseInvoiceItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'purchase_invoice_id',
        'line_no',
        'product_id',
        'goods_receipt_item_id',
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
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<GoodsReceiptItem, $this>
     */
    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
