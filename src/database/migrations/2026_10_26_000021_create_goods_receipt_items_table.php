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
        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['goods_receipt_id', 'line_no']);
        });

        DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT chk_goods_receipt_items_qty CHECK (quantity > 0)');
        DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT chk_goods_receipt_items_unit_cost CHECK (unit_cost >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
    }
};
