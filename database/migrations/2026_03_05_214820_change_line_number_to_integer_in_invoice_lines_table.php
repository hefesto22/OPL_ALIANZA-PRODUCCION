<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->unsignedInteger('line_number')->change();
        });
    }
    
    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->unsignedSmallInteger('line_number')->change();
        });
    }
};
