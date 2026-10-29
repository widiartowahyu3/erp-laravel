<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

final class SupplierService
{
    /**
     * @param  array{code: string, name: string, contact_name?: string|null, phone?: string|null, email?: string|null, address?: string|null, tax_id?: string|null, payment_term_id?: int|string|null, is_active?: bool}  $data
     */
    public function create(array $data): Supplier
    {
        $paymentTermId = isset($data['payment_term_id']) && $data['payment_term_id'] !== '' ? (int) $data['payment_term_id'] : null;

        return DB::transaction(function () use ($data, $paymentTermId): Supplier {
            return Supplier::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'contact_name' => $data['contact_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,
                'payment_term_id' => $paymentTermId,
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * @param  array{code: string, name: string, contact_name?: string|null, phone?: string|null, email?: string|null, address?: string|null, tax_id?: string|null, payment_term_id?: int|string|null, is_active?: bool}  $data
     */
    public function update(Supplier $supplier, array $data): Supplier
    {
        $paymentTermId = isset($data['payment_term_id']) && $data['payment_term_id'] !== '' ? (int) $data['payment_term_id'] : null;

        return DB::transaction(function () use ($supplier, $data, $paymentTermId): Supplier {
            // @audit-bypass: Audited via RecordsAudit model event
            $supplier->update([
                'code' => $data['code'],
                'name' => $data['name'],
                'contact_name' => $data['contact_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,
                'payment_term_id' => $paymentTermId,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $supplier;
        });
    }

    public function delete(Supplier $supplier): void
    {
        DB::transaction(function () use ($supplier): void {
            ReferenceGuard::assertCanDelete($supplier, 'Pemasok');
            // @audit-bypass: Audited via RecordsAudit model event
            $supplier->delete();
        });
    }
}
