<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manifests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('number', 20)->unique(); // NumeroManifiesto
            $table->date('date');
            $table->enum('status', ['pending', 'processing', 'imported', 'closed'])->default('pending');

            // Totales calculados
            $table->decimal('total_invoices', 12, 2)->default(0);
            $table->decimal('total_returns', 12, 2)->default(0);
            $table->decimal('total_to_deposit', 12, 2)->default(0);
            $table->decimal('total_deposited', 12, 2)->default(0);
            $table->decimal('difference', 12, 2)->default(0);

            // Conteos
            $table->unsignedInteger('invoices_count')->default(0);
            $table->unsignedInteger('returns_count')->default(0);

            // JSON original de Jaremar
            $table->json('raw_json')->nullable();

            // Cierre
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifests');
    }
};
