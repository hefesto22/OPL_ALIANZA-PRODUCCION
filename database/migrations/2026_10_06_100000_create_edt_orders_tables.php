<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preventas del EDT (pedidos que toman los vendedores en ruta).
 *
 * Flujo: BORRADOR (el vendedor lo arma) → ENVIADO (preventa) → EN REVISIÓN
 * (la bodega bajó alguna cantidad) → APROBADO (pasa a facturación) o
 * RECHAZADO. El vendedor puede ANULAR mientras la bodega no la toque.
 *
 * - Número PED-000001 al enviar (secuencia: sin choques entre vendedores).
 *   Es interno; el número fiscal sale con el CAI al facturar.
 * - Vendedor y cliente tienen que ser de la bodega del pedido: FKs
 *   compuestas (seller_id, warehouse_id) y (client_id, warehouse_id).
 * - Un solo borrador por vendedor y cliente (índice único parcial): un
 *   doble toque en «Nuevo pedido» no crea dos.
 * - Cada línea guarda copia del producto y su precio del día (lista, costo,
 *   ISV, unidades por caja): la factura y los reportes de margen respetan
 *   el precio de la preventa aunque el catálogo cambie después.
 * - requested_* es lo que pidió el vendedor; boxes/units lo que queda
 *   después de la revisión de la bodega (puede bajar hasta 0, nunca subir).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Para la FK compuesta (client_id, warehouse_id) de los pedidos.
        Schema::table('edt_clients', function (Blueprint $table) {
            $table->unique(['id', 'warehouse_id'], 'edt_clients_id_warehouse_unique');
        });

        Schema::create('edt_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('client_id');
            $table->foreignId('route_id')->nullable()->constrained('edt_routes')->nullOnDelete();
            $table->string('status', 20)->default('borrador');
            $table->string('payment_type', 10)->default('contado');
            $table->date('order_date');
            $table->string('note', 255)->nullable();

            // Totales (con ISV incluido en total). Los recalcula EdtOrderService.
            $table->decimal('exempt_total', 14, 2)->default(0);
            $table->decimal('taxable_15', 14, 2)->default(0);
            $table->decimal('isv_15', 14, 2)->default(0);
            $table->decimal('taxable_18', 14, 2)->default(0);
            $table->decimal('isv_18', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('requested_total', 14, 2)->default(0);
            $table->decimal('cost_total', 14, 2)->default(0);
            $table->unsignedSmallInteger('lines_count')->default(0);

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['seller_id', 'warehouse_id'], 'edt_orders_seller_fk')
                ->references(['id', 'warehouse_id'])->on('edt_sellers')->restrictOnDelete();
            $table->foreign(['client_id', 'warehouse_id'], 'edt_orders_client_fk')
                ->references(['id', 'warehouse_id'])->on('edt_clients')->restrictOnDelete();

            // Bandeja de la bodega: sus preventas por estado y fecha.
            $table->index(['warehouse_id', 'status', 'order_date'], 'edt_orders_warehouse_status_date_idx');
            // «Mis pedidos» del vendedor.
            $table->index(['seller_id', 'order_date'], 'edt_orders_seller_date_idx');
            $table->index(['client_id', 'order_date'], 'edt_orders_client_date_idx');
            $table->index('route_id');
            $table->index('reviewed_by');
            $table->index('created_by');
            $table->index('updated_by');
        });

        DB::statement(
            'ALTER TABLE edt_orders ADD CONSTRAINT edt_orders_status_valid '.
            "CHECK (status IN ('borrador','enviado','en_revision','aprobado','rechazado','anulado'))"
        );
        DB::statement(
            'ALTER TABLE edt_orders ADD CONSTRAINT edt_orders_payment_type_valid '.
            "CHECK (payment_type IN ('contado','credito'))"
        );
        DB::statement(
            'ALTER TABLE edt_orders ADD CONSTRAINT edt_orders_number_when_sent '.
            "CHECK (status = 'borrador' OR number IS NOT NULL)"
        );
        DB::statement(
            'ALTER TABLE edt_orders ADD CONSTRAINT edt_orders_totals_non_negative '.
            'CHECK (exempt_total >= 0 AND taxable_15 >= 0 AND isv_15 >= 0 AND taxable_18 >= 0 '.
            'AND isv_18 >= 0 AND total >= 0 AND requested_total >= 0 AND cost_total >= 0)'
        );
        DB::statement(
            'CREATE UNIQUE INDEX edt_orders_one_draft_per_client ON edt_orders (seller_id, client_id) '.
            "WHERE status = 'borrador'"
        );

        DB::statement('CREATE SEQUENCE IF NOT EXISTS edt_order_number_seq START WITH 1 INCREMENT BY 1');
        DB::statement('ALTER SEQUENCE edt_order_number_seq OWNED BY edt_orders.number');

        Schema::create('edt_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('edt_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('edt_products')->restrictOnDelete();

            // Copia del producto y su precio del día de la preventa.
            $table->string('product_code', 30);
            $table->string('description', 200);
            $table->unsignedInteger('units_per_box');
            $table->decimal('isv_pct', 5, 2);
            $table->decimal('list_price', 14, 4);
            $table->decimal('box_cost', 14, 4);

            $table->unsignedInteger('requested_boxes')->default(0);
            $table->unsignedInteger('requested_units')->default(0);
            $table->unsignedInteger('boxes')->default(0);
            $table->unsignedInteger('units')->default(0);

            // Precio aplicado (con ISV) a lo que queda: la escala depende de las cajas.
            $table->string('tier_name', 60)->nullable();
            $table->decimal('tier_discount_pct', 5, 2)->nullable();
            $table->decimal('box_price', 14, 2);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('isv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('requested_total', 14, 2)->default(0);
            $table->decimal('cost_total', 14, 2)->default(0);
            $table->timestamps();

            // Un producto una sola vez por pedido: la escala cuenta por código.
            $table->unique(['order_id', 'product_id'], 'edt_order_lines_order_product_unique');
            $table->index('product_id');
        });

        DB::statement(
            'ALTER TABLE edt_order_lines ADD CONSTRAINT edt_order_lines_requested_positive '.
            'CHECK (requested_boxes + requested_units > 0)'
        );
        DB::statement(
            'ALTER TABLE edt_order_lines ADD CONSTRAINT edt_order_lines_not_above_requested '.
            'CHECK (boxes * units_per_box + units <= requested_boxes * units_per_box + requested_units)'
        );
        DB::statement(
            'ALTER TABLE edt_order_lines ADD CONSTRAINT edt_order_lines_loose_units_below_box '.
            'CHECK (units < units_per_box AND requested_units < units_per_box)'
        );
        DB::statement(
            'ALTER TABLE edt_order_lines ADD CONSTRAINT edt_order_lines_amounts_non_negative '.
            'CHECK (units_per_box > 0 AND list_price > 0 AND box_cost > 0 AND box_price >= 0 AND unit_price >= 0 '.
            'AND subtotal >= 0 AND isv >= 0 AND total >= 0 AND requested_total >= 0 AND cost_total >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('edt_order_lines');
        Schema::dropIfExists('edt_orders');
        DB::statement('DROP SEQUENCE IF EXISTS edt_order_number_seq');

        Schema::table('edt_clients', function (Blueprint $table) {
            $table->dropUnique('edt_clients_id_warehouse_unique');
        });
    }
};
