<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

trait RecordsAudit
{
    /**
     * Pending updates store bound strictly to the model instance.
     * Declared explicitly on the trait so Eloquent never treats it as a model database attribute.
     *
     * @var array{old: array<string, mixed>, new: array<string, mixed>}|null
     */
    protected ?array $auditPendingChanges = null;

    /**
     * Boot the records audit trait for an Eloquent model.
     */
    protected static function bootRecordsAudit(): void
    {
        static::saving(function ($model): void {
            // Guarantee fresh slate for every save cycle
            $model->auditPendingChanges = null;
        });

        static::created(function ($model): void {
            $attributes = $model->filterAuditAttributes($model->getAttributes());

            AuditLogger::record(
                event: "{$model->getAuditAlias()}.created",
                auditableType: $model->getMorphClass(),
                auditableId: (int) $model->getKey(),
                old: null,
                new: $attributes,
                userId: Auth::id() ? (int) Auth::id() : null
            );
        });

        static::updating(function ($model): void {
            $old = [];
            $new = [];

            foreach ($model->getDirty() as $key => $newValue) {
                if ($key === 'updated_at') {
                    continue;
                }
                $old[$key] = $model->getOriginal($key);
                $new[$key] = $newValue;
            }

            $model->auditPendingChanges = [
                'old' => $old,
                'new' => $new,
            ];
        });

        static::updated(function ($model): void {
            $pending = $model->auditPendingChanges;
            $model->auditPendingChanges = null;

            $rawOld = $pending['old'] ?? [];
            $rawNew = $pending['new'] ?? [];

            $old = $model->filterAuditAttributes($rawOld);
            $new = $model->filterAuditAttributes($rawNew);

            if (empty($old) && empty($new)) {
                return;
            }

            AuditLogger::record(
                event: "{$model->getAuditAlias()}.updated",
                auditableType: $model->getMorphClass(),
                auditableId: (int) $model->getKey(),
                old: $old,
                new: $new,
                userId: Auth::id() ? (int) Auth::id() : null
            );
        });

        static::saved(function ($model): void {
            // Guaranteed cleanup
            $model->auditPendingChanges = null;
        });

        static::deleted(function ($model): void {
            $attributes = $model->filterAuditAttributes($model->getAttributes());

            AuditLogger::record(
                event: "{$model->getAuditAlias()}.deleted",
                auditableType: $model->getMorphClass(),
                auditableId: (int) $model->getKey(),
                old: $attributes,
                new: null,
                userId: Auth::id() ? (int) Auth::id() : null
            );
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function ($model): void {
                $attributes = $model->filterAuditAttributes($model->getAttributes());

                AuditLogger::record(
                    event: "{$model->getAuditAlias()}.restored",
                    auditableType: $model->getMorphClass(),
                    auditableId: (int) $model->getKey(),
                    old: null,
                    new: $attributes,
                    userId: Auth::id() ? (int) Auth::id() : null
                );
            });
        }
    }

    /**
     * Get the alias used for audit event naming (e.g., 'unit' in 'unit.created').
     */
    public function getAuditAlias(): string
    {
        if (property_exists($this, 'auditAlias') && is_string($this->auditAlias) && $this->auditAlias !== '') {
            return $this->auditAlias;
        }

        return Str::snake(class_basename($this));
    }

    /**
     * Filter out updated_at and sensitive attributes from audit payload.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function filterAuditAttributes(array $attributes): array
    {
        unset($attributes['updated_at']);

        foreach ($this->getHidden() as $hiddenKey) {
            unset($attributes[$hiddenKey]);
        }

        return AuditLogger::sanitizePayload($attributes);
    }
}
