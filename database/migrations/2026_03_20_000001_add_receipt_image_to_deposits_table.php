<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega soporte para imagen de comprobante de depósito.
     *
     * Se guarda solo la ruta relativa dentro del disco 'public'
     * (storage/app/public/deposits/receipts/{uuid}.{ext}).
     *
     * La columna receipt_image_uploaded_at permite que la tarea
     * programada sepa cuándo se subió la imagen (no cuándo se creó
     * el depósito), lo que evita borrar imágenes recién subidas
     * a depósitos antiguos.
     */
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->string('receipt_image')->nullable()->after('observations');
            $table->timestamp('receipt_image_uploaded_at')->nullable()->after('receipt_image');
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropColumn(['receipt_image', 'receipt_image_uploaded_at']);
        });
    }
};
