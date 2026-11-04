<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Models\Permission;

/**
 * Groups the permission table into module, resource and action for the role matrix.
 */
final class PermissionCatalog
{
    private const array MODULE_LABELS = [
        'administration' => 'Administrasi',
        'master_data' => 'Data Master',
        'purchasing' => 'Pembelian',
        'sales' => 'Penjualan',
        'inventory' => 'Persediaan',
        'finance' => 'Keuangan',
        'reporting' => 'Laporan',
    ];

    private const array ACTION_LABELS = [
        'view' => 'Lihat',
        'create' => 'Tambah',
        'update' => 'Ubah',
        'delete' => 'Hapus',
        'approve' => 'Setujui',
        'post' => 'Posting',
        'void' => 'Batalkan',
        'close' => 'Tutup',
    ];

    private const string VERB_PREFIX = '/^(Melihat|Menambah|Membuat|Mengubah|Menghapus|Menyetujui|Memposting|Membatalkan \(Void\)|Membalik)\s+/u';

    /**
     * @return list<array{key: string, label: string, resources: list<array{key: string, label: string, actions: list<array{action: string, name: string, label: string}>}>}>
     */
    public function matrix(): array
    {
        $grouped = [];

        foreach (Permission::query()->orderBy('id')->get(['name', 'module', 'display_name']) as $permission) {
            [$resource, $action] = array_pad(explode('.', $permission->name, 2), 2, '');
            $grouped[$permission->module][$resource][] = [
                'action' => $action,
                'name' => $permission->name,
                'label' => self::ACTION_LABELS[$action] ?? $permission->display_name,
                'display_name' => $permission->display_name,
            ];
        }

        $modules = [];

        foreach ($this->orderModules(array_keys($grouped)) as $module) {
            $resources = [];

            foreach ($grouped[$module] as $resource => $actions) {
                $resources[] = [
                    'key' => $resource,
                    'label' => $this->resourceLabel($resource, $actions),
                    'actions' => $this->orderActions($actions),
                ];
            }

            $modules[] = [
                'key' => $module,
                'label' => self::MODULE_LABELS[$module] ?? $module,
                'resources' => $resources,
            ];
        }

        return $modules;
    }

    /**
     * @param  list<string>  $modules
     * @return list<string>
     */
    private function orderModules(array $modules): array
    {
        $known = array_values(array_intersect(array_keys(self::MODULE_LABELS), $modules));

        return [...$known, ...array_values(array_diff($modules, $known))];
    }

    /**
     * @param  list<array{action: string, name: string, label: string, display_name: string}>  $actions
     */
    private function resourceLabel(string $resource, array $actions): string
    {
        if ($resource === 'reports') {
            return 'Laporan';
        }

        $source = $actions[0]['display_name'];

        foreach ($actions as $action) {
            if ($action['action'] === 'view') {
                $source = $action['display_name'];
            }
        }

        return (string) preg_replace(self::VERB_PREFIX, '', $source);
    }

    /**
     * @param  list<array{action: string, name: string, label: string, display_name: string}>  $actions
     * @return list<array{action: string, name: string, label: string}>
     */
    private function orderActions(array $actions): array
    {
        $order = array_flip(array_keys(self::ACTION_LABELS));

        usort($actions, fn (array $a, array $b): int => ($order[$a['action']] ?? PHP_INT_MAX) <=> ($order[$b['action']] ?? PHP_INT_MAX));

        return array_map(fn (array $action): array => [
            'action' => $action['action'],
            'name' => $action['name'],
            'label' => $action['label'],
        ], $actions);
    }
}
