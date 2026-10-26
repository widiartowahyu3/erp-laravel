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
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('name', 150);
            $table->foreignId('category_id')->constrained('product_categories')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete()->restrictOnUpdate();
            $table->string('barcode', 50)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('track_stock')->default(true);
            $table->decimal('selling_price', 18, 2)->default(0);
            $table->decimal('min_stock', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();

            $table->index('category_id');
            $table->index('name');
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_selling_price CHECK (selling_price >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_min_stock CHECK (min_stock >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
