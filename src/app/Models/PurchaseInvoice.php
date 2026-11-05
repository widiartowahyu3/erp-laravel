<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\Concerns\HasAuditUsers;
use App\Models\Concerns\RecordsAudit;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $number
 * @property int $supplier_id
 * @property int|null $purchase_order_id
 * @property string $supplier_invoice_number
 * @property CarbonImmutable $invoice_date
 * @property CarbonImmutable $due_date
 * @property PurchaseInvoiceStatus $status
 * @property string $subtotal
 * @property string $discount_amount
 * @property string $tax_amount
 * @property string $total
 * @property string $paid_amount
 * @property int|null $journal_id
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
 * @property-read Supplier $supplier
 * @property-read PurchaseOrder|null $purchaseOrder
 * @property-read Journal|null $journal
 * @property-read Collection<int, PurchaseInvoiceItem> $items
 * @property-read User|null $poster
 * @property-read User|null $voider
 * @property-read User|null $creator
 * @property-read User|null $updater
 *
 * @method static void restored(\Closure $callback)
 */
class PurchaseInvoice extends Model
{
    use HasAuditUsers, RecordsAudit;

    /**
     * The source_type of this document's automatic journals.
     */
    public const string JOURNAL_SOURCE = 'purchase_invoice';

    /**
     * Statuses that count as billed and as money owed; drafts do not reserve anything.
     *
     * @var list<string>
     */
    public const array BILLED_STATUSES = [
        'posted',
        'partially_paid',
        'paid',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'supplier_id',
        'purchase_order_id',
        'supplier_invoice_number',
        'invoice_date',
        'due_date',
        'status',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'paid_amount',
        'journal_id',
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
            'invoice_date' => 'date',
            'due_date' => 'date',
            'status' => PurchaseInvoiceStatus::class,
            'subtotal' => 'string',
            'discount_amount' => 'string',
            'tax_amount' => 'string',
            'total' => 'string',
            'paid_amount' => 'string',
            'posted_at' => 'datetime',
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
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * @return HasMany<PurchaseInvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class)->orderBy('line_no');
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
        return $this->status === PurchaseInvoiceStatus::DRAFT;
    }

    public function outstanding(): string
    {
        return Decimal::sub($this->total, $this->paid_amount);
    }
}
