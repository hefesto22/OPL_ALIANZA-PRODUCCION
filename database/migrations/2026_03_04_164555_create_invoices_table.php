<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            // Datos de Jaremar
            $table->string('jaremar_id')->nullable();       // Id
            $table->string('invoice_number', 30)->unique(); // Nfactura
            $table->string('lx_number', 20)->nullable();    // NumeroFacturaLX
            $table->string('order_number', 20)->nullable(); // NumeroPedido
            $table->date('invoice_date');                   // FechaFactura
            $table->date('due_date')->nullable();            // FechaVencimiento
            $table->date('print_limit_date')->nullable();    // FechaLimImpre

            // Vendedor
            $table->string('seller_id', 20)->nullable();
            $table->string('seller_name')->nullable();

            // Cliente
            $table->string('client_id', 20)->nullable();
            $table->string('client_name');
            $table->string('client_rtn', 20)->nullable();
            $table->string('deliver_to')->nullable();        // EntregarA

            // Ubicación
            $table->string('department')->nullable();
            $table->string('municipality')->nullable();
            $table->string('neighborhood')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();

            // Ruta
            $table->string('route_number', 20)->nullable();  // NumeroRuta

            // Fiscal
            $table->string('cai', 50)->nullable();
            $table->string('range_start', 30)->nullable();   // Rinicial
            $table->string('range_end', 30)->nullable();     // Rfinal
            $table->string('payment_type', 20)->nullable();  // TipoPago: CONTADO/CREDITO
            $table->unsignedTinyInteger('credit_days')->default(0); // DiasCred
            $table->string('invoice_type', 10)->nullable();  // TipoFactura: FAC
            $table->unsignedTinyInteger('invoice_status')->default(1); // EstadoFactura

            // Direcciones Jaremar
            $table->text('matriz_address')->nullable();
            $table->text('branch_address')->nullable();

            // Importes fiscales Honduras
            $table->decimal('importe_excento', 12, 2)->default(0);
            $table->decimal('importe_exento_desc', 12, 2)->default(0);
            $table->decimal('importe_exento_isv18', 12, 2)->default(0);
            $table->decimal('importe_exento_isv15', 12, 2)->default(0);
            $table->decimal('importe_exento_total', 12, 2)->default(0);
            $table->decimal('importe_exonerado', 12, 2)->default(0);
            $table->decimal('importe_exonerado_desc', 12, 2)->default(0);
            $table->decimal('importe_exonerado_isv18', 12, 2)->default(0);
            $table->decimal('importe_exonerado_isv15', 12, 2)->default(0);
            $table->decimal('importe_exonerado_total', 12, 2)->default(0);
            $table->decimal('importe_gravado', 12, 2)->default(0);
            $table->decimal('importe_gravado_desc', 12, 2)->default(0);
            $table->decimal('importe_gravado_isv18', 12, 2)->default(0);
            $table->decimal('importe_gravado_isv15', 12, 2)->default(0);
            $table->decimal('importe_gravado_total', 12, 2)->default(0);
            $table->decimal('discounts', 12, 2)->default(0);
            $table->decimal('isv18', 12, 2)->default(0);
            $table->decimal('isv15', 12, 2)->default(0);
            $table->decimal('total', 12, 2);

            // Estado en el sistema Hosana
            $table->boolean('is_printed')->default(false);
            $table->timestamp('printed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['manifest_id', 'warehouse_id']);
            $table->index('route_number');
            $table->index('client_id');
            $table->index('seller_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
