<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_status_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
            CHECK (status IN ('imported','pending_warehouse','partial_return','returned','rejected','deposited','closed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_status_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
            CHECK (status IN ('imported','pending_warehouse','partial_return','returned','deposited','closed'))");
    }
};
