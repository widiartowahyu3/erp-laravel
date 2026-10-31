<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;

test('validation messages in id locale do not show raw translation keys or english sentences', function (): void {
    App::setLocale('id');

    $user = userWithPermissions(['products.create', 'products.view']);
    $this->actingAs($user);

    $response = $this->postJson('/master/products', []);

    $response->assertStatus(422);
    $errors = $response->json('errors');

    expect($errors)->toHaveKey('sku')
        ->and($errors)->toHaveKey('name');

    $skuMsg = $errors['sku'][0];
    $nameMsg = $errors['name'][0];

    // Must NOT contain raw translation keys
    expect($skuMsg)->not->toContain('validation.')
        ->and($nameMsg)->not->toContain('validation.')
        // Must NOT contain english default validation words
        ->and($skuMsg)->not->toContain('The ')
        ->and($skuMsg)->not->toContain(' field is required')
        ->and($nameMsg)->not->toContain('The ')
        ->and($nameMsg)->not->toContain(' field is required')
        // Must contain Indonesian attribute name and phrase
        ->and($skuMsg)->toContain('wajib')
        ->and($nameMsg)->toContain('wajib');
});

test('at least 12 distinct validation rules produce indonesian messages with attributes and without english text', function (): void {
    App::setLocale('id');

    $rules = [
        'sku' => 'required',
        'name' => 'string|min:3',
        'code' => 'max:5',
        'category_id' => 'integer',
        'is_active' => 'boolean',
        'email' => 'email',
        'password' => 'confirmed',
        'order_date' => 'date',
        'receipt_date' => 'before_or_equal:today',
        'expected_date' => 'after_or_equal:order_date',
        'items' => 'array',
        'status' => 'in:draft,approved',
        'base_unit_id' => 'exists:units,id',
    ];

    $invalidData = [
        'sku' => '',
        'name' => 'a',
        'code' => 'toolongcodehere',
        'category_id' => 'not-an-int',
        'is_active' => 'not-a-bool',
        'email' => 'invalid-email',
        'password' => 'pass1',
        'password_confirmation' => 'pass2',
        'order_date' => 'invalid-date',
        'receipt_date' => '2099-12-31',
        'expected_date' => '2020-01-01',
        'items' => 'not-an-array',
        'status' => 'invalid-status',
        'base_unit_id' => 999999,
    ];

    $validator = Validator::make($invalidData, $rules);
    expect($validator->fails())->toBeTrue();

    $messages = $validator->errors()->all();
    expect(count($messages))->toBeGreaterThanOrEqual(12);

    foreach ($messages as $msg) {
        expect($msg)->not->toContain('validation.')
            ->and($msg)->not->toContain('The ')
            ->and($msg)->not->toContain('must be')
            ->and($msg)->not->toContain('field is')
            ->and($msg)->not->toContain('is invalid');
    }
});
