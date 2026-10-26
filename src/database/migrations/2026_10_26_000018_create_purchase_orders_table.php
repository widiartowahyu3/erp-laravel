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
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete()->restrictOnUpdate();
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();

            $table->index(['supplier_id', 'status']);
            $table->index('order_date');
        });

        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT chk_purchase_orders_status CHECK (status IN ('draft', 'approved', 'partially_received', 'received', 'closed', 'void'))");
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT chk_purchase_orders_total CHECK (subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND total >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
