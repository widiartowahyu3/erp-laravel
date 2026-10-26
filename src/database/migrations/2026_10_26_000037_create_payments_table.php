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
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('payment_type', 20);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete()->restrictOnUpdate();
            $table->date('payment_date');
            $table->string('method', 30);
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('amount', 18, 2);
            $table->string('reference', 100)->nullable();
            $table->string('status', 20)->default('draft');
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
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_type CHECK (payment_type IN ('receipt', 'disbursement'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_method CHECK (method IN ('cash', 'bank_transfer', 'other'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_status CHECK (status IN ('draft', 'posted', 'void'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_party CHECK ((payment_type = 'receipt' AND customer_id IS NOT NULL AND supplier_id IS NULL) OR (payment_type = 'disbursement' AND supplier_id IS NOT NULL AND customer_id IS NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
