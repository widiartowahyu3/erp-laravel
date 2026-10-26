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
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('movement_date');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $table->string('movement_type', 30);
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->decimal('avg_cost_after', 18, 4);
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'warehouse_id', 'movement_date']);
            $table->index(['source_type', 'source_id']);
        });

        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movements_type CHECK (movement_type IN ('receipt', 'delivery', 'adjustment_in', 'adjustment_out'))");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movements_quantity CHECK (quantity <> 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
