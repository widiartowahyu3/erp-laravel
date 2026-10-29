<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAuditUsers;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use HasAuditUsers, RecordsAudit, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
    ];

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'base_unit_id');
    }

    /**
     * Filter active records (no is_active column on units; soft delete handles active).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('deleted_at');
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
