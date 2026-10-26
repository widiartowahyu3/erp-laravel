<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->date('receipt_date');
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();

            $table->index('purchase_order_id');
            $table->index('receipt_date');
        });

        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT chk_goods_receipts_status CHECK (status IN ('draft', 'posted', 'void'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};
