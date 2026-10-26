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
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(['payment_id', 'sales_invoice_id']);
            $table->unique(['payment_id', 'purchase_invoice_id']);
        });

        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT chk_payment_allocations_amount CHECK (amount > 0)');
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT chk_payment_allocations_target CHECK ((sales_invoice_id IS NOT NULL AND purchase_invoice_id IS NULL) OR (purchase_invoice_id IS NOT NULL AND sales_invoice_id IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
