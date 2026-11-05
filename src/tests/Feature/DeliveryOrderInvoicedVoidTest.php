<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeliveryOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Sales\DeliveryOrderService;
use App\Services\Sales\SalesInvoiceService;
use Database\Seeders\ChartOfAccountSeeder;
use Tests\Support\SalesInvoiceFixtures;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
    $this->deliveries = new DeliveryOrderService(new StockService);
    $this->invoices = new SalesInvoiceService;
    $this->order = SalesInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '1000.0000']]);
    $this->delivery = SalesInvoiceFixtures::postedDelivery($this->order, ['5.0000']);
});

test('a delivery on a posted invoice cannot be voided, and the message names the invoice', function (): void {
    $invoice = $this->invoices->post(SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery]));

    try {
        $this->deliveries->void($this->delivery, 'Salah kirim');
        $this->fail('The void should have been refused.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(409)
            ->and($e->field)->toBe('status')
            ->and($e->getMessage())->toContain($invoice->number)
            ->and($e->getMessage())->toContain('batalkan faktur terlebih dahulu');
    }

    expect($this->delivery->fresh()->status)->toBe(DeliveryOrderStatus::POSTED);
});

test('a draft invoice also blocks the void', function (): void {
    $draft = SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery]);

    expect(fn () => $this->deliveries->void($this->delivery, 'Salah kirim'))
        ->toThrow(BusinessRuleException::class, $draft->number);
});

test('once the invoice is voided the delivery can be voided', function (): void {
    $invoice = $this->invoices->post(SalesInvoiceFixtures::draftInvoice($this->order, [$this->delivery]));

    $this->invoices->void($invoice, 'Salah tagih');

    expect($this->deliveries->void($this->delivery, 'Salah kirim')->status)->toBe(DeliveryOrderStatus::VOID);
});

test('a delivery without any invoice can still be voided', function (): void {
    expect($this->deliveries->void($this->delivery, 'Salah kirim')->status)->toBe(DeliveryOrderStatus::VOID);
});

test('an invoice for another delivery of the order does not block this one', function (): void {
    $order = SalesInvoiceFixtures::approvedOrder([['quantity' => '4.0000', 'unit_price' => '1000.0000']]);
    $billed = SalesInvoiceFixtures::postedDelivery($order, ['2.0000']);
    $unbilled = SalesInvoiceFixtures::postedDelivery($order->fresh(['items']), ['2.0000']);

    SalesInvoiceFixtures::draftInvoice($order, [$billed]);

    expect($this->deliveries->void($unbilled, 'Salah kirim')->status)->toBe(DeliveryOrderStatus::VOID)
        ->and(fn () => $this->deliveries->void($billed, 'Salah kirim'))->toThrow(BusinessRuleException::class);
});
