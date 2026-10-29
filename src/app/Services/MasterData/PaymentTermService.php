<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use App\Models\PaymentTerm;
use Illuminate\Support\Facades\DB;

final class PaymentTermService
{
    /**
     * @param  array{code: string, name: string, days: int|string}  $data
     */
    public function create(array $data): PaymentTerm
    {
        return DB::transaction(function () use ($data): PaymentTerm {
            return PaymentTerm::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'days' => (int) $data['days'],
            ]);
        });
    }

    /**
     * @param  array{code: string, name: string, days: int|string}  $data
     */
    public function update(PaymentTerm $paymentTerm, array $data): PaymentTerm
    {
        return DB::transaction(function () use ($paymentTerm, $data): PaymentTerm {
            // @audit-bypass: Audited via RecordsAudit model event
            $paymentTerm->update([
                'code' => $data['code'],
                'name' => $data['name'],
                'days' => (int) $data['days'],
            ]);

            return $paymentTerm;
        });
    }

    public function delete(PaymentTerm $paymentTerm): void
    {
        DB::transaction(function () use ($paymentTerm): void {
            ReferenceGuard::assertCanDelete($paymentTerm, 'Syarat Pembayaran');
            // @audit-bypass: Audited via RecordsAudit model event
            $paymentTerm->delete();
        });
    }
}
