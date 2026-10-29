<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAuditUsers;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxRate extends Model
{
    use HasAuditUsers, RecordsAudit, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'rate',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'string',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Alias rate_percent to rate.
     *
     * @return Attribute<string, string>
     */
    protected function ratePercent(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes): string => (string) ($attributes['rate'] ?? '0.00'),
            set: fn (string $value): array => ['rate' => $value],
        );
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
                ->orWhere('name', 'like', "%{$clean}%");
        });
    }
}
