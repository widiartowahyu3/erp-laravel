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
        Schema::create('purchase_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('goods_receipt_item_id')->nullable()->constrained('goods_receipt_items')->restrictOnDelete()->restrictOnUpdate();
            $table->string('description', 255);
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['purchase_invoice_id', 'line_no']);
        });

        DB::statement('ALTER TABLE purchase_invoice_items ADD CONSTRAINT chk_purchase_invoice_items_qty CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_items');
    }
};
