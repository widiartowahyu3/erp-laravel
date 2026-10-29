<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAuditUsers;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasAuditUsers, RecordsAudit, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sku',
        'name',
        'category_id',
        'base_unit_id',
        'barcode',
        'description',
        'track_stock',
        'selling_price',
        'min_stock',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'base_unit_id' => 'integer',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
            'selling_price' => 'string',
            'min_stock' => 'string',
        ];
    }

    /**
     * Alias code to sku.
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes): string => (string) ($attributes['sku'] ?? ''),
            set: fn (string $value): array => ['sku' => $value],
        );
    }

    /**
     * Alias unit_id to base_unit_id.
     *
     * @return Attribute<int, int>
     */
    protected function unitId(): Attribute
    {
        return Attribute::make(
            get: fn (?int $value, array $attributes): int => (int) ($attributes['base_unit_id'] ?? 0),
            set: fn (int $value): array => ['base_unit_id' => $value],
        );
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * Alias for baseUnit relation.
     *
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->baseUnit();
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
            $q->where('sku', 'like', "%{$clean}%")
                ->orWhere('name', 'like', "%{$clean}%")
                ->orWhere('barcode', 'like', "%{$clean}%");
        });
    }
}
