<?php

declare(strict_types=1);

use App\Console\Commands\SyncFrontendEnumsCommand;
use Illuminate\Support\Facades\File;

test('resources/js/generated/enums.json is up to date with backend PHP enums', function () {
    $targetPath = resource_path('js/generated/enums.json');

    expect(File::exists($targetPath))->toBeTrue(
        'File enums.json belum ada. Jalankan php artisan erp:frontend:sync-enums'
    );

    $fileContent = (string) File::get($targetPath);
    /** @var array<string, array{label: string, variant: string}> $fileMap */
    $fileMap = json_decode($fileContent, true);

    $expectedMap = SyncFrontendEnumsCommand::generateMap();

    expect($fileMap)->toBe(
        $expectedMap,
        'File resources/js/generated/enums.json tidak sinkron dengan enum PHP backend. Jalankan php artisan erp:frontend:sync-enums'
    );
});
