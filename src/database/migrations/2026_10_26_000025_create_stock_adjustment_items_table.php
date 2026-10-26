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
        Schema::create('stock_adjustment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('system_quantity', 18, 4);
            $table->decimal('counted_quantity', 18, 4);
            $table->decimal('difference_quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->timestamps();

            $table->unique(['stock_adjustment_id', 'line_no']);
        });

        DB::statement('ALTER TABLE stock_adjustment_items ADD CONSTRAINT chk_stock_adjustment_items_counted_qty CHECK (counted_quantity >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
    }
};
