<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAuditUsers;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasAuditUsers, RecordsAudit, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'tax_id',
        'credit_limit',
        'payment_term_id',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_limit' => 'string',
            'payment_term_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    /**
     * Filter active records.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Filter records matching search term.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || trim($term) === '') {
            return;
        }

        $clean = trim($term);
        $query->where(function (Builder $q) use ($clean): void {
            $q->where('code', 'like', "%{$clean}%")
                ->orWhere('name', 'like', "%{$clean}%")
                ->orWhere('contact_name', 'like', "%{$clean}%")
                ->orWhere('email', 'like', "%{$clean}%");
        });
    }
}
