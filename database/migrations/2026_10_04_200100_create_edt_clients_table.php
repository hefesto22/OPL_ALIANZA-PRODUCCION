<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes del módulo EDT.
 *
 * Tabla propia: el EDT no tiene clientes previos y no se mezcla con los
 * clientes de las facturas de Jaremar (decisión de Mauricio, 2026-10-04).
 *
 * - warehouse_id: la bodega que le factura. Los vendedores de una bodega
 *   solo atienden (y facturan desde) esa bodega, así que los usuarios de
 *   bodega solo ven sus clientes (WarehouseScope + HandlesWarehouseScope).
 * - department_id / municipality_id: la zona, del catálogo oficial hn_*.
 *   La FK compuesta (municipality_id, department_id) impide guardar un
 *   municipio que no sea del departamento elegido.
 * - code: único. Si el usuario no lo escribe, EdtClientObserver toma el
 *   siguiente de la secuencia edt_client_code_seq (C-000001…), que es
 *   OWNED BY edt_clients.code y se borra junto con la tabla. La secuencia
 *   no se repite aunque dos personas guarden a la vez; puede dejar huecos
 *   (no es un correlativo fiscal, no importa).
 * - Crédito opcional: credit_enabled dice si el cliente compra al crédito;
 *   límite y días pueden quedar vacíos hasta que el negocio los defina. Si
 *   no tiene crédito, límite y días tienen que ir vacíos (CHECK).
 *
 * Sin softDeletes: un cliente se desactiva con is_active. Cuando existan
 * pedidos (fases siguientes) la Policy no dejará borrar uno que los tenga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edt_clients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            // Nombre o razón social: el que irá en la factura.
            $table->string('name', 150);
            $table->string('business_name', 150)->nullable();
            $table->string('business_type', 60)->nullable();
            // RTN hondureño: 14 dígitos, se guarda sin guiones.
            $table->string('rtn', 14)->nullable();
            $table->string('phone', 20)->nullable();
            $table->foreignId('department_id')->constrained('hn_departments')->restrictOnDelete();
            $table->unsignedBigInteger('municipality_id');
            $table->string('neighborhood', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->boolean('credit_enabled')->default(false);
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->unsignedSmallInteger('credit_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['municipality_id', 'department_id'], 'edt_clients_municipality_department_fk')
                ->references(['id', 'department_id'])
                ->on('hn_municipalities')
                ->restrictOnDelete();

            // Listado por bodega ordenado por nombre; también es el índice
            // de la FK warehouse_id (columna líder).
            $table->index(['warehouse_id', 'name'], 'edt_clients_warehouse_name_idx');
            // Clientes de una bodega en ciertos municipios: lo que verá cada
            // vendedor según sus zonas (fase 4).
            $table->index(['warehouse_id', 'municipality_id'], 'edt_clients_warehouse_municipality_idx');
            // Índices de las FKs (Postgres no los crea solo).
            $table->index(['municipality_id', 'department_id'], 'edt_clients_municipality_department_idx');
            $table->index('department_id');
            $table->index('created_by');
            $table->index('updated_by');
        });

        DB::statement(
            'ALTER TABLE edt_clients ADD CONSTRAINT edt_clients_rtn_format '.
            "CHECK (rtn IS NULL OR rtn ~ '^[0-9]{14}$')"
        );
        DB::statement(
            'ALTER TABLE edt_clients ADD CONSTRAINT edt_clients_credit_limit_positive '.
            'CHECK (credit_limit IS NULL OR credit_limit > 0)'
        );
        DB::statement(
            'ALTER TABLE edt_clients ADD CONSTRAINT edt_clients_credit_days_range '.
            'CHECK (credit_days IS NULL OR credit_days BETWEEN 1 AND 365)'
        );
        DB::statement(
            'ALTER TABLE edt_clients ADD CONSTRAINT edt_clients_credit_terms_need_credit '.
            'CHECK (credit_enabled OR (credit_limit IS NULL AND credit_days IS NULL))'
        );

        // OWNED BY: la secuencia es "de" la tabla. Si la tabla se borra (DROP
        // TABLE, migrate:fresh, el RefreshDatabase de los tests) la secuencia
        // se va con ella; si no, quedaría huérfana y el siguiente CREATE
        // fallaría con "already exists". IF NOT EXISTS cubre una que ya haya
        // quedado huérfana de antes: se reutiliza y sigue contando.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS edt_client_code_seq START WITH 1 INCREMENT BY 1');
        DB::statement('ALTER SEQUENCE edt_client_code_seq OWNED BY edt_clients.code');
    }

    public function down(): void
    {
        // La secuencia es OWNED BY la tabla: se borra con ella. El DROP
        // explícito es por si quedó suelta.
        Schema::dropIfExists('edt_clients');
        DB::statement('DROP SEQUENCE IF EXISTS edt_client_code_seq');
    }
};
