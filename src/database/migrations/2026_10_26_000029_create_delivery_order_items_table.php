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
        Schema::create('delivery_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_order_id')->constrained('delivery_orders')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('sales_order_item_id')->constrained('sales_order_items')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['delivery_order_id', 'line_no']);
        });

        DB::statement('ALTER TABLE delivery_order_items ADD CONSTRAINT chk_delivery_order_items_qty CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_order_items');
    }
};
