<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $goods_receipt_id
 * @property int $line_no
 * @property int $purchase_order_item_id
 * @property int $product_id
 * @property string $quantity
 * @property string $unit_cost
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read GoodsReceipt $goodsReceipt
 * @property-read PurchaseOrderItem $purchaseOrderItem
 * @property-read Product $product
 */
class GoodsReceiptItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'goods_receipt_id',
        'line_no',
        'purchase_order_item_id',
        'product_id',
        'quantity',
        'unit_cost',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'string',
            'unit_cost' => 'string',
        ];
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /**
     * @return BelongsTo<PurchaseOrderItem, $this>
     */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
