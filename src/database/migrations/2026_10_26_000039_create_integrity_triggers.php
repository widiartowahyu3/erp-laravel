<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared("CREATE TRIGGER tr_audit_logs_before_update BEFORE UPDATE ON audit_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'; END");
        DB::unprepared("CREATE TRIGGER tr_audit_logs_before_delete BEFORE DELETE ON audit_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'; END");
        DB::unprepared("CREATE TRIGGER tr_stock_movements_before_update BEFORE UPDATE ON stock_movements FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'; END");
        DB::unprepared("CREATE TRIGGER tr_stock_movements_before_delete BEFORE DELETE ON stock_movements FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'; END");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS tr_audit_logs_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS tr_audit_logs_before_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS tr_stock_movements_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS tr_stock_movements_before_delete');
    }
};
