<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Accounting\JournalBackfillService;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;

class BackfillJournalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erp:journals:backfill {--execute : Tulis jurnal ke database (tanpa opsi ini hanya simulasi)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Membuat jurnal untuk dokumen yang diposting sebelum modul akuntansi ada';

    /**
     * Execute the console command.
     */
    public function handle(JournalBackfillService $backfill): int
    {
        $execute = (bool) $this->option('execute');

        $counts = $backfill->run($execute);

        info($execute ? 'Backfill jurnal selesai.' : 'DRY-RUN: tidak ada data yang ditulis. Pakai --execute untuk menulis.');
        $this->line("Penerimaan barang: {$counts['goods_receipt']}");
        $this->line("Surat jalan: {$counts['delivery_order']}");
        $this->line("Penyesuaian stok: {$counts['stock_adjustment']}");

        return self::SUCCESS;
    }
}
