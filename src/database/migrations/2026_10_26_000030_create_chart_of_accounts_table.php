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
        Schema::create('chart_of_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('type', 20);
            $table->string('normal_balance', 6);
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE chart_of_accounts ADD CONSTRAINT chk_coa_type CHECK (type IN ('asset', 'liability', 'equity', 'revenue', 'expense'))");
        DB::statement("ALTER TABLE chart_of_accounts ADD CONSTRAINT chk_coa_normal_balance CHECK (normal_balance IN ('debit', 'credit'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
