<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\PaymentService;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Sales\DeliveryOrderService;
use App\Services\Sales\SalesInvoiceService;
use App\Services\Sales\SalesOrderService;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Fills a clean database with a full trading cycle through the real services, so every report has
 * something to show: about 20 purchases, 30 sales, payments, stock counts and manual journals.
 *
 * The figures come from a fixed random seed. Dates count back from today so aging and this month's
 * profit stay current. Each product keeps one purchase price, which keeps average cost exact and the
 * inventory reconciliation at 0.00.
 */
class DemoAccountingCycleSeeder extends Seeder
{
    private const int RANDOM_SEED = 20261105;

    private const int PURCHASE_COUNT = 20;

    private const int SALE_COUNT = 30;

    private const int ADJUSTMENT_COUNT = 5;

    private const int NEWEST_UNPAID_SALES = 3;

    private const string FIRST_SUPPLIER_INVOICE = 'DEMO-PI-0001';

    private CarbonImmutable $today;

    private int $cashAccount;

    private int $bankAccount;

    private ?int $vatRateId;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoAccountingCycleSeeder tidak boleh dijalankan di lingkungan production!');
        }

        if (PurchaseInvoice::query()->where('supplier_invoice_number', self::FIRST_SUPPLIER_INVOICE)->exists()) {
            $this->command?->info('Data siklus akuntansi demo sudah ada di database (dilewati).');

            return;
        }

        if (! ChartOfAccount::query()->exists()) {
            $this->call(ChartOfAccountSeeder::class);
        }

        if (! Product::query()->where('track_stock', true)->exists()) {
            $this->call(DemoMasterDataSeeder::class);
        }

        mt_srand(self::RANDOM_SEED);

        $this->today = CarbonImmutable::today();
        $this->cashAccount = (int) ChartOfAccount::query()->where('code', '1101')->value('id');
        $this->bankAccount = (int) ChartOfAccount::query()->where('code', '1102')->value('id');
        $this->vatRateId = TaxRate::query()->where('code', 'PPN11')->value('id');

        $suppliers = Supplier::query()->where('is_active', true)->orderBy('id')->get();
        $customers = Customer::query()->where('is_active', true)->orderBy('id')->get();
        $warehouses = Warehouse::query()->where('is_active', true)->orderBy('id')->take(2)->get();
        $products = Product::query()->where('is_active', true)->where('track_stock', true)->orderBy('id')->take(6)->get();

        if ($suppliers->isEmpty() || $customers->isEmpty() || $warehouses->isEmpty() || $products->count() < 2) {
            throw new RuntimeException('Master data belum mencukupi untuk menjalankan DemoAccountingCycleSeeder.');
        }

        $this->postManualJournals();
        $this->runPurchases($suppliers, $warehouses, $products);
        $this->runSales($customers, $warehouses, $products);
        $this->runStockCounts($warehouses, $products);

        $this->command?->info('Data siklus akuntansi demo berhasil dibuat.');
    }

    private function date(int $daysAgo): string
    {
        return $this->today->subDays(max(0, $daysAgo))->toDateString();
    }

    /**
     * A product's one purchase price, a round figure below its selling price.
     */
    private function costOf(Product $product): string
    {
        return Decimal::mul(bcdiv(Decimal::mul((string) $product->selling_price, '0.7'), '500', 0), '500');
    }

    private function postManualJournals(): void
    {
        $journals = app(JournalService::class);

        $post = function (int $daysAgo, string $description, array $lines) use ($journals): void {
            $journals->post($journals->createDraft([
                'journal_date' => $this->date($daysAgo),
                'description' => $description,
                'lines' => $lines,
            ]));
        };

        $post(130, 'Setoran modal awal pemilik', [
            ['account_id' => $this->bankAccount, 'debit' => '500000000.00', 'credit' => '0.00'],
            ['account_id' => $this->cashAccount, 'debit' => '50000000.00', 'credit' => '0.00'],
            ['account_id' => (int) ChartOfAccount::query()->where('code', '3101')->value('id'), 'debit' => '0.00', 'credit' => '550000000.00'],
        ]);

        $overhead = (int) ChartOfAccount::query()->where('code', '6101')->value('id');

        foreach ([90, 60, 30, 2] as $daysAgo) {
            $post($daysAgo, 'Beban operasional bulanan', [
                ['account_id' => $overhead, 'debit' => '2500000.00', 'credit' => '0.00'],
                ['account_id' => $this->bankAccount, 'debit' => '0.00', 'credit' => '2500000.00'],
            ]);
        }
    }

    /**
     * @param  Collection<int, Supplier>  $suppliers
     * @param  Collection<int, Warehouse>  $warehouses
     * @param  Collection<int, Product>  $products
     */
    private function runPurchases(Collection $suppliers, Collection $warehouses, Collection $products): void
    {
        $orders = app(PurchaseOrderService::class);
        $receipts = app(GoodsReceiptService::class);
        $invoices = app(PurchaseInvoiceService::class);
        $payments = app(PaymentService::class);

        for ($i = 0; $i < self::PURCHASE_COUNT; $i++) {
            $supplier = $suppliers[$i % $suppliers->count()];
            $warehouse = $warehouses[$i % $warehouses->count()];
            $orderDaysAgo = 125 - ($i * 3);
            $taxRateId = $i % 2 === 0 ? $this->vatRateId : null;

            // Every warehouse gets every product early on, so the sales below always find stock.
            $lines = $i < $warehouses->count() * 2
                ? $products
                : collect([$products[mt_rand(0, $products->count() - 1)]]);

            $order = $orders->approve($orders->create([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'order_date' => $this->date($orderDaysAgo),
                'notes' => 'Pembelian demo',
                'items' => $lines->values()->map(fn (Product $product): array => [
                    'product_id' => $product->id,
                    'quantity' => (string) (mt_rand(8, 20) * 10).'.0000',
                    'unit_price' => $this->costOf($product),
                    'discount_amount' => '0.00',
                    'tax_rate_id' => $taxRateId,
                ])->all(),
            ]))->fresh(['items']);

            // One purchase in five arrives in two parts.
            $parts = $i % 5 === 0 ? ['0.6', '0.4'] : ['1'];
            $received = [];

            foreach ($parts as $part => $share) {
                $received[] = $receipts->post($receipts->create([
                    'purchase_order_id' => $order->id,
                    'supplier_id' => $order->supplier_id,
                    'warehouse_id' => $order->warehouse_id,
                    'receipt_date' => $this->date($orderDaysAgo - 2 - $part),
                    'items' => $order->items->map(fn ($item): array => [
                        'purchase_order_item_id' => $item->id,
                        'product_id' => $item->product_id,
                        'quantity' => Decimal::mul((string) $item->quantity, $share, Decimal::SCALE_QUANTITY),
                    ])->all(),
                ]));
            }

            $invoiceDaysAgo = $orderDaysAgo - 4;
            $invoice = $invoices->post($invoices->create([
                'purchase_order_id' => $order->id,
                'supplier_invoice_number' => sprintf('DEMO-PI-%04d', $i + 1),
                'invoice_date' => $this->date($invoiceDaysAgo),
                'due_date' => $this->date($invoiceDaysAgo - [14, 30, 7][$i % 3]),
                'items' => collect($received)->flatMap(fn ($receipt) => $receipt->items->map(fn ($item): array => [
                    'goods_receipt_item_id' => $item->id,
                    'quantity' => (string) $item->quantity,
                ]))->values()->all(),
            ]));

            $this->settlePurchase($payments, $invoice, $i, $invoiceDaysAgo);
        }
    }

    /**
     * One purchase invoice in four stays unpaid and one is paid in part; the others are paid in full.
     */
    private function settlePurchase(PaymentService $payments, PurchaseInvoice $invoice, int $index, int $invoiceDaysAgo): void
    {
        $amount = match ($index % 4) {
            0 => null,
            1 => Decimal::mul((string) $invoice->total, '0.5'),
            default => (string) $invoice->total,
        };

        if ($amount === null) {
            return;
        }

        $payments->post($payments->createDisbursement([
            'supplier_id' => $invoice->supplier_id,
            'payment_date' => $this->date($invoiceDaysAgo - 10),
            'method' => 'bank_transfer',
            'account_id' => $this->bankAccount,
            'amount' => $amount,
            'allocations' => [['purchase_invoice_id' => $invoice->id, 'amount' => $amount]],
        ]));
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Warehouse>  $warehouses
     * @param  Collection<int, Product>  $products
     */
    private function runSales(Collection $customers, Collection $warehouses, Collection $products): void
    {
        $orders = app(SalesOrderService::class);
        $deliveries = app(DeliveryOrderService::class);
        $invoices = app(SalesInvoiceService::class);
        $payments = app(PaymentService::class);

        for ($i = 0; $i < self::SALE_COUNT; $i++) {
            $customer = $customers[$i % $customers->count()];
            $warehouse = $warehouses[$i % $warehouses->count()];
            $orderDaysAgo = $i < 8 ? 112 - ($i * 2) : 96 - intdiv(($i - 8) * 92, self::SALE_COUNT - 9);
            $product = $products[mt_rand(0, $products->count() - 1)];
            $quantity = (string) mt_rand(1, 8).'.0000';

            $order = $orders->create([
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'order_date' => $this->date($orderDaysAgo),
                'notes' => 'Penjualan demo',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => (string) $product->selling_price,
                    'discount_amount' => $i % 4 === 0 ? '1000.00' : '0.00',
                    'tax_rate_id' => $i % 3 === 0 ? $this->vatRateId : null,
                ]],
            ]);
            $orders->approve($order);
            $order = $order->fresh(['items']);

            $delivery = $deliveries->post($deliveries->create([
                'sales_order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'warehouse_id' => $order->warehouse_id,
                'delivery_date' => $this->date($orderDaysAgo - 1),
                'items' => [[
                    'sales_order_item_id' => $order->items[0]->id,
                    'product_id' => $order->items[0]->product_id,
                    'quantity' => $quantity,
                ]],
            ]));

            $invoiceDaysAgo = $orderDaysAgo - 2;
            $invoiceData = fn (): array => [
                'sales_order_id' => $order->id,
                'invoice_date' => $this->date($invoiceDaysAgo),
                'due_date' => $this->date($invoiceDaysAgo - [30, 14, 7][$i % 3]),
                'items' => [['delivery_order_item_id' => $this->firstItemId($delivery), 'quantity' => $quantity]],
            ];

            $invoice = $invoices->post($invoices->create($invoiceData()));

            // One invoice shows the correction path: voided by a reversal, then billed again.
            if ($i === 7) {
                $invoices->void($invoice, 'Koreksi data demo');
                $invoice = $invoices->post($invoices->create($invoiceData()));
            }

            $this->settleSale($payments, $invoice, $i, $invoiceDaysAgo);
        }
    }

    private function firstItemId(DeliveryOrder $delivery): int
    {
        return (int) $delivery->items->first()->id;
    }

    /**
     * One sales invoice in five stays unpaid and one is paid in part; the others are paid in full.
     * The newest ones stay unpaid too, so aging also shows invoices not yet due.
     * One payment is voided and received again, to show the reversal in the ledger.
     */
    private function settleSale(PaymentService $payments, SalesInvoice $invoice, int $index, int $invoiceDaysAgo): void
    {
        $amount = match (true) {
            $index >= self::SALE_COUNT - self::NEWEST_UNPAID_SALES, $index % 5 === 0 => null,
            $index % 5 === 1 => Decimal::mul((string) $invoice->total, '0.5'),
            default => (string) $invoice->total,
        };

        if ($amount === null) {
            return;
        }

        $receipt = fn (): array => [
            'customer_id' => $invoice->customer_id,
            'payment_date' => $this->date($invoiceDaysAgo - 5),
            'method' => $index % 2 === 0 ? 'cash' : 'bank_transfer',
            'account_id' => $index % 2 === 0 ? $this->cashAccount : $this->bankAccount,
            'amount' => $amount,
            'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => $amount]],
        ];

        $payment = $payments->post($payments->createReceipt($receipt()));

        if ($index === 12) {
            $payments->void($payment, 'Koreksi data demo');
            $payments->post($payments->createReceipt($receipt()));
        }
    }

    /**
     * @param  Collection<int, Warehouse>  $warehouses
     * @param  Collection<int, Product>  $products
     */
    private function runStockCounts(Collection $warehouses, Collection $products): void
    {
        $adjustments = app(StockAdjustmentService::class);

        for ($i = 0; $i < self::ADJUSTMENT_COUNT; $i++) {
            $warehouse = $warehouses[$i % $warehouses->count()];
            $product = $products[$i % $products->count()];
            $current = (string) StockBalance::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->value('quantity');

            // Mostly shortages, and every third count finds a little extra at the usual cost.
            $isSurplus = $i % 3 === 2;

            $adjustments->post($adjustments->create([
                'warehouse_id' => $warehouse->id,
                'adjustment_date' => $this->date(20 - $i),
                'reason' => 'Stock opname demo',
                'items' => [array_filter([
                    'product_id' => $product->id,
                    'counted_quantity' => $isSurplus
                        ? Decimal::add($current, '2', Decimal::SCALE_QUANTITY)
                        : Decimal::sub($current, '1', Decimal::SCALE_QUANTITY),
                    'unit_cost' => $isSurplus ? $this->costOf($product) : null,
                ], fn (mixed $value): bool => $value !== null)],
            ]));
        }
    }
}
