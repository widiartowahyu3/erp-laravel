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
        Schema::create('sales_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('quantity', 18, 4);
            $table->decimal('delivered_quantity', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['sales_order_id', 'line_no']);
        });

        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT chk_sales_order_items_qty CHECK (quantity > 0)');
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT chk_sales_order_items_deliv_qty CHECK (delivered_quantity >= 0)');
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT chk_sales_order_items_unit_price CHECK (unit_price >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_order_items');
    }
};
