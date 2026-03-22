<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_invoice_imports', function (Blueprint $table) {
            $table->id();

            // Identificación del batch recibido
            $table->string('batch_uuid', 36)->unique();         // UUID único por llamada al endpoint
            $table->string('api_key_hint', 8);                  // Primeros 8 chars del ApiKey usado (auditoría sin exponer el key completo)
            $table->string('ip_address', 45)->nullable();        // IP de origen (OPL de Jaremar)
            $table->unsignedInteger('total_received');           // Cantidad de facturas recibidas en este batch

            // Payload crudo — siempre se guarda, sin importar qué pase después
            $table->json('raw_payload');                         // El array JSON tal cual llegó
            $table->string('payload_hash', 64);                 // SHA256 del payload (detectar batches idénticos)

            // Resultado del procesamiento
            $table->enum('status', [
                'received',     // Llegó y se guardó, procesando
                'processed',    // Procesado completamente
                'partial',      // Procesado con advertencias
                'failed',       // Error durante el procesamiento
            ])->default('received');

            // Resumen del resultado (se llena después de procesar)
            $table->unsignedInteger('invoices_inserted')->default(0);
            $table->unsignedInteger('invoices_updated')->default(0);
            $table->unsignedInteger('invoices_unchanged')->default(0);
            $table->unsignedInteger('invoices_pending_review')->default(0);
            $table->unsignedInteger('invoices_rejected')->default(0);

            // Detalles de advertencias y errores (JSON con detalle por factura)
            $table->json('warnings')->nullable();               // Facturas con cambios detectados
            $table->json('errors')->nullable();                 // Facturas rechazadas con motivo
            $table->text('failure_message')->nullable();        // Mensaje de error global si status = failed

            $table->timestamps();

            // Índices para consultas frecuentes
            $table->index('status');
            $table->index('created_at');
            $table->index('payload_hash');
        });

        Schema::create('api_invoice_import_conflicts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('api_invoice_import_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('invoice_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->string('invoice_number', 30);               // Nfactura — para mostrar sin join
            $table->string('manifest_number', 20);              // NumeroManifiesto — para mostrar sin join

            // Snapshot de los cambios detectados
            $table->json('previous_values');                    // Valores que ya estaban en BD
            $table->json('incoming_values');                    // Valores que llegaron de Jaremar

            // Resolución
            $table->enum('resolution', [
                'pending',      // Esperando revisión de Hosana
                'accepted',     // Hosana aceptó los nuevos datos
                'rejected',     // Hosana rechazó el cambio, se mantienen los anteriores
            ])->default('pending');

            $table->foreignId('resolved_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();       // Notas opcionales del revisor

            $table->timestamps();

            $table->index(['resolution', 'created_at']);
            $table->index('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_invoice_import_conflicts');
        Schema::dropIfExists('api_invoice_imports');
    }
};