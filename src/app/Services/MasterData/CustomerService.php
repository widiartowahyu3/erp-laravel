<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

final class CustomerService
{
    /**
     * @param  array{code: string, name: string, contact_name?: string|null, phone?: string|null, email?: string|null, address?: string|null, tax_id?: string|null, credit_limit?: string|numeric|null, payment_term_id?: int|string|null, is_active?: bool}  $data
     */
    public function create(array $data): Customer
    {
        $paymentTermId = isset($data['payment_term_id']) && $data['payment_term_id'] !== '' ? (int) $data['payment_term_id'] : null;
        $creditLimit = isset($data['credit_limit']) && $data['credit_limit'] !== '' ? (string) $data['credit_limit'] : '0.00';

        return DB::transaction(function () use ($data, $paymentTermId, $creditLimit): Customer {
            return Customer::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'contact_name' => $data['contact_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,
                'credit_limit' => $creditLimit,
                'payment_term_id' => $paymentTermId,
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * @param  array{code: string, name: string, contact_name?: string|null, phone?: string|null, email?: string|null, address?: string|null, tax_id?: string|null, credit_limit?: string|numeric|null, payment_term_id?: int|string|null, is_active?: bool}  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        $paymentTermId = isset($data['payment_term_id']) && $data['payment_term_id'] !== '' ? (int) $data['payment_term_id'] : null;
        $creditLimit = isset($data['credit_limit']) && $data['credit_limit'] !== '' ? (string) $data['credit_limit'] : '0.00';

        return DB::transaction(function () use ($customer, $data, $paymentTermId, $creditLimit): Customer {
            $customer->update([
                'code' => $data['code'],
                'name' => $data['name'],
                'contact_name' => $data['contact_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,
                'credit_limit' => $creditLimit,
                'payment_term_id' => $paymentTermId,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $customer;
        });
    }

    public function delete(Customer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            ReferenceGuard::assertCanDelete($customer, 'Pelanggan');
            $customer->delete();
        });
    }
}
