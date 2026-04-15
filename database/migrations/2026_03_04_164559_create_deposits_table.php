<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 12, 2);
            $table->date('deposit_date');
            $table->string('bank')->nullable();
            $table->string('reference')->nullable();
            $table->text('observations')->nullable();

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('manifest_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
