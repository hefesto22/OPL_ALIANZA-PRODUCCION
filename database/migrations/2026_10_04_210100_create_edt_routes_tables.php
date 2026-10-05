<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rutas del EDT y su asignación a vendedores.
 *
 * edt_routes: una ruta es de una bodega, cubre uno o más municipios
 * (edt_route_municipality) y opcionalmente tiene días de visita
 * (visit_days: arreglo JSON de días ISO, 1 = lunes … 7 = domingo; vacío =
 * cualquier día).
 *
 * edt_route_assignments: qué vendedor tiene la ruta y DESDE CUÁNDO
 * (starts_on) y hasta cuándo (ends_on, NULL = sigue vigente). Es el
 * historial: no se borra ni se edita, se cierra y se abre otra.
 *   - FK compuestas (route_id, warehouse_id) y (seller_id, warehouse_id):
 *     la BD no acepta asignar una ruta a un vendedor de otra bodega.
 *   - Índice único parcial: una ruta no puede tener dos asignaciones
 *     abiertas a la vez. Que no se traslapen en fechas lo cuida
 *     EdtRouteAssignmentService con lock de la ruta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edt_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('name', 100);
            $table->jsonb('visit_days')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Nombre único dentro de la bodega; índice líder de la FK warehouse_id.
            $table->unique(['warehouse_id', 'name'], 'edt_routes_warehouse_name_unique');
            // Destino de la FK compuesta (route_id, warehouse_id) de las asignaciones.
            $table->unique(['id', 'warehouse_id'], 'edt_routes_id_warehouse_unique');
            $table->index('created_by');
            $table->index('updated_by');
        });

        DB::statement(
            'ALTER TABLE edt_routes ADD CONSTRAINT edt_routes_visit_days_is_array '.
            "CHECK (jsonb_typeof(visit_days) = 'array')"
        );

        Schema::create('edt_route_municipality', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('edt_routes')->cascadeOnDelete();
            $table->foreignId('municipality_id')->constrained('hn_municipalities')->restrictOnDelete();
            $table->timestamps();

            // Índice líder de la FK route_id y evita repetir un municipio en la ruta.
            $table->unique(['route_id', 'municipality_id'], 'edt_route_municipality_unique');
            $table->index('municipality_id');
        });

        Schema::create('edt_route_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->unsignedBigInteger('route_id');
            $table->unsignedBigInteger('seller_id');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['route_id', 'warehouse_id'], 'edt_route_assignments_route_fk')
                ->references(['id', 'warehouse_id'])->on('edt_routes')->restrictOnDelete();
            $table->foreign(['seller_id', 'warehouse_id'], 'edt_route_assignments_seller_fk')
                ->references(['id', 'warehouse_id'])->on('edt_sellers')->restrictOnDelete();

            // Historial de una ruta por fecha; índice líder de su FK.
            $table->index(['route_id', 'starts_on'], 'edt_route_assignments_route_starts_idx');
            // Rutas vigentes de un vendedor (lo que define qué clientes ve).
            $table->index(['seller_id', 'starts_on'], 'edt_route_assignments_seller_starts_idx');
            $table->index('warehouse_id');
            $table->index('created_by');
            $table->index('updated_by');
        });

        DB::statement(
            'ALTER TABLE edt_route_assignments ADD CONSTRAINT edt_route_assignments_dates_order '.
            'CHECK (ends_on IS NULL OR ends_on >= starts_on)'
        );
        DB::statement(
            'CREATE UNIQUE INDEX edt_route_assignments_one_open_per_route '.
            'ON edt_route_assignments (route_id) WHERE ends_on IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('edt_route_assignments');
        Schema::dropIfExists('edt_route_municipality');
        Schema::dropIfExists('edt_routes');
    }
};
