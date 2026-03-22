<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('return_reason_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            $table->string('jaremar_return_id')->nullable();  // devolucion de Jaremar
            $table->enum('type', ['total', 'partial']);
            $table->string('manifest_number', 20)->nullable(); // numeroManifiesto de Jaremar (puede diferir)

            // Cliente
            $table->string('client_id', 20)->nullable();
            $table->string('client_name')->nullable();

            // Fechas
            $table->date('return_date');
            $table->date('processed_date')->nullable();
            $table->time('processed_time')->nullable();

            $table->decimal('total', 12, 2)->default(0);

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['manifest_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};