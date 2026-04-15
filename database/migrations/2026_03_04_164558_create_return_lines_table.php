<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_line_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('line_number');
            $table->string('product_id', 20);
            $table->string('product_description');
            $table->decimal('quantity', 10, 4)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);

            $table->timestamps();

            $table->index(['return_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_lines');
    }
};
