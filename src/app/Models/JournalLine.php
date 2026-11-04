<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $journal_id
 * @property int $line_no
 * @property int $account_id
 * @property string $debit
 * @property string $credit
 * @property string|null $description
 * @property-read Journal $journal
 * @property-read ChartOfAccount $account
 */
class JournalLine extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'journal_id',
        'line_no',
        'account_id',
        'debit',
        'credit',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'account_id' => 'integer',
            'debit' => 'string',
            'credit' => 'string',
        ];
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id')->withTrashed();
    }
}
