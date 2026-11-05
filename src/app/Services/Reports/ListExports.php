<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\GoodsReceipt;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Accounting\JournalSourceResolver;
use App\Support\Decimal;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV for the list pages. Each list keeps to the columns its page already shows, so the
 * export needs no permission beyond viewing the list.
 */
final class ListExports
{
    private const int CHUNK = 1000;

    public function __construct(private readonly CsvExporter $csv) {}

    public static function requested(Request $request): bool
    {
        return $request->input('export') === 'csv';
    }

    /**
     * @param  Builder<Product>  $query
     *
     * @throws BusinessRuleException
     */
    public function products(Builder $query): StreamedResponse
    {
        return $this->stream('produk', [
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'name', 'label' => 'Nama'],
            ['key' => 'category', 'label' => 'Kategori'],
            ['key' => 'unit', 'label' => 'Satuan'],
            ['key' => 'selling_price', 'label' => 'Harga Jual', 'numeric' => true],
            ['key' => 'min_stock', 'label' => 'Stok Minimum', 'numeric' => true],
            ['key' => 'track_stock', 'label' => 'Lacak Stok'],
            ['key' => 'is_active', 'label' => 'Aktif'],
        ], $query, fn (Product $product): array => [
            'sku' => $product->sku,
            'name' => $product->name,
            'category' => $product->category?->name,
            'unit' => $product->baseUnit?->name,
            'selling_price' => $product->selling_price,
            'min_stock' => $product->min_stock,
            'track_stock' => $product->track_stock,
            'is_active' => $product->is_active,
        ]);
    }

    /**
     * @param  Builder<Customer>  $query
     *
     * @throws BusinessRuleException
     */
    public function customers(Builder $query): StreamedResponse
    {
        return $this->stream('pelanggan', [
            ['key' => 'code', 'label' => 'Kode'],
            ['key' => 'name', 'label' => 'Nama'],
            ['key' => 'contact_name', 'label' => 'Kontak'],
            ['key' => 'phone', 'label' => 'Telepon'],
            ['key' => 'credit_limit', 'label' => 'Limit Kredit', 'numeric' => true],
            ['key' => 'payment_term', 'label' => 'Syarat Bayar'],
            ['key' => 'is_active', 'label' => 'Aktif'],
        ], $query, fn (Customer $customer): array => [
            'code' => $customer->code,
            'name' => $customer->name,
            'contact_name' => $customer->contact_name,
            'phone' => $customer->phone,
            'credit_limit' => $customer->credit_limit,
            'payment_term' => $customer->paymentTerm?->name,
            'is_active' => $customer->is_active,
        ]);
    }

    /**
     * @param  Builder<Supplier>  $query
     *
     * @throws BusinessRuleException
     */
    public function suppliers(Builder $query): StreamedResponse
    {
        return $this->stream('pemasok', [
            ['key' => 'code', 'label' => 'Kode'],
            ['key' => 'name', 'label' => 'Nama'],
            ['key' => 'contact_name', 'label' => 'Kontak'],
            ['key' => 'phone', 'label' => 'Telepon'],
            ['key' => 'payment_term', 'label' => 'Syarat Bayar'],
            ['key' => 'is_active', 'label' => 'Aktif'],
        ], $query, fn (Supplier $supplier): array => [
            'code' => $supplier->code,
            'name' => $supplier->name,
            'contact_name' => $supplier->contact_name,
            'phone' => $supplier->phone,
            'payment_term' => $supplier->paymentTerm?->name,
            'is_active' => $supplier->is_active,
        ]);
    }

    /**
     * @param  Builder<StockBalance>  $query
     *
     * @throws BusinessRuleException
     */
    public function stockBalances(Builder $query): StreamedResponse
    {
        return $this->stream('saldo-stok', [
            ['key' => 'warehouse', 'label' => 'Gudang'],
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'product', 'label' => 'Produk'],
            ['key' => 'category', 'label' => 'Kategori'],
            ['key' => 'unit', 'label' => 'Satuan'],
            ['key' => 'quantity', 'label' => 'Stok', 'numeric' => true],
            ['key' => 'avg_cost', 'label' => 'Biaya Rata-rata', 'numeric' => true],
            ['key' => 'value', 'label' => 'Nilai Total', 'numeric' => true],
        ], $query, fn (StockBalance $balance): array => [
            'warehouse' => $balance->warehouse->name,
            'sku' => $balance->product->sku,
            'product' => $balance->product->name,
            'category' => $balance->product->category?->name,
            'unit' => $balance->product->baseUnit?->name,
            'quantity' => $balance->quantity,
            'avg_cost' => $balance->avg_cost,
            'value' => Decimal::mul((string) $balance->quantity, (string) $balance->avg_cost, Decimal::SCALE_MONEY),
        ]);
    }

    /**
     * @param  Builder<StockMovement>  $query
     *
     * @throws BusinessRuleException
     */
    public function stockMovements(Builder $query): StreamedResponse
    {
        return $this->stream('mutasi-stok', [
            ['key' => 'date', 'label' => 'Tanggal'],
            ['key' => 'type', 'label' => 'Jenis'],
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'product', 'label' => 'Produk'],
            ['key' => 'warehouse', 'label' => 'Gudang'],
            ['key' => 'quantity', 'label' => 'Kuantitas', 'numeric' => true],
            ['key' => 'unit_cost', 'label' => 'Biaya Satuan', 'numeric' => true],
            ['key' => 'balance_after', 'label' => 'Saldo Setelah', 'numeric' => true],
            ['key' => 'avg_cost_after', 'label' => 'Biaya Rata-rata Setelah', 'numeric' => true],
        ], $query, fn (StockMovement $movement): array => [
            'date' => $movement->movement_date->toDateTimeString(),
            'type' => $movement->movement_type->label(),
            'sku' => $movement->product->sku,
            'product' => $movement->product->name,
            'warehouse' => $movement->warehouse->name,
            'quantity' => $movement->quantity,
            'unit_cost' => $movement->unit_cost,
            'balance_after' => $movement->balance_after,
            'avg_cost_after' => $movement->avg_cost_after,
        ]);
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     *
     * @throws BusinessRuleException
     */
    public function purchaseOrders(Builder $query): StreamedResponse
    {
        return $this->stream('pesanan-pembelian', [
            ['key' => 'number', 'label' => 'Nomor'],
            ['key' => 'date', 'label' => 'Tanggal'],
            ['key' => 'party', 'label' => 'Pemasok'],
            ['key' => 'warehouse', 'label' => 'Gudang'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'total', 'label' => 'Total', 'numeric' => true],
        ], $query, fn (PurchaseOrder $order): array => [
            'number' => $order->number,
            'date' => $order->order_date->toDateString(),
            'party' => $order->supplier->name,
            'warehouse' => $order->warehouse->name,
            'status' => $order->status->label(),
            'total' => $order->total,
        ]);
    }

    /**
     * @param  Builder<GoodsReceipt>  $query
     *
     * @throws BusinessRuleException
     */
    public function goodsReceipts(Builder $query): StreamedResponse
    {
        return $this->stream('penerimaan-barang', [
            ['key' => 'number', 'label' => 'Nomor'],
            ['key' => 'date', 'label' => 'Tanggal'],
            ['key' => 'order', 'label' => 'No. Pesanan'],
            ['key' => 'party', 'label' => 'Pemasok'],
            ['key' => 'warehouse', 'label' => 'Gudang'],
            ['key' => 'status', 'label' => 'Status'],
        ], $query, fn (GoodsReceipt $receipt): array => [
            'number' => $receipt->number,
            'date' => $receipt->receipt_date->toDateString(),
            'order' => $receipt->purchaseOrder->number,
            'party' => $receipt->supplier->name,
            'warehouse' => $receipt->warehouse->name,
            'status' => $receipt->status->label(),
        ]);
    }

    /**
     * @param  Builder<PurchaseInvoice>  $query
     *
     * @throws BusinessRuleException
     */
    public function purchaseInvoices(Builder $query): StreamedResponse
    {
        return $this->stream('faktur-pembelian', [
            ['key' => 'number', 'label' => 'Nomor'],
            ['key' => 'supplier_number', 'label' => 'No. Faktur Pemasok'],
            ['key' => 'date', 'label' => 'Tanggal'],
            ['key' => 'due_date', 'label' => 'Jatuh Tempo'],
            ['key' => 'party', 'label' => 'Pemasok'],
            ['key' => 'total', 'label' => 'Total', 'numeric' => true],
            ['key' => 'paid', 'label' => 'Dibayar', 'numeric' => true],
            ['key' => 'outstanding', 'label' => 'Sisa', 'numeric' => true],
            ['key' => 'status', 'label' => 'Status'],
        ], $query, fn (PurchaseInvoice $invoice): array => [
            'number' => $invoice->number,
            'supplier_number' => $invoice->supplier_invoice_number,
            'date' => $invoice->invoice_date->toDateString(),
            'due_date' => $invoice->due_date->toDateString(),
            'party' => $invoice->supplier->name,
            'total' => $invoice->total,
            'paid' => $invoice->paid_amount,
            'outstanding' => $invoice->outstanding(),
            'status' => $invoice->status->label(),
        ]);
    }

    /**
     * @param  Builder<SalesOrder>  $query
     *
     * @throws BusinessRuleException
     */
    public function salesOrders(Builder $query): StreamedResponse
    {
        return $this->stream('pesanan-penjualan', [
            ['key' => 'number', 'label' => 'Nomor'],
            ['key' => 'date', 'label' => 'Tanggal'],
            ['key' => 'party', 'label' => 'Pelanggan'],
            ['key' => 'warehouse', 'label' => 'Gudang'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'total', 'label' => 'Total', 'numeric' => true],
        ], $query, fn (SalesOrder $order): array => [
            'number' => $order->number,
            'date' => $order->order_date->toDateString(),
            'party' => $order->customer->name,
            'warehouse' => $order->warehouse->name,
            'status' => $order->status->label(),
            'total' => $order->total,
        ]);
    }

    /**
     * @param  Builder<DeliveryOrder>  $query
     *
     * @throws BusinessRuleException
     */
    public function deliveryOrders(Builder $query): StreamedResponse
    {
        return $this->stream('surat-jalan', [
            ['key' => 'number', 'label' => 'Nomor'],
            ['key' => 'date', 'label' => 'Tanggal'],
            ['key' => 'order', 'label' => 'No. Pesanan'],
            ['key' => 'party', 'label' => 'Pelanggan'],
            ['key' => 'warehouse', 'label' => 'Gudang'],
            ['key' => 'status', 'label' => 'Status'],
        ], $query, fn (DeliveryOrder $delivery): array => [
            'number' => $delivery->number,
            'date' => $delivery->delivery_date->toDateString(),
            'order' => $delivery->salesOrder->number,
            'party' => $delivery->customer->name,
            'warehouse' => $delivery->warehouse->name,
            'status' => $delivery->status->label(),
        ]);
    }

    /**
     * @param  Builder<SalesInvoice>  $query
     *
     * @throws BusinessRuleException
     */
    public function salesInvoices(Builder $query): StreamedResponse
    {
        return $this->stream('faktur-penjualan', [
            ['key' => 'number', 'label' => 'Nomor'],
            ['key' => 'date', 'label' => 'Tanggal'],
            ['key' => 'due_date', 'label' => 'Jatuh Tempo'],
            ['key' => 'party', 'label' => 'Pelanggan'],
            ['key' => 'total', 'label' => 'Total', 'numeric' => true],
            ['key' => 'paid', 'label' => 'Dibayar', 'numeric' => true],
            ['key' => 'outstanding', 'label' => 'Sisa', 'numeric' => true],
            ['key' => 'status', 'label' => 'Status'],
        ], $query, fn (SalesInvoice $invoice): array => [
            'number' => $invoice->number,
            'date' => $invoice->invoice_date->toDateString(),
            'due_date' => $invoice->due_date->toDateString(),
            'party' => $invoice->customer->name,
            'total' => $invoice->total,
            'paid' => $invoice->paid_amount,
            'outstanding' => $invoice->outstanding(),
            'status' => $invoice->status->label(),
        ]);
    }

    /**
     * @param  Builder<Payment>  $query
     *
     * @throws BusinessRuleException
     */
    public function payments(Builder $query): StreamedResponse
    {
        return $this->stream('pembayaran', [
            ['key' => 'number', 'label' => 'Nomor'],
            ['key' => 'date', 'label' => 'Tanggal'],
            ['key' => 'type', 'label' => 'Jenis'],
            ['key' => 'party', 'label' => 'Pihak'],
            ['key' => 'method', 'label' => 'Metode'],
            ['key' => 'account', 'label' => 'Akun'],
            ['key' => 'reference', 'label' => 'Referensi'],
            ['key' => 'amount', 'label' => 'Jumlah', 'numeric' => true],
            ['key' => 'status', 'label' => 'Status'],
        ], $query, fn (Payment $payment): array => [
            'number' => $payment->number,
            'date' => $payment->payment_date->toDateString(),
            'type' => $payment->payment_type->label(),
            'party' => $payment->customer->name ?? $payment->supplier->name,
            'method' => $payment->method->label(),
            'account' => $payment->account->code.' '.$payment->account->name,
            'reference' => $payment->reference,
            'amount' => $payment->amount,
            'status' => $payment->status->label(),
        ]);
    }

    /**
     * @param  Builder<Journal>  $query
     *
     * @throws BusinessRuleException
     */
    public function journals(Builder $query): StreamedResponse
    {
        return $this->stream('jurnal', [
            ['key' => 'number', 'label' => 'Nomor'],
            ['key' => 'date', 'label' => 'Tanggal'],
            ['key' => 'description', 'label' => 'Deskripsi'],
            ['key' => 'source', 'label' => 'Sumber'],
            ['key' => 'debit', 'label' => 'Debit', 'numeric' => true],
            ['key' => 'credit', 'label' => 'Kredit', 'numeric' => true],
            ['key' => 'status', 'label' => 'Status'],
        ], $query, fn (Journal $journal): array => [
            'number' => $journal->number,
            'date' => $journal->journal_date->toDateString(),
            'description' => $journal->description,
            'source' => JournalSourceResolver::label($journal->source_type) ?? 'Manual',
            'debit' => $journal->total_debit,
            'credit' => $journal->total_credit,
            'status' => $journal->status->label(),
        ]);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  list<array{key: string, label: string, numeric?: bool}>  $columns
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): array<string, mixed>  $row
     *
     * @throws BusinessRuleException
     */
    private function stream(string $name, array $columns, Builder $query, Closure $row): StreamedResponse
    {
        return $this->csv->download(
            $name.'-'.now()->toDateString().'.csv',
            $columns,
            $this->rows($query, $row),
            $query->toBase()->getCountForPagination(),
        );
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): array<string, mixed>  $row
     * @return Generator<int, array<string, mixed>>
     */
    private function rows(Builder $query, Closure $row): Generator
    {
        foreach ($query->lazy(self::CHUNK) as $model) {
            yield $row($model);
        }
    }
}
