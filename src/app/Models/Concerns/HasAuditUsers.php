<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait HasAuditUsers
{
    /**
     * Boot the audit users trait for an Eloquent model.
     */
    protected static function bootHasAuditUsers(): void
    {
        static::creating(function ($model): void {
            $userId = Auth::id();

            if ($userId !== null) {
                if ($model->getAttribute('created_by') === null) {
                    $model->setAttribute('created_by', (int) $userId);
                }
                if ($model->getAttribute('updated_by') === null) {
                    $model->setAttribute('updated_by', (int) $userId);
                }
            }
        });

        static::updating(function ($model): void {
            $userId = Auth::id();

            if ($userId !== null && ! $model->isDirty('updated_by')) {
                $model->setAttribute('updated_by', (int) $userId);
            }
        });
    }

    /**
     * Get the user who created the record.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the record.
     *
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
