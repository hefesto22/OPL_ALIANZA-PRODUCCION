<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->string('jaremar_line_id')->nullable();   // Id de la línea
            $table->unsignedInteger('invoice_jaremar_id')->nullable(); // InvoiceId
            $table->unsignedSmallInteger('line_number');     // NumeroLinea
            $table->string('product_id', 20);                // ProductoId
            $table->string('product_description');           // ProductoDesc
            $table->string('product_type', 5)->nullable();   // TipoProducto: A
            $table->string('unit_sale', 10)->nullable();     // UniVenta: UN, CJ

            // Cantidades
            $table->decimal('quantity_fractions', 10, 4)->default(0);  // CantidadFracciones
            $table->decimal('quantity_decimal', 10, 4)->default(0);    // CantidadDecimal
            $table->decimal('quantity_box', 10, 4)->default(0);        // CantidadCaja
            $table->decimal('quantity_min_sale', 10, 4)->default(0);   // CantidadUnidadMinVenta
            $table->unsignedInteger('conversion_factor')->default(1);  // FactorConversion

            // Precios
            $table->decimal('cost', 12, 4)->default(0);
            $table->decimal('price', 12, 4)->default(0);               // Precio (precio caja)
            $table->decimal('price_min_sale', 12, 4)->default(0);      // PrecioUnidadMinVenta

            // Totales
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('discount_percent', 8, 4)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('tax_percent', 8, 4)->default(0);
            $table->decimal('tax18', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // Físicos
            $table->decimal('weight', 10, 4)->default(0);
            $table->decimal('volume', 10, 4)->default(0);

            $table->timestamps();

            $table->index(['invoice_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};