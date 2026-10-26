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
        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('avg_cost', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);
        });

        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT chk_stock_balances_quantity CHECK (quantity >= 0)');
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT chk_stock_balances_avg_cost CHECK (avg_cost >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
