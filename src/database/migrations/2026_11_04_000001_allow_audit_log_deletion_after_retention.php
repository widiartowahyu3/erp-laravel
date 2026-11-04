<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Audit rows may be deleted once they are older than 7 days. Newer rows stay protected at database level.
     * SYSDATE() is deliberate: NOW() follows SET timestamp, which any session can change to fake a row's age.
     */
    public function up(): void
    {
        DB::unprepared("CREATE OR REPLACE TRIGGER tr_audit_logs_before_delete BEFORE DELETE ON audit_logs FOR EACH ROW BEGIN IF OLD.created_at >= SYSDATE() - INTERVAL 7 DAY THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'; END IF; END");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared("CREATE OR REPLACE TRIGGER tr_audit_logs_before_delete BEFORE DELETE ON audit_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'; END");
    }
};
