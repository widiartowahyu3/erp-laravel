<?php

declare(strict_types=1);

namespace Tests\Support\Models;

use App\Models\Concerns\HasAuditUsers;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TestUnit extends Model
{
    use HasAuditUsers;
    use RecordsAudit;
    use SoftDeletes;

    protected $table = 'units';

    protected $guarded = [];

    protected string $auditAlias = 'unit';

    /**
     * @var list<string>
     */
    protected $hidden = [
        'internal_secret',
    ];
}
