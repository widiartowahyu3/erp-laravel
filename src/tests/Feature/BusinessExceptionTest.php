<?php

declare(strict_types=1);

use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentNotEditableException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\TooManyAttemptsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::match(['get', 'post'], '/_test/business-rule', function () {
            throw new BusinessRuleException('Aturan bisnis dilanggar.');
        });

        Route::match(['get', 'post'], '/_test/invalid-transition', function () {
            throw new InvalidStateTransitionException('draft', 'closed');
        });

        Route::match(['get', 'post'], '/_test/insufficient-stock', function () {
            throw new InsufficientStockException('Stok produk tidak mencukupi.');
        });

        Route::match(['get', 'post'], '/_test/document-not-editable', function () {
            throw new DocumentNotEditableException('Dokumen tidak dapat diubah.');
        });

        Route::match(['get', 'post'], '/_test/too-many-attempts', function () {
            throw new TooManyAttemptsException('test_key', 45, 'email', 'Terlalu banyak percobaan.');
        });
    });
});

test('business exceptions return 303 redirect with inline error bag for Inertia requests', function (
    string $url,
    string $expectedField,
    string $expectedMessage
) {
    $response = $this->withHeader('X-Inertia', 'true')
        ->from('/test-page')
        ->post($url);

    $response->assertStatus(303);
    $response->assertRedirect('/test-page');
    $response->assertSessionHasErrors([$expectedField => $expectedMessage]);
})->with([
    'BusinessRuleException' => ['/_test/business-rule', 'form', 'Aturan bisnis dilanggar.'],
    'InvalidStateTransitionException' => ['/_test/invalid-transition', 'status', "Transisi status dari 'draft' ke 'closed' tidak diizinkan."],
    'InsufficientStockException' => ['/_test/insufficient-stock', 'quantity', 'Stok produk tidak mencukupi.'],
    'DocumentNotEditableException' => ['/_test/document-not-editable', 'status', 'Dokumen tidak dapat diubah.'],
    'TooManyAttemptsException' => ['/_test/too-many-attempts', 'email', 'Terlalu banyak percobaan.'],
]);

test('business exceptions return structured JSON response for JSON requests', function (
    string $url,
    int $expectedStatus,
    string $expectedField,
    string $expectedMessage
) {
    $response = $this->getJson($url);

    $response->assertStatus($expectedStatus);
    $response->assertJson([
        'message' => $expectedMessage,
        'errors' => [
            $expectedField => [$expectedMessage],
        ],
    ]);
})->with([
    'BusinessRuleException' => ['/_test/business-rule', 422, 'form', 'Aturan bisnis dilanggar.'],
    'InvalidStateTransitionException' => ['/_test/invalid-transition', 409, 'status', "Transisi status dari 'draft' ke 'closed' tidak diizinkan."],
    'InsufficientStockException' => ['/_test/insufficient-stock', 422, 'quantity', 'Stok produk tidak mencukupi.'],
    'DocumentNotEditableException' => ['/_test/document-not-editable', 409, 'status', 'Dokumen tidak dapat diubah.'],
    'TooManyAttemptsException' => ['/_test/too-many-attempts', 429, 'email', 'Terlalu banyak percobaan.'],
]);

test('TooManyAttemptsException includes Retry-After header in JSON response', function () {
    $response = $this->getJson('/_test/too-many-attempts');

    $response->assertStatus(429);
    $response->assertHeader('Retry-After', '45');
});

test('business exceptions return generic HTML page without leaking debug information for HTML requests', function (
    string $url,
    int $expectedStatus,
    string $expectedMessage
) {
    $response = $this->withHeader('Accept', 'text/html')->get($url);

    $response->assertStatus($expectedStatus);
    $response->assertSee($expectedMessage);
    $response->assertDontSee('Stack trace');
    $response->assertDontSee('vendor/laravel');
})->with([
    'BusinessRuleException' => ['/_test/business-rule', 422, 'Aturan bisnis dilanggar.'],
    'InvalidStateTransitionException' => ['/_test/invalid-transition', 409, "Transisi status dari 'draft' ke 'closed' tidak diizinkan."],
    'InsufficientStockException' => ['/_test/insufficient-stock', 422, 'Stok produk tidak mencukupi.'],
    'DocumentNotEditableException' => ['/_test/document-not-editable', 409, 'Dokumen tidak dapat diubah.'],
    'TooManyAttemptsException' => ['/_test/too-many-attempts', 429, 'Terlalu banyak percobaan.'],
]);

test('business exceptions are not logged to ERROR log channel', function () {
    Log::spy();

    $this->getJson('/_test/business-rule');
    $this->getJson('/_test/invalid-transition');
    $this->getJson('/_test/insufficient-stock');
    $this->getJson('/_test/document-not-editable');
    $this->getJson('/_test/too-many-attempts');

    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('critical');
    Log::shouldNotHaveReceived('alert');
    Log::shouldNotHaveReceived('emergency');
});
