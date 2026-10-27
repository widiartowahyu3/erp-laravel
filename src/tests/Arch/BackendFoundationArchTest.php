<?php

declare(strict_types=1);

namespace Tests\Arch;

use BackedEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionEnum;

arch('controllers do not use DB facade directly')
    ->expect(DB::class)
    ->not->toBeUsedIn('App\Http\Controllers');

arch('enums in App\Enums are enums')
    ->expect('App\Enums')
    ->toBeEnums()
    ->toImplement(BackedEnum::class);

arch('classes in App\Support are final')
    ->expect('App\Support')
    ->classes()
    ->toBeFinal();

arch('classes in App\Http\Requests extend FormRequest')
    ->expect('App\Http\Requests')
    ->classes()
    ->toExtend(FormRequest::class);

arch('services do not depend on http layer')
    ->expect('App\Services')
    ->not->toUse('App\Http');

test('all enums in App\Enums are string-backed enums', function (): void {
    $enumFiles = glob(__DIR__.'/../../app/Enums/*.php') ?: [];
    expect($enumFiles)->not->toBeEmpty();

    foreach ($enumFiles as $file) {
        $className = 'App\\Enums\\'.basename($file, '.php');
        $reflection = new ReflectionEnum($className);
        expect($reflection->isBacked())->toBeTrue()
            ->and($reflection->getBackingType()?->getName())->toBe('string');
    }
});

test('all classes extending FormRequest reside in App\Http\Requests', function (): void {
    $appDir = realpath(__DIR__.'/../../app');
    expect($appDir)->not->toBeFalse();

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $appDir));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $content = (string) file_get_contents($file->getPathname());
        if (str_contains($content, 'extends FormRequest')) {
            $relativePath = str_replace((string) $appDir, 'App', $file->getPathname());
            expect(str_starts_with($relativePath, 'App/Http/Requests/') || str_starts_with($relativePath, 'App\\Http\\Requests\\'))->toBeTrue();
        }
    }
});

test('services do not perform bulk query builder update or delete without approved audit bypass marker', function (): void {
    $servicesDir = realpath(__DIR__.'/../../app/Services');
    expect($servicesDir)->not->toBeFalse();

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $servicesDir));
    $violations = [];

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $lineIndex => $line) {
            if (preg_match('/->(update|delete)\s*\(/', $line)) {
                $hasBypass = str_contains($line, '@audit-bypass');
                if (! $hasBypass && $lineIndex > 0 && str_contains($lines[$lineIndex - 1], '@audit-bypass')) {
                    $hasBypass = true;
                }

                if (! $hasBypass) {
                    $violations[] = sprintf(
                        'Direct update/delete found without @audit-bypass in %s:%d: %s',
                        basename($file->getPathname()),
                        $lineIndex + 1,
                        trim($line)
                    );
                }
            }
        }
    }

    expect($violations)->toBeEmpty(
        "Services must not perform un-audited mass query builder update/delete without '// @audit-bypass: <reason>' marker.\n".implode("\n", $violations)
    );
});
