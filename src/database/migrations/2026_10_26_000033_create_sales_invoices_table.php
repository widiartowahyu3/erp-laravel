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
        Schema::create('sales_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->restrictOnDelete()->restrictOnUpdate();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete()->restrictOnUpdate();
            $table->text('notes')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();

            $table->index(['customer_id', 'status']);
            $table->index('due_date');
        });

        DB::statement("ALTER TABLE sales_invoices ADD CONSTRAINT chk_sales_invoices_status CHECK (status IN ('draft', 'posted', 'partially_paid', 'paid', 'void'))");
        DB::statement('ALTER TABLE sales_invoices ADD CONSTRAINT chk_sales_invoices_paid CHECK (paid_amount >= 0 AND paid_amount <= total)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
