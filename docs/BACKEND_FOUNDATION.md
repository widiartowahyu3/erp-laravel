# Fondasi Backend ERP Distribusi / Trading (Fase 4)

Dokumen ini menjelaskan standar arsitektur dan fondasi teknis backend yang menjadi acuan baku bagi seluruh implementasi modul bisnis (pembelian, penjualan, persediaan, keuangan, dan master data) pada aplikasi ERP Distribusi/Trading.

---

## 1. Arsitektur Berlapis (Layered Architecture)

Aplikasi mengadopsi pemisahan tanggung jawab (*Separation of Concerns*) berbasis pola berlapis yang ketat:

```text
[HTTP Request]
       │
       ▼
[Controller] ─────────────► [FormRequest] (Validasi input & otorisasi awal)
       │
       ▼
[Service Layer] ──────────► [DocumentStateMachine] / [DocumentNumberService]
       │
       ▼
[Eloquent Models & Traits] (HasAuditUsers, RecordsAudit)
       │
       ▼
[Database MariaDB] (Strict DDL, Foreign Keys, CHECK constraints, Triggers)
```

### Tanggung Jawab Tiap Lapisan:
1. **Controller (`App\Http\Controllers`)**:
   - Menerima HTTP Request dan mengembalikan response (Inertia View / JSON).
   - **DILARANG** memanggil query `DB` secara langsung (ditegakkan via Pest Arch Test).
   - Mendelegasikan seluruh eksekusi logika bisnis ke Service layer.
2. **Form Request (`App\Http\Requests`)**:
   - Seluruh request validasi harus berada di dalam namespace ini dan meng-extend `Illuminate\Foundation\Http\FormRequest`.
   - Menjamin integritas dan sanitasi payload sebelum masuk ke controller atau service.
3. **Service Layer (`App\Services`)**:
   - Mengelola orkestrasi transaksi (`DB::transaction`).
   - Menerapkan aturan bisnis, validasi transisi status (*state machine*), dan penomoran dokumen otomatis.
   - **DILARANG** memiliki ketergantungan balik (*reverse dependency*) ke namespace `App\Http`.
4. **Eloquent Models & Traits (`App\Models`, `App\Models\Concerns`)**:
   - Representasi entitas data dengan atribut ketat (*Strict Mode* aktif).
   - Trait `HasAuditUsers` mencatat `created_by`, `updated_by`, dan `deleted_by`.
   - Trait `RecordsAudit` merekam riwayat audit perubahan model ke tabel `audit_logs`.
5. **Database Layer (MariaDB 12.3.x)**:
   - Baris pertahanan terakhir integritas data: CHECK constraints, Foreign Key RESTRICT, dan trigger integritas append-only.

---

## 2. Aritmetika Desimal Aman (`App\Support\Decimal`)

### Aturan Baku: DILARANG MENGGUNAKAN TIPE FLOAT
Floating-point bawaan PHP (`float` / `double`) memiliki keterbatasan presisi biner IEEE 754 yang menyebabkan hilangnya akurasi pada kalkulasi mata uang dan kuantitas inventaris.

### Standar Presisi Desimal:
- **Uang / Nilai Finansial (`SCALE_MONEY`)**: 2 tempat desimal (`DECIMAL(15, 2)`).
- **Kuantitas Barang (`SCALE_QTY`)**: 4 tempat desimal (`DECIMAL(12, 4)`).
- **Faktor Konversi / Tarif Pajak (`SCALE_CONVERSION`)**: 6 tempat desimal (`DECIMAL(10, 6)`).
- **Perhitungan Internal (`SCALE_INTERNAL`)**: 8 tempat desimal.

### Metode Tersedia pada `Decimal`:
Semua metode menerima dan mengembalikan `string`. Jika parameter berupa non-numeric string atau float, metode akan melempar `InvalidArgumentException`.
- `Decimal::add(string $left, string $right, int $scale): string`
- `Decimal::sub(string $left, string $right, int $scale): string`
- `Decimal::mul(string $left, string $right, int $scale): string`
- `Decimal::div(string $left, string $right, int $scale): string` (melempar `DivisionByZeroError` bila pembagi nol)
- `Decimal::cmp(string $left, string $right, int $scale): int` (mengembalikan `-1`, `0`, atau `1`)
- `Decimal::round(string $value, int $scale): string` (pembulatan *half-up away from zero*)
- `Decimal::isNegative(string $value): bool`
- `Decimal::isZero(string $value): bool`
- `Decimal::normalize(string $value): string`

Contoh Penggunaan:
```php
use App\Support\Decimal;

$subtotal = '150000.00';
$taxRate = '0.110000'; // PPN 11%

$taxAmount = Decimal::round(
    Decimal::mul($subtotal, $taxRate, Decimal::SCALE_INTERNAL),
    Decimal::SCALE_MONEY
); // '16500.00'

$total = Decimal::add($subtotal, $taxAmount, Decimal::SCALE_MONEY); // '166500.00'
```

---

## 3. Penjaga Transaksi (`App\Support\Concerns\RequiresTransaction`)

Setiap metode internal atau service yang menuntut eksekusi di dalam transaksi database wajib menyertakan trait `RequiresTransaction` dan memanggil `self::ensureInTransaction()`.

Jika metode dipanggil di luar transaksi aktif (`DB::transactionLevel() <= 0`), trait akan langsung melempar `LogicException`, mencegah eksekusi mutasi parsial tanpa proteksi rollback.

---

## 4. Penomoran Dokumen Otomatis (`App\Services\Documents\DocumentNumberService`)

### Karakteristik & Aturan:
1. **Format Penomoran Baku**: `{PREFIX}/{YYYYMM}/{NNNNN}` (contoh: `PO/202610/00001`).
2. **Karakteristik Nomor**: *Gapless* dan berurutan strictly per prefix per periode (bulan/tahun).
3. **Penyimpanan Counter**: Disimpan di tabel `document_sequences` dengan kolom `(prefix, period, next_number)`.
4. **Proteksi Konkurensi**:
   - `insertOrIgnore` untuk inisialisasi baris sequence secara aman bila belum ada.
   - `lockForUpdate()` (pessimistic row locking) untuk memastikan nomor tidak ganda di lingkungan konkurensi tinggi.
5. **Rollback Behavior**:
   - Karena transaksi membatalkan seluruh operasi bila terjadi error sebelum commit, nomor sequence yang belum commit otomatis ter-rollback dan counter tidak bertambah (menjamin *zero gap*).

Contoh:
```php
use App\Enums\DocumentType;
use App\Services\Documents\DocumentNumberService;
use Illuminate\Support\Facades\DB;

$number = DB::transaction(function () use ($documentNumberService): string {
    return $documentNumberService->generate(
        DocumentType::PURCHASE_ORDER,
        now()
    );
}); // 'PO/202610/00001'
```

---

## 5. Status Enums & State Machine Dokumen

### A. Backed Enums (`App\Enums`)
Seluruh status transaksi dan entitas diwakili oleh enum PHP *string-backed*:
- `DocumentType`: Tipe dokumen transaksi (`purchase_order`, `goods_receipt`, dsb).
- `PurchaseOrderStatus`: `draft`, `submitted`, `approved`, `partial`, `completed`, `cancelled`.
- `SalesOrderStatus`: `draft`, `confirmed`, `processing`, `partial`, `shipped`, `delivered`, `completed`, `cancelled`.
- `GoodsReceiptStatus`, `DeliveryOrderStatus`, `StockAdjustmentStatus`, `InvoiceStatus`, `PaymentStatus`, `JournalStatus`, dsb.

### B. State Machine Dokumen (`App\Services\Documents\DocumentStateMachine`)
Mengelola transisi status yang sah untuk dokumen bisnis:
- `canTransition(DocumentType $type, BackedEnum|string $from, BackedEnum|string $to): bool`
- `assertCanTransition(DocumentType $type, BackedEnum|string $from, BackedEnum|string $to): void`
  - Jika transisi tidak sah, melempar `InvalidStateTransitionException` (HTTP 409 Conflict).
- `allowedTransitions(DocumentType $type, BackedEnum|string $from): array`

### C. Cara Menambah Status Baru / Enum:
1. Definisikan status pada file enum di `App\Enums\*Status.php` (harus `enum ...: string`).
2. Perbarui matriks transisi di `DocumentStateMachine::TRANSITIONS`.
3. Tambahkan nilai enum ke migrasi MariaDB `CHECK (status IN (...))`.
4. Jalankan `EnumDriftTest` (`./vendor/bin/pest tests/Feature/EnumDriftTest.php`) untuk memastikan tidak ada perbedaan (*drift*) antara kode PHP dan database skema MariaDB.

---

## 6. Contoh Pola Gabungan: Service + Transaksi + Penomoran + State Machine

```php
namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\DocumentNotEditableException;
use App\Models\PurchaseOrder;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentStateMachine;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderService
{
    public function __construct(
        private readonly DocumentNumberService $numberService,
        private readonly DocumentStateMachine $stateMachine,
    ) {}

    public function createOrder(array $attributes, array $items): PurchaseOrder
    {
        return DB::transaction(function () use ($attributes, $items): PurchaseOrder {
            // 1. Generate nomor gapless
            $orderNumber = $this->numberService->generate(DocumentType::PURCHASE_ORDER);

            // 2. Simpan order dengan status awal DRAFT
            $order = PurchaseOrder::create([
                ...$attributes,
                'order_number' => $orderNumber,
                'status' => PurchaseOrderStatus::DRAFT->value,
            ]);

            // 3. Simpan item-item pesanan
            $order->items()->createMany($items);

            return $order;
        });
    }

    public function approveOrder(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            // 1. Kunci baris untuk pembaruan
            $order = PurchaseOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

            // 2. Validasi transisi status
            $this->stateMachine->assertCanTransition(
                DocumentType::PURCHASE_ORDER,
                $order->status,
                PurchaseOrderStatus::APPROVED
            );

            // 3. Perbarui status
            $order->status = PurchaseOrderStatus::APPROVED->value;
            $order->save();
        });
    }
}
```

---

## 7. Hierarki Exception Bisnis

Seluruh exception aturan bisnis meng-extend `App\Exceptions\BusinessRuleException`:
- `InvalidStateTransitionException`: Transisi status dokumen yang tidak diizinkan (HTTP 409 Conflict).
- `DocumentNotEditableException`: Dokumen sudah diposting atau void dan tidak dapat diubah lagi (HTTP 409 Conflict).
- `InsufficientStockException`: Stok tidak mencukupi untuk alokasi/pengeluaran barang (HTTP 422 Unprocessable Content).
- `TooManyAttemptsException`: Pembatasan laju otentikasi (HTTP 429 Too Many Requests).

### Penanganan Error pada Antarmuka (Form Error Handling):
Exception bisnis menggunakan trait `RendersAsFormError`:
- Jika request berupa **Inertia / Web Form Submission**: Mengembalikan HTTP 303 Redirect kembali ke halaman asal (`back()`) dengan menyertakan pesan error di dalam Form Error Bag (`withErrors(['general' => $message])`).
- Jika request mengharapkan **JSON (API)**: Mengembalikan respons JSON dengan HTTP status code yang sesuai (`409` atau `422`).
- Seluruh exception bisnis dan throttle didaftarkan ke `$exceptions->dontReport(...)` di `bootstrap/app.php` sehingga tidak mengotori file log produksi dengan stack trace.

---

## 8. Kontrak Query Daftar Aman (`ListQuery`, `ListRequest`, `Paginated`)

### Komponen Query:
1. **`ListRequest`**: FormRequest baku untuk validasi parameter list (`page`, `per_page` maksimal 100, `sort`, `direction`, `search`, `filter`).
2. **`ListQuery`**: Query builder aman dengan proteksi:
   - Sanitasi wildcard SQL `LIKE` (`%`, `_`, `\`) sehingga karakter pencarian diproses secara literal.
   - Whitelist kolom sorting untuk mencegah SQL Injection.
   - Penambahan secondary sort `id ASC` otomatis demi stabilitas paginasi.
   - Filter ketat hanya pada kolom yang diizinkan (*whitelisted*).
3. **`Paginated`**: Helper format respons standar untuk Inertia.js dengan struktur seragam:
   ```json
   {
     "data": [...],
     "meta": {
       "current_page": 1,
       "last_page": 5,
       "per_page": 15,
       "total": 68
     },
     "query": {
       "search": "baut",
       "sort": "name",
       "direction": "asc",
       "filter": {"category_id": "2"}
     }
   }
   ```

---

## 9. Jejak Audit Model (`HasAuditUsers`, `RecordsAudit`)

- **`HasAuditUsers`**: Otomatis mengisi kolom `created_by`, `updated_by`, dan `deleted_by` dengan ID pengguna terotentikasi saat ini.
- **`RecordsAudit`**: Menangkap nilai atribut lama (`dirtyOldBeforeUpdate`) di dalam *in-memory store* internal sebelum update, lalu pada event `created`, `updated`, dan `deleted` menulis rekaman ke tabel `audit_logs`.
- Catatan teknis: `audit_logs` dilindungi oleh trigger MariaDB `trg_audit_logs_prevent_update` dan `trg_audit_logs_prevent_delete` (append-only strictly).

---

## 10. Alat Bantu Pengujian (*Test Helpers* & *Arch Tests*)

### Helper Global Pest:
- `userWithPermissions(array $names): User`: Membuat instance User uji dengan role temporer yang memuat izin (*permissions*) yang ditentukan.
- `userWithRole(string $role): User`: Membuat instance User uji dan memasangkannya dengan role tertentu (misal: `'admin'`, `'viewer'`).

### Aturan Arsitektur (Pest Arch Tests):
1. `App\Http\Controllers` dilarang memanggil `DB` facade langsung.
2. Seluruh enum di `App\Enums` wajib merupakan string-backed enum (`BackedEnum`).
3. Seluruh kelas utilitas di `App\Support` wajib bertipe `final`.
4. Seluruh Form Request wajib berada di `App\Http\Requests` dan meng-extend `FormRequest`.
5. `App\Services` dilarang memiliki ketergantungan ke `App\Http`.
