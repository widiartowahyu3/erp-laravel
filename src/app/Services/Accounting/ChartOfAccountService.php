<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\BusinessRuleException;
use App\Models\ChartOfAccount;
use App\Models\JournalLine;
use Illuminate\Support\Facades\DB;

final class ChartOfAccountService
{
    /**
     * Fields that may not change once journal lines refer to the account.
     */
    private const array LOCKED_WHEN_USED = ['code', 'type', 'normal_balance', 'is_postable'];

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function create(array $data): ChartOfAccount
    {
        return DB::transaction(function () use ($data): ChartOfAccount {
            $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
            $this->assertValidParent($parentId, null);

            return ChartOfAccount::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'],
                'normal_balance' => $data['normal_balance'],
                'parent_id' => $parentId,
                'is_postable' => $data['is_postable'] ?? true,
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function update(ChartOfAccount $account, array $data): ChartOfAccount
    {
        return DB::transaction(function () use ($account, $data): ChartOfAccount {
            $locked = ChartOfAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
            $isPostable = (bool) ($data['is_postable'] ?? $locked->is_postable);
            $isActive = (bool) ($data['is_active'] ?? $locked->is_active);

            $this->assertValidParent($parentId, $locked->id);

            $new = [
                'code' => (string) $data['code'],
                'type' => (string) $data['type'],
                'normal_balance' => (string) $data['normal_balance'],
                'is_postable' => $isPostable,
            ];
            $current = [
                'code' => $locked->code,
                'type' => $locked->type->value,
                'normal_balance' => $locked->normal_balance->value,
                'is_postable' => $locked->is_postable,
            ];

            if ($this->isUsedByJournals($locked)) {
                foreach (self::LOCKED_WHEN_USED as $field) {
                    if ($new[$field] !== $current[$field]) {
                        throw new BusinessRuleException('Kolom ini tidak dapat diubah karena akun sudah dipakai pada jurnal.', 422, $field);
                    }
                }
            }

            if ($this->isMapped($locked)) {
                if ($new['code'] !== $current['code']) {
                    throw new BusinessRuleException('Kode akun yang dipetakan pada konfigurasi akuntansi tidak dapat diubah.', 422, 'code');
                }

                if (! $isActive) {
                    throw new BusinessRuleException('Akun yang dipetakan pada konfigurasi akuntansi tidak dapat dinonaktifkan.', 422, 'is_active');
                }

                if (! $isPostable) {
                    throw new BusinessRuleException('Akun yang dipetakan pada konfigurasi akuntansi harus dapat diposting.', 422, 'is_postable');
                }
            }

            if ($isPostable && $locked->children()->exists()) {
                throw new BusinessRuleException('Akun yang memiliki anak harus berupa akun induk.', 422, 'is_postable');
            }

            // @audit-bypass: Audited via RecordsAudit model event
            $locked->update([
                'code' => $new['code'],
                'name' => $data['name'],
                'type' => $new['type'],
                'normal_balance' => $new['normal_balance'],
                'parent_id' => $parentId,
                'is_postable' => $isPostable,
                'is_active' => $isActive,
            ]);

            return $locked;
        });
    }

    /**
     * @throws BusinessRuleException
     */
    public function delete(ChartOfAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            $locked = ChartOfAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($this->isMapped($locked)) {
                throw new BusinessRuleException('akun dipetakan pada konfigurasi akuntansi.', 409);
            }

            if ($this->isUsedByJournals($locked)) {
                throw new BusinessRuleException('akun sudah dipakai pada jurnal.', 409);
            }

            if ($locked->children()->exists()) {
                throw new BusinessRuleException('akun masih memiliki anak.', 409);
            }

            // @audit-bypass: Audited via RecordsAudit model event
            $locked->delete();
        });
    }

    public function isUsedByJournals(ChartOfAccount $account): bool
    {
        return JournalLine::query()->where('account_id', $account->id)->exists();
    }

    public function isMapped(ChartOfAccount $account): bool
    {
        return in_array($account->code, array_merge(
            array_values((array) config('accounting.accounts')),
            (array) config('accounting.cash_bank')
        ), true);
    }

    /**
     * Depth of every account in the tree, computed from one query.
     *
     * @return array<int, int>
     */
    public function levels(): array
    {
        /** @var array<int, int|null> $parents */
        $parents = ChartOfAccount::query()->pluck('parent_id', 'id')->all();
        $levels = [];

        foreach (array_keys($parents) as $id) {
            $level = 0;
            $cursor = $parents[$id] ?? null;

            while ($cursor !== null && $level < count($parents)) {
                $level++;
                $cursor = $parents[$cursor] ?? null;
            }

            $levels[$id] = $level;
        }

        return $levels;
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertValidParent(?int $parentId, ?int $accountId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $accountId) {
            throw new BusinessRuleException('Akun tidak boleh menjadi induk untuk dirinya sendiri.', 422, 'parent_id');
        }

        $parent = ChartOfAccount::query()->whereKey($parentId)->first();

        if ($parent === null) {
            throw new BusinessRuleException('Akun induk tidak ditemukan.', 422, 'parent_id');
        }

        if ($parent->is_postable) {
            throw new BusinessRuleException('Akun induk harus berupa akun header yang tidak dapat diposting.', 422, 'parent_id');
        }

        $cursor = $parent->parent_id;
        $guard = 0;

        while ($cursor !== null && $guard++ < 100) {
            if ($cursor === $accountId) {
                throw new BusinessRuleException('Akun induk ini akan membentuk siklus hierarki.', 422, 'parent_id');
            }

            $cursor = ChartOfAccount::query()->whereKey($cursor)->value('parent_id');
        }
    }
}
