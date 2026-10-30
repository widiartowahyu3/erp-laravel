<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::post('/_test/erp-write-probe', function () {
        return response()->json(['status' => 'ok']);
    })->middleware(['web', 'auth', 'throttle:erp-write']);
});

test('erp-write rate limiter throttles 61st write request per minute per user without affecting other users', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    RateLimiter::clear((string) $userA->getAuthIdentifier());
    RateLimiter::clear((string) $userB->getAuthIdentifier());

    $this->actingAs($userA);

    // Send 60 write requests - all must succeed (200)
    for ($i = 1; $i <= 60; $i++) {
        $response = $this->post('/_test/erp-write-probe');
        expect($response->status())->toBe(200, "Request #{$i} should have succeeded with 200");
    }

    // 61st request must be throttled with 429 and Retry-After header
    $response61 = $this->post('/_test/erp-write-probe');
    expect($response61->status())->toBe(429)
        ->and($response61->headers->has('Retry-After'))->toBeTrue()
        ->and($response61->getContent())->not->toContain('Stack trace:')
        ->and($response61->getContent())->not->toContain('vendor/');

    // User B is isolated and unaffected (positive control)
    $this->actingAs($userB);
    $responseUserB = $this->post('/_test/erp-write-probe');
    expect($responseUserB->status())->toBe(200);
});
