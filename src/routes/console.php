<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Runs before the 02:00 backup suggested in docs/OPERATIONS.md, so the two never overlap.
Schedule::command('erp:audit:prune')->dailyAt('01:00')->withoutOverlapping(60);
