<?php

namespace Database\Seeders;

use App\Models\ReturnReason;
use Illuminate\Database\Seeder;

class ReturnReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            // ── BE: Bodega / Entrega (16) ──────────────────────────
            ['code' => 'BE-01', 'category' => 'BE', 'description' => 'Cliente No Quiere (INV ALTO)'],
            ['code' => 'BE-02', 'category' => 'BE', 'description' => 'Prod. No Solicitado X Client'],
            ['code' => 'BE-03', 'category' => 'BE', 'description' => 'Error de Entrega (Motorista)'],
            ['code' => 'BE-04', 'category' => 'BE', 'description' => 'Entrega Fuera De Fecha'],
            ['code' => 'BE-05', 'category' => 'BE', 'description' => 'Cant. Pedido Mal Ingresada'],
            ['code' => 'BE-06', 'category' => 'BE', 'description' => 'Faltante Transportista'],
            ['code' => 'BE-07', 'category' => 'BE', 'description' => 'Mala Facturación'],
            ['code' => 'BE-08', 'category' => 'BE', 'description' => 'Diferencia En Precio/Descuento'],
            ['code' => 'BE-09', 'category' => 'BE', 'description' => 'Sin Existencia (No Cargado)'],
            ['code' => 'BE-10', 'category' => 'BE', 'description' => 'Cliente No Tiene Dinero'],
            ['code' => 'BE-11', 'category' => 'BE', 'description' => 'Negocio Cerrado'],
            ['code' => 'BE-12', 'category' => 'BE', 'description' => 'Diferencia Precio Negociado'],
            ['code' => 'BE-13', 'category' => 'BE', 'description' => 'Error de Carga (Bodega)'],
            ['code' => 'BE-14', 'category' => 'BE', 'description' => 'Zona de Alto Riesgo'],
            ['code' => 'BE-15', 'category' => 'BE', 'description' => 'Cliente Mal Georreferenciado'],
            ['code' => 'BE-16', 'category' => 'BE', 'description' => 'Extorsión por Asalto'],

            // ── PNC: Producto No Conforme (14) ─────────────────────
            ['code' => 'PNC-01', 'category' => 'PNC', 'description' => 'Caja/Paq Manchada(o)'],
            ['code' => 'PNC-02', 'category' => 'PNC', 'description' => 'Producto Vencido/a Vencer'],
            ['code' => 'PNC-03', 'category' => 'PNC', 'description' => 'Empaque Roto/Fugas Envase'],
            ['code' => 'PNC-04', 'category' => 'PNC', 'description' => 'Producto Dañado En Viaje'],
            ['code' => 'PNC-05', 'category' => 'PNC', 'description' => 'Contaminado Larva/Gorgojos'],
            ['code' => 'PNC-06', 'category' => 'PNC', 'description' => 'Código de Barra Incorrecto'],
            ['code' => 'PNC-07', 'category' => 'PNC', 'description' => 'Consistencia/Textura/Color'],
            ['code' => 'PNC-08', 'category' => 'PNC', 'description' => 'Cristalizado/Descristaliza'],
            ['code' => 'PNC-09', 'category' => 'PNC', 'description' => 'Embalaje Incorrecto'],
            ['code' => 'PNC-10', 'category' => 'PNC', 'description' => 'Error Fecha Producto/Vencim'],
            ['code' => 'PNC-11', 'category' => 'PNC', 'description' => 'Faltante en Caja/Paq/Peso'],
            ['code' => 'PNC-12', 'category' => 'PNC', 'description' => 'Producto Derretido'],
            ['code' => 'PNC-13', 'category' => 'PNC', 'description' => 'Contaminado Olor a Jabón'],
            ['code' => 'PNC-14', 'category' => 'PNC', 'description' => 'Producto Explotado'],
        ];

        foreach ($reasons as $reason) {
            ReturnReason::firstOrCreate(
                ['code' => $reason['code']],
                array_merge($reason, ['jaremar_id' => null, 'is_active' => true])
            );
        }
    }
}
