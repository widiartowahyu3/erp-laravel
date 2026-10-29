<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DeliveryOrderStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\JournalStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockMovementType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SyncFrontendEnumsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erp:frontend:sync-enums';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mengekstrak enum status backend PHP dan menghasilkan resources/js/generated/enums.json';

    /**
     * Default variant mappings for known status keys.
     *
     * @var array<string, string>
     */
    private const VARIANT_MAP = [
        'draft' => 'neutral',
        'closed' => 'neutral',
        'inactive' => 'neutral',
        'cash' => 'neutral',
        'other' => 'neutral',
        'approved' => 'info',
        'receipt' => 'info',
        'delivery' => 'info',
        'adjustment_in' => 'info',
        'bank_transfer' => 'info',
        'partially_received' => 'warning',
        'partially_delivered' => 'warning',
        'partially_paid' => 'warning',
        'adjustment_out' => 'warning',
        'received' => 'success',
        'delivered' => 'success',
        'posted' => 'success',
        'paid' => 'success',
        'active' => 'success',
        'void' => 'danger',
    ];

    /**
     * Generate the consolidated enum status mapping array.
     *
     * @return array<string, array{label: string, variant: string}>
     */
    public static function generateMap(): array
    {
        $statusMap = [];

        /** @var list<class-string<\BackedEnum>> $enumClasses */
        $enumClasses = [
            PurchaseOrderStatus::class,
            SalesOrderStatus::class,
            GoodsReceiptStatus::class,
            DeliveryOrderStatus::class,
            PurchaseInvoiceStatus::class,
            SalesInvoiceStatus::class,
            StockAdjustmentStatus::class,
            PaymentStatus::class,
            JournalStatus::class,
            StockMovementType::class,
            PaymentMethod::class,
        ];

        foreach ($enumClasses as $enumClass) {
            /** @var \BackedEnum $case */
            foreach ($enumClass::cases() as $case) {
                $val = (string) $case->value;
                $label = method_exists($case, 'label') ? $case->label() : ucfirst($val);
                $variant = self::VARIANT_MAP[$val] ?? 'neutral';

                $statusMap[$val] = [
                    'label' => $label,
                    'variant' => $variant,
                ];
            }
        }

        // Include master data active/inactive boolean states
        $statusMap['active'] = [
            'label' => 'Aktif',
            'variant' => 'success',
        ];
        $statusMap['inactive'] = [
            'label' => 'Nonaktif',
            'variant' => 'neutral',
        ];

        return $statusMap;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $statusMap = self::generateMap();
        $targetDir = resource_path('js/generated');
        $targetPath = $targetDir.'/enums.json';

        if (! File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, 0755, true, true);
        }

        $json = json_encode($statusMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        File::put($targetPath, $json);

        $this->info('Berhasil menyinkronkan '.count($statusMap)." status ke {$targetPath}");

        return self::SUCCESS;
    }
}
