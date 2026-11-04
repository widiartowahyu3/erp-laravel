<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JournalStatus;
use App\Models\Concerns\HasAuditUsers;
use App\Models\Concerns\RecordsAudit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $number
 * @property CarbonImmutable|string $journal_date
 * @property string $description
 * @property string|null $source_type
 * @property int|null $source_id
 * @property JournalStatus $status
 * @property string $total_debit
 * @property string $total_credit
 * @property int|null $reversal_of_id
 * @property int|null $posted_by
 * @property CarbonImmutable|null $posted_at
 * @property int|null $voided_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property-read Collection<int, JournalLine> $lines
 * @property-read Journal|null $reversalOf
 * @property-read Journal|null $reversal
 *
 * @method static void restored(\Closure $callback)
 */
class Journal extends Model
{
    use HasAuditUsers, RecordsAudit;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'journal_date',
        'description',
        'source_type',
        'source_id',
        'status',
        'total_debit',
        'total_credit',
        'reversal_of_id',
        'posted_by',
        'posted_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'journal_date' => 'date',
            'source_id' => 'integer',
            'status' => JournalStatus::class,
            'total_debit' => 'string',
            'total_credit' => 'string',
            'reversal_of_id' => 'integer',
            'posted_by' => 'integer',
            'posted_at' => 'datetime',
            'voided_by' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return $this->status === JournalStatus::DRAFT;
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * @return HasOne<self, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
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
}
