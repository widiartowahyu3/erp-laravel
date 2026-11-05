<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GoodsReceiptStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseInvoiceService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchaseInvoiceFixtures;

beforeEach(function (): void {
    (new ChartOfAccountSeeder)->run();
    $this->actingAs(User::factory()->create());
    $this->receipts = new GoodsReceiptService(new StockService);
    $this->invoices = new PurchaseInvoiceService;
    $this->order = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '100.0000']]);
    $this->receipt = PurchaseInvoiceFixtures::postedReceipt($this->order, ['5.0000']);
});

test('a receipt on a posted invoice cannot be voided, and the message names the invoice', function (): void {
    $invoice = $this->invoices->post(PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt]));

    try {
        $this->receipts->void($this->receipt, 'Salah terima');
        $this->fail('The void should have been refused.');
    } catch (BusinessRuleException $e) {
        expect($e->getCode())->toBe(409)
            ->and($e->field)->toBe('status')
            ->and($e->getMessage())->toContain($invoice->number)
            ->and($e->getMessage())->toContain('batalkan faktur terlebih dahulu');
    }

    expect($this->receipt->fresh()->status)->toBe(GoodsReceiptStatus::POSTED);
});

test('a draft invoice also blocks the void', function (): void {
    $draft = PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt]);

    expect(fn () => $this->receipts->void($this->receipt, 'Salah terima'))
        ->toThrow(BusinessRuleException::class, $draft->number);
});

test('once the invoice is voided the receipt can be voided', function (): void {
    $invoice = $this->invoices->post(PurchaseInvoiceFixtures::draftInvoice($this->order, [$this->receipt]));

    $this->invoices->void($invoice, 'Salah tagih');

    expect($this->receipts->void($this->receipt, 'Salah terima')->status)->toBe(GoodsReceiptStatus::VOID);
});

test('a receipt without any invoice can still be voided', function (): void {
    expect($this->receipts->void($this->receipt, 'Salah terima')->status)->toBe(GoodsReceiptStatus::VOID);
});

test('an invoice for another receipt of the order does not block this one', function (): void {
    $order = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '4.0000', 'unit_price' => '100.0000']]);
    $billed = PurchaseInvoiceFixtures::postedReceipt($order, ['2.0000']);
    $unbilled = PurchaseInvoiceFixtures::postedReceipt($order->fresh(['items']), ['2.0000']);

    PurchaseInvoiceFixtures::draftInvoice($order, [$billed]);

    expect($this->receipts->void($unbilled, 'Salah terima')->status)->toBe(GoodsReceiptStatus::VOID)
        ->and(fn () => $this->receipts->void($billed, 'Salah terima'))->toThrow(BusinessRuleException::class);
});

test('posting and voiding a receipt lock the order before the receipt, like invoices do', function (string $action): void {
    $receipt = $this->receipt;

    if ($action === 'post') {
        $order = PurchaseInvoiceFixtures::approvedOrder([['quantity' => '5.0000', 'unit_price' => '100.0000']]);
        $receipt = PurchaseInvoiceFixtures::draftReceipt($order, ['5.0000']);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $action === 'post' ? $this->receipts->post($receipt) : $this->receipts->void($receipt, 'Salah terima');
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $position = function (string $table) use ($queries): int {
        foreach ($queries as $index => $sql) {
            if (str_contains($sql, "from `{$table}`") && str_contains($sql, 'for update')) {
                return $index;
            }
        }

        return -1;
    };

    expect($position('purchase_orders'))->toBeGreaterThanOrEqual(0)
        ->and($position('goods_receipts'))->toBeGreaterThan($position('purchase_orders'));
})->with(['post', 'void']);
