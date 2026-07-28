<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos del depósito que acompañan la aplicación multi-manifiesto.
 *
 * justification
 *   Texto obligatorio (validado en app, nullable en BD por los registros
 *   históricos) cuando el monto de la boleta SUPERA el saldo pendiente del
 *   manifiesto de origen. Responde la pregunta de auditoría "¿por qué se
 *   depositó más de lo que este manifiesto debía?".
 *
 * allocated_amount
 *   Cache de SUM(deposit_allocations.amount) del depósito. Existe para no
 *   disparar un subquery por fila al listar depósitos en la tabla de
 *   Filament. Se escribe SIEMPRE dentro de la misma transacción que toca
 *   las allocations, nunca desde fuera del DepositService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->text('justification')->nullable()->after('observations');
            $table->decimal('allocated_amount', 12, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropColumn(['justification', 'allocated_amount']);
        });
    }
};
