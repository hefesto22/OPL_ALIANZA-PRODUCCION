<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: agregar 'cancelled' al CHECK constraint de status
        DB::statement("ALTER TABLE returns DROP CONSTRAINT IF EXISTS returns_status_check");

        // Recrear como CHECK constraint con el nuevo valor
        DB::statement("ALTER TABLE returns ADD CONSTRAINT returns_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE returns DROP CONSTRAINT IF EXISTS returns_status_check");
        DB::statement("ALTER TABLE returns ADD CONSTRAINT returns_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
    }
};