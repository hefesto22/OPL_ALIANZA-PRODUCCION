<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnReason;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DepositService;
use App\Services\ReturnService;
use App\Support\BusinessDays;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Histórico completo de AGOSTO 2026 para las bodegas de alianza
 * JO05 / JO06 / JO07 / JO10 — datos de prueba para trabajar sobre ellas las
 * mejoras futuras.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  SOLO PARA pruebas.hozana.cloud (y desarrollo local)
 * ─────────────────────────────────────────────────────────────────────
 *  El guard NO se basa en APP_ENV: pruebas.hozana.cloud corre con
 *  APP_ENV=production igual que producción (mismo hallazgo que motivó el
 *  guard de DepositCasesSeeder). Tampoco sirve el nombre de la base: la
 *  base local de desarrollo también se llama `distribuidora_hozana`.
 *
 *  Por eso el guard es una LISTA BLANCA de hosts (APP_URL): pruebas y los
 *  de desarrollo. Cualquier otro host aborta sin escribir nada, y no hay
 *  bypass. Producción, se llame como se llame, nunca está en la lista, así
 *  que el modo de falla es "no hace nada y lo dice".
 *
 * ─────────────────────────────────────────────────────────────────────
 *  QUÉ SIEMBRA
 * ─────────────────────────────────────────────────────────────────────
 *  16 manifiestos (serie 9601xx–9604xx): 1 por bodega por semana, con la
 *  llegada escalonada de lunes a jueves para que las 4 bodegas no caigan el
 *  mismo día.
 *
 *    Semana 1 → 03–06 ago    Semana 3 → 17–20 ago
 *    Semana 2 → 10–13 ago    Semana 4 → 24–27 ago
 *
 *  Cada manifiesto: 6 facturas (3 líneas c/u, mezcla CJ/UN) y 3–4
 *  devoluciones repartidas en los días siguientes. Las devoluciones cubren
 *  del 4 al 31 de agosto y salen por ReturnService — el mismo servicio del
 *  flujo real — así que quedan auto-aprobadas, con total calculado en
 *  servidor, returned_quantity de líneas, status de factura y totales del
 *  manifiesto recalculados. Son datos consistentes, no filas inventadas.
 *
 *  Estado financiero mezclado a propósito (ver PLAN):
 *    12 cerrados con depósito exacto · 1 cerrado con SOBREPAGO justificado
 *     2 abiertos con saldo pendiente (depósito parcial)
 *     1 abierto y cuadrado — listo para practicar el cierre
 *
 * ─────────────────────────────────────────────────────────────────────
 *  LA VENTANA DE DEVOLUCIONES
 * ─────────────────────────────────────────────────────────────────────
 *  Un manifiesto de agosto ya tiene vencida la ventana de 7 días hábiles, y
 *  ReturnService rechaza (con razón) toda devolución fuera de ventana. El
 *  seeder crea el manifiesto con `returns_deadline_at` a futuro para poder
 *  sembrar y, al terminar, la RESTAURA a la fecha histórica real
 *  (BusinessDays::deadline sobre la fecha del manifiesto).
 *
 *  CONSECUENCIA: los 16 manifiestos quedan con la ventana CERRADA, igual
 *  que en producción. No se van a poder agregar devoluciones a mano desde
 *  el panel sobre ellos — si hace falta uno abierto para probar, correr el
 *  seeder y después mover el deadline de ese manifiesto puntual.
 *
 *  Además se retrofechan los timestamps (manifiestos, facturas, líneas,
 *  devoluciones, depósitos y sus entradas de activity_log) para que el
 *  histórico no aparezca creado hoy. Sin eso, `isEditableToday()` daría
 *  true en las 56 devoluciones de agosto.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  USO — NO va en DatabaseSeeder, se corre a demanda
 * ─────────────────────────────────────────────────────────────────────
 *    php artisan db:seed --class=AgostoAlianzasSeeder --force
 *
 *  Es IDEMPOTENTE: borra todo lo suyo (serie 9601xx–9604xx) antes de
 *  recrear, así que se puede repetir las veces que haga falta.
 */
class AgostoAlianzasSeeder extends Seeder
{
    /**
     * Hosts donde este seeder PUEDE correr. Lista blanca a propósito: lo que
     * no está acá, no corre. Si pruebas cambia de dominio se agrega el host
     * nuevo — nunca se quita el candado.
     *
     * @var array<int, string>
     */
    private const HOSTS_PERMITIDOS = [
        'pruebas.hozana.cloud',
        'distribuidora-hozana.test',
        'localhost',
        '127.0.0.1',
    ];

    /** Bodegas de alianza que cubre este histórico. */
    private const BODEGAS = ['JO05', 'JO06', 'JO07', 'JO10'];

    /**
     * Serie reservada. NO toca 900001–900005 (DemoDataSeeder), 900904
     * (fixture JO05 de la API) ni 950001–950011 (DepositCasesSeeder).
     * Lectura del número: 96 · semana · bodega.
     *
     * @var array<int, array{number: string, wh: string, date: string, pago: string, cerrar: bool}>
     */
    private const PLAN = [
        // ── Semana 1 (03–06 ago) — mes arrancado y cobrado ──────────────
        ['number' => '960101', 'wh' => 'JO05', 'date' => '2026-08-03', 'pago' => 'exacto', 'cerrar' => true],
        ['number' => '960102', 'wh' => 'JO06', 'date' => '2026-08-04', 'pago' => 'exacto', 'cerrar' => true],
        ['number' => '960103', 'wh' => 'JO07', 'date' => '2026-08-05', 'pago' => 'exacto', 'cerrar' => true],
        ['number' => '960104', 'wh' => 'JO10', 'date' => '2026-08-06', 'pago' => 'exacto', 'cerrar' => true],

        // ── Semana 2 (10–13 ago) ────────────────────────────────────────
        ['number' => '960201', 'wh' => 'JO05', 'date' => '2026-08-10', 'pago' => 'exacto', 'cerrar' => true],
        ['number' => '960202', 'wh' => 'JO06', 'date' => '2026-08-11', 'pago' => 'exacto', 'cerrar' => true],
        ['number' => '960203', 'wh' => 'JO07', 'date' => '2026-08-12', 'pago' => 'exacto', 'cerrar' => true],
        ['number' => '960204', 'wh' => 'JO10', 'date' => '2026-08-13', 'pago' => 'exacto', 'cerrar' => true],

        // ── Semana 3 (17–20 ago) — aparece el primer saldo pendiente ────
        ['number' => '960301', 'wh' => 'JO05', 'date' => '2026-08-17', 'pago' => 'exacto', 'cerrar' => true],
        ['number' => '960302', 'wh' => 'JO06', 'date' => '2026-08-18', 'pago' => 'exacto', 'cerrar' => true],
        ['number' => '960303', 'wh' => 'JO07', 'date' => '2026-08-19', 'pago' => 'parcial', 'cerrar' => false],
        ['number' => '960304', 'wh' => 'JO10', 'date' => '2026-08-20', 'pago' => 'exacto', 'cerrar' => true],

        // ── Semana 4 (24–27 ago) — cierre de mes con casos de excepción ─
        ['number' => '960401', 'wh' => 'JO05', 'date' => '2026-08-24', 'pago' => 'sobrepago', 'cerrar' => true],
        ['number' => '960402', 'wh' => 'JO06', 'date' => '2026-08-25', 'pago' => 'parcial', 'cerrar' => false],
        ['number' => '960403', 'wh' => 'JO07', 'date' => '2026-08-26', 'pago' => 'exacto', 'cerrar' => true],
        ['number' => '960404', 'wh' => 'JO10', 'date' => '2026-08-27', 'pago' => 'exacto', 'cerrar' => false],
    ];

    /** Facturas por manifiesto. */
    private const FACTURAS_POR_MANIFIESTO = 6;

    /** Sobrepago del caso 960401, en lempiras sobre el saldo pendiente. */
    private const SOBREPAGO = 250.00;

    /** Fracción del saldo que cubre un depósito parcial. */
    private const FRACCION_PARCIAL = 0.60;

    /** Último día que el histórico puede tocar: nada se sale de agosto. */
    private const ULTIMO_DIA = '2026-08-31';

    /**
     * Catálogo de productos (mismo set real que DemoDataSeeder).
     * 'cj' = venta por caja; 'factor' = unidades por caja; 'iva' grava 15%.
     *
     * @var array<int, array{id: string, desc: string, cj: bool, factor: int, unit: float, iva: bool}>
     */
    private const CATALOG = [
        // ── Productos CJ (caja) — índices 0..3 ────────────────────────────
        ['id' => '50470402', 'desc' => 'CENTELLABARRA ROSADO 400GX3X4', 'cj' => true,  'factor' => 12, 'unit' => 15.00, 'iva' => true],
        ['id' => '50470403', 'desc' => 'CENTELLABARRA AMARILL 400GX3X4', 'cj' => true,  'factor' => 12, 'unit' => 15.00, 'iva' => true],
        ['id' => '52480087', 'desc' => '3PACK LIMPIOX LIMON 245g X3X8',  'cj' => true,  'factor' => 24, 'unit' => 9.03,  'iva' => true],
        ['id' => '52480088', 'desc' => 'LIMPIOX MEGA DISCO LIMON 425g',  'cj' => true,  'factor' => 20, 'unit' => 14.12, 'iva' => true],
        // ── Productos UN (unidad) — índices 4..11 ─────────────────────────
        ['id' => '30110205', 'desc' => 'ORISOL LIGHT OLIVA 410mL 1/24',  'cj' => false, 'factor' => 24, 'unit' => 31.20, 'iva' => true],
        ['id' => '81800012', 'desc' => 'KETCHUP 8X12X87GR',              'cj' => false, 'factor' => 96, 'unit' => 6.69,  'iva' => true],
        ['id' => '52480038', 'desc' => 'LIMPIOX DISKETT LIMON 115GRX72', 'cj' => false, 'factor' => 72, 'unit' => 4.17,  'iva' => true],
        ['id' => '86800002', 'desc' => 'FRIJOLES 24X200GR',              'cj' => false, 'factor' => 24, 'unit' => 14.36, 'iva' => true],
        ['id' => '86800016', 'desc' => 'FRIJOLES 24X360G',               'cj' => false, 'factor' => 24, 'unit' => 22.09, 'iva' => true],
        ['id' => '01020021', 'desc' => 'MANTECA DOMESTICA DORAL 50X409', 'cj' => false, 'factor' => 50, 'unit' => 20.00, 'iva' => false],
        ['id' => '80800013', 'desc' => 'PASTA NORMAL 8X12X87GR',         'cj' => false, 'factor' => 96, 'unit' => 7.93,  'iva' => true],
        ['id' => '50491315', 'desc' => 'MAX SBA DUOX AZUL BCS 400GX4X5', 'cj' => false, 'factor' => 20, 'unit' => 20.54, 'iva' => true],
    ];

    /**
     * Zona de cada bodega: clientes, vendedor y ruta. Los clientes son fijos
     * por bodega (misma ruta cada semana), así que clients_count da 6 por
     * manifiesto y el histórico de cada cliente tiene 4 visitas en el mes.
     *
     * @var array<string, array{depto: string, ruta: string, vendedor: array{id: string, name: string}, clientes: array<int, array{id: string, name: string, rtn: string, mun: string}>}>
     */
    private const ZONAS = [
        'JO05' => [
            'depto' => 'SANTA BARBARA',
            'ruta' => '515',
            'vendedor' => ['id' => '13051', 'name' => 'OSCAR ARMANDO PORTILLO'],
            'clientes' => [
                ['id' => '99071001', 'name' => 'PULPERIA LA ESPERANZA SB', 'rtn' => '15011984003310', 'mun' => 'Santa Barbara'],
                ['id' => '99071002', 'name' => 'ABARROTERIA EL PROGRESO',  'rtn' => '15031990007742', 'mun' => 'Quimistan'],
                ['id' => '99071003', 'name' => 'VENTA DONA CARMEN',        'rtn' => '15051978001196', 'mun' => 'Trinidad'],
                ['id' => '99071004', 'name' => 'MINISUPER EL ROBLE',       'rtn' => '15071992004503', 'mun' => 'Ilama'],
                ['id' => '99071005', 'name' => 'PULPERIA SANTA FE',        'rtn' => '15021986002267', 'mun' => 'Macuelizo'],
                ['id' => '99071006', 'name' => 'DISTRIBUIDORA LEMPIRA SB', 'rtn' => '15091981009038', 'mun' => 'San Nicolas'],
            ],
        ],
        'JO06' => [
            'depto' => 'COPAN',
            'ruta' => '406',
            'vendedor' => ['id' => '13062', 'name' => 'JOSE ANTONIO MEJIA'],
            'clientes' => [
                ['id' => '99072001', 'name' => 'PULPERIA LA BENDICION CP', 'rtn' => '04011990014521', 'mun' => 'Santa Rosa de Copan'],
                ['id' => '99072002', 'name' => 'ABARROTERIA SAN JOSE',     'rtn' => '04081988009910', 'mun' => 'La Entrada'],
                ['id' => '99072003', 'name' => 'VENTA EL BUEN PRECIO',     'rtn' => '04031985006634', 'mun' => 'Cucuyagua'],
                ['id' => '99072004', 'name' => 'MINISUPER COPAN RUINAS',   'rtn' => '04041993001807', 'mun' => 'Copan Ruinas'],
                ['id' => '99072005', 'name' => 'PULPERIA EL CENTRO',       'rtn' => '04061987003429', 'mun' => 'Dulce Nombre'],
                ['id' => '99072006', 'name' => 'DISTRIBUIDORA OCCIDENTE',  'rtn' => '04021982008115', 'mun' => 'Corquin'],
            ],
        ],
        'JO07' => [
            'depto' => 'OCOTEPEQUE',
            'ruta' => '312',
            'vendedor' => ['id' => '13073', 'name' => 'MARIO ALBERTO GUEVARA'],
            'clientes' => [
                ['id' => '99073001', 'name' => 'ABARROTERIA EL AHORRO OC', 'rtn' => '14021985007733', 'mun' => 'Ocotepeque'],
                ['id' => '99073002', 'name' => 'PULPERIA EL ENCUENTRO',    'rtn' => '14051987001172', 'mun' => 'San Marcos'],
                ['id' => '99073003', 'name' => 'VENTA LA FRONTERA',        'rtn' => '14011991005580', 'mun' => 'Sinuapa'],
                ['id' => '99073004', 'name' => 'MINISUPER LA PAZ OC',      'rtn' => '14031989002914', 'mun' => 'La Labor'],
                ['id' => '99073005', 'name' => 'PULPERIA SAN FRANCISCO',   'rtn' => '14071983006201', 'mun' => 'Belen Gualcho'],
                ['id' => '99073006', 'name' => 'DISTRIBUIDORA MERENDON',   'rtn' => '14041994000738', 'mun' => 'Santa Fe'],
            ],
        ],
        'JO10' => [
            'depto' => 'INTIBUCA',
            'ruta' => '228',
            'vendedor' => ['id' => '13104', 'name' => 'WILMER DANILO SANCHEZ'],
            'clientes' => [
                ['id' => '99074001', 'name' => 'PULPERIA LA ESPERANZA IN', 'rtn' => '10011986004427', 'mun' => 'La Esperanza'],
                ['id' => '99074002', 'name' => 'ABARROTERIA INTIBUCA',     'rtn' => '10021990001053', 'mun' => 'Intibuca'],
                ['id' => '99074003', 'name' => 'VENTA DONA ROSA',          'rtn' => '10051984008866', 'mun' => 'Jesus de Otoro'],
                ['id' => '99074004', 'name' => 'MINISUPER YAMARANGUILA',   'rtn' => '10031992003390', 'mun' => 'Yamaranguila'],
                ['id' => '99074005', 'name' => 'PULPERIA EL PINAR',        'rtn' => '10061981007112', 'mun' => 'San Miguelito'],
                ['id' => '99074006', 'name' => 'DISTRIBUIDORA LENCA',      'rtn' => '10041995002645', 'mun' => 'Camasca'],
            ],
        ],
    ];

    /**
     * Motivos que rota el seeder para que los gráficos por motivo tengan
     * dispersión en vez de tres barras. Todos existen en ReturnReasonSeeder.
     *
     * @var array<int, string>
     */
    private const MOTIVOS = [
        'BE-01', 'BE-03', 'BE-05', 'BE-09', 'BE-13',
        'PNC-01', 'PNC-02', 'PNC-03', 'PNC-04', 'PNC-05', 'PNC-11',
    ];

    /** Contador global de devoluciones — es lo que rota los motivos. */
    private int $motivoCursor = 0;

    public function run(): void
    {
        if (! $this->entornoPermitido()) {
            return;
        }

        [$supplier, $warehouses, $reasons, $user] = $this->dependencias();

        if ($supplier === null || $warehouses === null || $reasons === null || $user === null) {
            return;
        }

        $this->purgar();

        // Los servicios registran actividad con auth()->user(); sin sesión las
        // entradas de activity_log quedarían sin causer.
        Auth::login($user);

        $returnService = app(ReturnService::class);
        $depositService = app(DepositService::class);

        $devoluciones = 0;

        foreach (self::PLAN as $index => $paso) {
            $devoluciones += $this->sembrarManifiesto(
                $paso,
                $index,
                $warehouses[$paso['wh']],
                (int) $supplier->id,
                $reasons,
                $user,
                $returnService,
                $depositService,
            );
        }

        Auth::logout();

        $facturas = count(self::PLAN) * self::FACTURAS_POR_MANIFIESTO;

        $this->command?->newLine();
        $this->command?->info(
            'Agosto 2026 sembrado: '.count(self::PLAN)." manifiestos, {$facturas} facturas y ".
            "{$devoluciones} devoluciones en ".implode('/', self::BODEGAS).'.'
        );

        $this->resumen();
    }

    /**
     * Lista blanca de hosts. Ver el bloque de arriba: en este proyecto ni
     * APP_ENV ni el nombre de la base distinguen pruebas de producción.
     */
    private function entornoPermitido(): bool
    {
        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: '');
        $base = DB::connection()->getDatabaseName();

        if (! in_array($host, self::HOSTS_PERMITIDOS, true)) {
            $this->command?->error(
                "AgostoAlianzasSeeder ABORTADO — host no permitido: '{$host}' (base: {$base}). ".
                'Solo corre en: '.implode(', ', self::HOSTS_PERMITIDOS).'. No se escribió nada.'
            );

            return false;
        }

        $this->command?->info("Sembrando en host '{$host}', base '{$base}'.");

        return true;
    }

    /**
     * Resuelve proveedor, bodegas, motivos y usuario. Devuelve nulls y avisa
     * si falta algo: mejor abortar que sembrar un histórico incompleto.
     *
     * @return array{0: ?Supplier, 1: ?Collection<string, Warehouse>, 2: ?Collection<string, int>, 3: ?User}
     */
    private function dependencias(): array
    {
        $vacio = [null, null, null, null];

        $supplier = Supplier::where('is_active', true)->first() ?? Supplier::first();

        if (! $supplier) {
            $this->command?->error('No hay proveedor. Corré SupplierSeeder primero.');

            return $vacio;
        }

        $warehouses = Warehouse::whereIn('code', self::BODEGAS)->get()->keyBy('code');

        if ($warehouses->count() < count(self::BODEGAS)) {
            $faltan = array_diff(self::BODEGAS, $warehouses->keys()->all());
            $this->command?->error('Faltan bodegas: '.implode(', ', $faltan).'. Creálas antes de sembrar.');

            return $vacio;
        }

        $reasons = ReturnReason::whereIn('code', self::MOTIVOS)->pluck('id', 'code');

        if ($reasons->count() < count(self::MOTIVOS)) {
            $this->command?->error('Faltan motivos de devolución. Corré ReturnReasonSeeder primero.');

            return $vacio;
        }

        $user = User::query()->orderBy('id')->first();

        if (! $user) {
            $this->command?->error('No hay usuarios. Corré RolePermissionSeeder + AdminUserSeeder primero.');

            return $vacio;
        }

        return [$supplier, $warehouses, $reasons, $user];
    }

    /**
     * Siembra un manifiesto completo y devuelve cuántas devoluciones creó.
     *
     * El orden importa: facturas → totales → devoluciones → depósito →
     * cierre → restaurar ventana y retrofechar. El depósito se calcula
     * DESPUÉS de las devoluciones porque el saldo a depositar es
     * total_invoices − total_returns.
     *
     * @param  array{number: string, wh: string, date: string, pago: string, cerrar: bool}  $paso
     * @param  Collection<string, int>  $reasons
     */
    private function sembrarManifiesto(
        array $paso,
        int $index,
        Warehouse $warehouse,
        int $supplierId,
        Collection $reasons,
        User $user,
        ReturnService $returnService,
        DepositService $depositService,
    ): int {
        $fecha = Carbon::parse($paso['date']);

        $manifest = Manifest::create([
            'supplier_id' => $supplierId,
            // NULL como los manifiestos que entran por la API de Jaremar: la
            // bodega vive en las facturas, no en el encabezado.
            'warehouse_id' => null,
            'number' => $paso['number'],
            'date' => $fecha->toDateString(),
            'status' => 'imported',
            'created_by' => $user->id,
            // Ventana abierta a propósito mientras se siembra; al final se
            // restaura a la fecha histórica real. Ver el bloque de arriba.
            'returns_deadline_at' => Carbon::now()->addYear(),
        ]);

        $invoices = [];

        for ($i = 0; $i < self::FACTURAS_POR_MANIFIESTO; $i++) {
            $invoices[] = $this->crearFactura(
                $manifest,
                $warehouse,
                $paso['wh'],
                $index * self::FACTURAS_POR_MANIFIESTO + $i,
                $i,
                $fecha,
            );
        }

        $manifest->recalculateTotals();

        $creadas = $this->crearDevoluciones($returnService, $invoices, $index, $fecha, $reasons, (int) $user->id);

        $manifest->refresh();

        $fechaDeposito = $this->cerrarAgosto($this->saltarDomingo($fecha->copy()->addDays(6)));

        $this->crearDeposito($depositService, $manifest, $paso, $warehouse, $fechaDeposito, (int) $user->id);

        $manifest->refresh();

        if ($paso['cerrar']) {
            $manifest->update([
                'status' => 'closed',
                'closed_at' => $fechaDeposito->copy()->setTime(16, 20),
                'closed_by' => $user->id,
            ]);
        }

        $this->restaurarVentana($manifest);
        $this->retrofechar($manifest, $fecha, $fechaDeposito);

        $this->command?->line(sprintf(
            '  #%s  %s  %s  %d facturas · %d devoluciones · %s',
            $paso['number'],
            $paso['wh'],
            $fecha->format('d/m/Y'),
            self::FACTURAS_POR_MANIFIESTO,
            $creadas,
            $paso['cerrar'] ? 'cerrado' : 'abierto ('.$paso['pago'].')',
        ));

        return $creadas;
    }

    /**
     * Crea una factura con 3 líneas: una CJ, una UN y una rotativa.
     *
     * Los PISOS de cantidad no son decorativos — sostienen las devoluciones:
     * la línea CJ lleva ≥2 cajas (la mixta pide 1 caja + 4 sueltas) y la UN
     * lleva ≥12 unidades (la de sueltas pide 6). Bajarlos rompe el seeder con
     * un "supera la cantidad disponible" de ReturnService.
     *
     * Producto y cantidad se mueven con $seqGlobal a propósito. Con valores
     * fijos, el ciclo del catálogo (mcm de 4, 8 y 12 = 24 facturas) hacía que
     * las 4 semanas de cada bodega salieran con EXACTAMENTE el mismo total, y
     * cualquier gráfico de tendencia mensual quedaba plano.
     */
    private function crearFactura(
        Manifest $manifest,
        Warehouse $warehouse,
        string $codigoBodega,
        int $seqGlobal,
        int $seqLocal,
        Carbon $fecha,
    ): Invoice {
        $zona = self::ZONAS[$codigoBodega];
        $cliente = $zona['clientes'][$seqLocal];
        $vendedor = $zona['vendedor'];

        $productos = count(self::CATALOG);
        $idxCj = $seqGlobal % 4;                    // 0..3  = venta por caja
        $idxUn = 4 + (($seqGlobal * 3) % 8);        // 4..11 = venta por unidad

        // La tercera línea rota por todo el catálogo, saltando las dos que ya
        // están en la factura: dos líneas del mismo producto pasarían el
        // unique (invoice_id, line_number) pero son un dato que no existe.
        $idxExtra = (($seqGlobal * 5) + 2) % $productos;

        while ($idxExtra === $idxCj || $idxExtra === $idxUn) {
            $idxExtra = ($idxExtra + 1) % $productos;
        }

        $cj = self::CATALOG[$idxCj];
        $un = self::CATALOG[$idxUn];
        $extra = self::CATALOG[$idxExtra];

        $lineas = [
            ['prod' => $cj,    'qty' => 2 + ($seqGlobal % 3)],                   // 2–4 cajas
            ['prod' => $un,    'qty' => 12 + (($seqGlobal * 7) % 25)],           // 12–36 unidades
            ['prod' => $extra, 'qty' => $extra['cj']
                ? 1 + ($seqGlobal % 2)
                : 6 + ($seqGlobal % 9)],
        ];

        $jaremarId = 96_000_001 + $seqGlobal;

        $invoice = Invoice::create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'imported',
            // Rango 0496xxxx — no choca con DemoDataSeeder (0490xxxx) ni con
            // el fixture de la API (0900xxxx).
            'invoice_number' => '002-001-01-0496'.str_pad((string) ($seqGlobal + 1), 4, '0', STR_PAD_LEFT),
            'jaremar_id' => (string) $jaremarId,
            'invoice_date' => $fecha->toDateString(),
            'due_date' => $fecha->toDateString(),
            'seller_id' => $vendedor['id'],
            'seller_name' => $vendedor['name'],
            'client_id' => $cliente['id'],
            'client_name' => $cliente['name'],
            'client_rtn' => $cliente['rtn'],
            'deliver_to' => $cliente['name'],
            'department' => $zona['depto'],
            'municipality' => $cliente['mun'],
            'address' => "Barrio Centro, {$cliente['mun']}, {$zona['depto']}",
            'route_number' => $zona['ruta'],
            'payment_type' => 'CONTADO',
            'credit_days' => 0,
            'invoice_type' => 'FAC',
            'cai' => '2F0037-619ACD-2A66E0-63BE03-0909DC-56',
            'range_start' => '002-001-01-04000001',
            'range_end' => '002-001-01-04999999',
            'total' => 0,
            'isv15' => 0,
            'isv18' => 0,
        ]);

        $total = 0.0;
        $isv = 0.0;
        $gravado = 0.0;

        foreach ($lineas as $idx => $spec) {
            $p = $spec['prod'];
            $factor = (int) $p['factor'];
            $fracciones = $p['cj'] ? $spec['qty'] * $factor : $spec['qty'];
            $cajas = $p['cj'] ? $spec['qty'] : 0;
            $unitario = (float) $p['unit'];

            $subtotal = round($fracciones * $unitario, 2);
            $impuesto = $p['iva'] ? round($subtotal * 0.15, 2) : 0.0;
            $lineaTotal = round($subtotal + $impuesto, 2);

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'jaremar_line_id' => (string) ($jaremarId * 10 + $idx),
                'invoice_jaremar_id' => (string) $jaremarId,
                'line_number' => $idx + 1,
                'product_id' => $p['id'],
                'product_description' => $p['desc'],
                'product_type' => 'A',
                'unit_sale' => $p['cj'] ? 'CJ' : 'UN',
                'quantity_fractions' => $fracciones,
                'quantity_decimal' => round($fracciones / $factor, 4),
                'quantity_box' => $cajas,
                'quantity_min_sale' => $fracciones,
                'conversion_factor' => $factor,
                'cost' => 0,
                'price' => round($unitario * $factor, 4),
                'price_min_sale' => $unitario,
                'subtotal' => $subtotal,
                'discount' => 0,
                'discount_percent' => 0,
                'tax' => $impuesto,
                'tax_percent' => $p['iva'] ? 15.0 : 0.0,
                'tax18' => 0,
                'total' => $lineaTotal,
                'returned_quantity' => 0,
                'weight' => 0,
                'volume' => 0,
            ]);

            $total += $lineaTotal;
            $isv += $impuesto;
            $gravado += $p['iva'] ? $lineaTotal : 0.0;
        }

        $invoice->update([
            'total' => round($total, 2),
            'isv15' => round($isv, 2),
            'importe_gravado' => round($gravado / 1.15, 2),
            'importe_gravado_isv15' => round($isv, 2),
            'importe_gravado_total' => round($gravado, 2),
        ]);

        return $invoice->fresh('lines');
    }

    /**
     * Devoluciones del manifiesto: siempre caja + sueltas + mixta y, en la
     * mitad de los manifiestos, una TOTAL (factura entera) para que el set
     * tenga tanto 'Parcial' como 'Total'.
     *
     * @param  array<int, Invoice>  $invoices
     * @param  Collection<string, int>  $reasons
     */
    private function crearDevoluciones(
        ReturnService $service,
        array $invoices,
        int $index,
        Carbon $fechaManifiesto,
        Collection $reasons,
        int $userId,
    ): int {
        $plan = [
            ['inv' => 0, 'kind' => 'caja',    'offset' => 1],
            ['inv' => 2, 'kind' => 'sueltas', 'offset' => 2],
            ['inv' => 3, 'kind' => 'mixta',   'offset' => 3],
        ];

        // La devolución TOTAL va en la mitad de los manifiestos. El desfase por
        // semana ($index/4) es lo que la reparte: con un simple $index % 2, las
        // bodegas de posición par se llevaban TODAS las totales y JO06/JO10 no
        // veían una sola en el mes. Así cada bodega termina con 2.
        if (($index + intdiv($index, count(self::BODEGAS))) % 2 === 0) {
            $plan[] = ['inv' => 5, 'kind' => 'total', 'offset' => 4];
        }

        $creadas = 0;

        foreach ($plan as $item) {
            $motivo = self::MOTIVOS[$this->motivoCursor % count(self::MOTIVOS)];
            $this->motivoCursor++;

            $fecha = $this->cerrarAgosto(
                $this->saltarDomingo($fechaManifiesto->copy()->addDays($item['offset']))
            );

            $creado = $this->crearDevolucion(
                $service,
                $invoices[$item['inv']],
                $item['kind'],
                $fecha,
                (int) $reasons[$motivo],
                $userId,
            );

            if ($creado) {
                $creadas++;
            }
        }

        return $creadas;
    }

    /**
     * Registra UNA devolución real vía ReturnService:
     *   'caja'    → 1 caja completa de la primera línea CJ.
     *   'sueltas' → 6 unidades sueltas de la primera línea UN.
     *   'mixta'   → 1 caja + 4 unidades sueltas de la primera línea CJ.
     *   'total'   → toda la cantidad disponible de cada línea.
     *
     * processed_date se alinea a la fecha de la devolución: createReturn lo
     * fija en hoy, y un histórico de agosto con procesado de septiembre
     * rompería cualquier reporte por fecha de proceso.
     */
    private function crearDevolucion(
        ReturnService $service,
        Invoice $invoice,
        string $kind,
        Carbon $fecha,
        int $reasonId,
        int $userId,
    ): bool {
        $cj = $invoice->lines->firstWhere('unit_sale', 'CJ');
        $un = $invoice->lines->firstWhere('unit_sale', 'UN');

        $lines = match ($kind) {
            'caja' => $cj ? [$this->lineaDevolucion($cj, 1.0, 0.0)] : [],
            'sueltas' => $un ? [$this->lineaDevolucion($un, 0.0, 6.0)] : [],
            'mixta' => $cj ? [$this->lineaDevolucion($cj, 1.0, 4.0)] : [],
            'total' => $invoice->lines->map(fn (InvoiceLine $line) => $this->lineaDevolucion(
                $line,
                $line->unit_sale === 'CJ' ? (float) $line->quantity_box : 0.0,
                $line->unit_sale === 'CJ' ? 0.0 : (float) $line->quantity_fractions,
            ))->all(),
            default => [],
        };

        if ($lines === []) {
            return false;
        }

        $return = $service->createReturn([
            'invoice_id' => $invoice->id,
            'return_reason_id' => $reasonId,
            'return_date' => $fecha->toDateString(),
            'created_by' => $userId,
            'lines' => $lines,
        ]);

        $return->forceFill([
            'processed_date' => $fecha->toDateString(),
            'processed_time' => '14:30:00',
            'reviewed_by' => $userId,
            'reviewed_at' => $fecha->copy()->setTime(14, 35),
        ])->saveQuietly();

        return true;
    }

    /**
     * Arma una línea del payload de devolución desde una InvoiceLine real.
     *
     * @return array<string, mixed>
     */
    private function lineaDevolucion(InvoiceLine $line, float $cajas, float $unidades): array
    {
        return [
            'invoice_line_id' => $line->id,
            'line_number' => $line->line_number,
            'product_id' => $line->product_id,
            'product_description' => $line->product_description,
            'quantity_box' => $cajas,
            'quantity' => $unidades,
        ];
    }

    /**
     * Depósito del manifiesto según el caso del PLAN. El monto sale del saldo
     * REAL ya recalculado (total_invoices − total_returns), no de una
     * constante: si mañana cambia el catálogo de productos, el histórico
     * sigue cuadrando solo.
     *
     * @param  array{number: string, wh: string, date: string, pago: string, cerrar: bool}  $paso
     */
    private function crearDeposito(
        DepositService $service,
        Manifest $manifest,
        array $paso,
        Warehouse $warehouse,
        Carbon $fecha,
        int $userId,
    ): void {
        $saldo = round((float) $manifest->total_to_deposit, 2);

        if ($saldo <= 0) {
            return;
        }

        [$monto, $justificacion, $nota] = match ($paso['pago']) {
            'parcial' => [
                round($saldo * self::FRACCION_PARCIAL, 2),
                null,
                'Abono parcial — el encargado deposita el resto la próxima semana.',
            ],
            'sobrepago' => [
                round($saldo + self::SOBREPAGO, 2),
                'El encargado transfirió una cifra redondeada y adelantó L '.
                    number_format(self::SOBREPAGO, 2).' del manifiesto siguiente.',
                'Depósito por encima del saldo — sobrepago justificado.',
            ],
            default => [$saldo, null, 'Depósito completo del manifiesto.'],
        };

        $data = [
            'amount' => $monto,
            'deposit_date' => $fecha->toDateString(),
            'bank' => Deposit::BANKS[abs(crc32($paso['number'])) % count(Deposit::BANKS)],
            'reference' => 'AGO-'.$paso['wh'].'-'.$paso['number'],
            'observations' => $nota,
            'warehouse_id' => $warehouse->id,
        ];

        if ($justificacion !== null) {
            $data['justification'] = $justificacion;
        }

        $service->createDeposit($manifest, $data, $userId);
    }

    /**
     * Devuelve la ventana de devoluciones a su valor histórico: el que el
     * manifiesto habría tenido si hubiera entrado por la API en su fecha.
     *
     * saveQuietly evita el hook de updating (que solo recalcula si cambia
     * `date`) y evita ensuciar activity_log con un cambio que en producción
     * nunca ocurre.
     */
    private function restaurarVentana(Manifest $manifest): void
    {
        $manifest->forceFill([
            'returns_deadline_at' => BusinessDays::deadline(
                $manifest->date,
                (int) config('api.devoluciones_ventana_dias_habiles', 7),
            ),
        ])->saveQuietly();
    }

    /**
     * Retrofecha los timestamps del manifiesto y de todo lo que cuelga de él.
     *
     * Sin esto el histórico de agosto aparece creado hoy: las devoluciones
     * darían `isEditableToday() === true` (editables desde el panel, justo lo
     * contrario de lo que pasa con datos de agosto) y la bitácora mostraría
     * cientos de entradas del día en que se corrió el seeder.
     *
     * Se usa el query builder crudo a propósito: Eloquent ignora escrituras
     * directas a created_at/updated_at mientras el modelo maneja timestamps.
     */
    private function retrofechar(Manifest $manifest, Carbon $fecha, Carbon $fechaDeposito): void
    {
        $llegada = $fecha->copy()->setTime(6, 15);
        $deposito = $fechaDeposito->copy()->setTime(15, 45);

        $invoiceIds = DB::table('invoices')->where('manifest_id', $manifest->id)->pluck('id')->all();
        $returnIds = DB::table('returns')->where('manifest_id', $manifest->id)->pluck('id')->all();
        $depositIds = DB::table('deposits')->where('manifest_id', $manifest->id)->pluck('id')->all();

        DB::table('manifests')->where('id', $manifest->id)
            ->update(['created_at' => $llegada, 'updated_at' => $llegada]);

        if ($invoiceIds !== []) {
            DB::table('invoices')->whereIn('id', $invoiceIds)
                ->update(['created_at' => $llegada, 'updated_at' => $llegada]);

            DB::table('invoice_lines')->whereIn('invoice_id', $invoiceIds)
                ->update(['created_at' => $llegada, 'updated_at' => $llegada]);
        }

        if ($depositIds !== []) {
            DB::table('deposits')->whereIn('id', $depositIds)
                ->update(['created_at' => $deposito, 'updated_at' => $deposito]);
        }

        $this->retrofecharBitacora(Manifest::class, [(int) $manifest->id], $llegada);
        $this->retrofecharBitacora(Invoice::class, $invoiceIds, $llegada);
        $this->retrofecharBitacora(Deposit::class, $depositIds, $deposito);

        // Cada devolución lleva su propia fecha, así que van una por una.
        foreach (DB::table('returns')->whereIn('id', $returnIds)->get(['id', 'return_date']) as $row) {
            $ts = Carbon::parse((string) $row->return_date)->setTime(14, 30);

            DB::table('returns')->where('id', $row->id)
                ->update(['created_at' => $ts, 'updated_at' => $ts]);

            DB::table('return_lines')->where('return_id', $row->id)
                ->update(['created_at' => $ts, 'updated_at' => $ts]);

            $this->retrofecharBitacora(InvoiceReturn::class, [(int) $row->id], $ts);
        }
    }

    /**
     * Retrofecha las entradas de activity_log de un conjunto de sujetos.
     *
     * subject_type guarda el nombre de clase completo: el proyecto no
     * configura morphMap, así que ::class es exactamente lo que hay en BD.
     *
     * @param  class-string  $subjectType
     * @param  array<int, mixed>  $ids
     */
    private function retrofecharBitacora(string $subjectType, array $ids, Carbon $ts): void
    {
        if ($ids === []) {
            return;
        }

        DB::table('activity_log')
            ->where('subject_type', $subjectType)
            ->whereIn('subject_id', $ids)
            ->update(['created_at' => $ts, 'updated_at' => $ts]);
    }

    /** El domingo no se opera: cae al lunes. */
    private function saltarDomingo(Carbon $fecha): Carbon
    {
        return $fecha->isSunday() ? $fecha->copy()->addDay() : $fecha;
    }

    /** Ninguna fecha del histórico se sale de agosto. */
    private function cerrarAgosto(Carbon $fecha): Carbon
    {
        $ultimo = Carbon::parse(self::ULTIMO_DIA);

        return $fecha->greaterThan($ultimo) ? $ultimo : $fecha;
    }

    /**
     * Borra todo lo sembrado por este seeder para poder repetirlo.
     *
     * El orden respeta las FK (deposits y returns son restrictOnDelete sobre
     * manifests) y usa borrado FÍSICO: un soft delete dejaría los números de
     * manifiesto ocupados y la segunda corrida chocaría contra el unique.
     */
    private function purgar(): void
    {
        $numeros = array_column(self::PLAN, 'number');

        $manifestIds = DB::table('manifests')->whereIn('number', $numeros)->pluck('id')->all();

        if ($manifestIds === []) {
            return;
        }

        $invoiceIds = DB::table('invoices')->whereIn('manifest_id', $manifestIds)->pluck('id')->all();
        $returnIds = DB::table('returns')->whereIn('manifest_id', $manifestIds)->pluck('id')->all();
        $depositIds = DB::table('deposits')->whereIn('manifest_id', $manifestIds)->pluck('id')->all();

        // La bitácora primero: después de borrar los sujetos ya no se sabe
        // cuáles eran sus entradas y quedarían huérfanas para siempre.
        $this->purgarBitacora(Manifest::class, $manifestIds);
        $this->purgarBitacora(Invoice::class, $invoiceIds);
        $this->purgarBitacora(InvoiceReturn::class, $returnIds);
        $this->purgarBitacora(Deposit::class, $depositIds);

        DB::table('return_lines')->whereIn('return_id', $returnIds)->delete();
        DB::table('returns')->whereIn('id', $returnIds)->delete();
        DB::table('deposits')->whereIn('id', $depositIds)->delete();
        DB::table('manifest_adjustments')->whereIn('manifest_id', $manifestIds)->delete();
        DB::table('invoice_lines')->whereIn('invoice_id', $invoiceIds)->delete();
        DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
        DB::table('manifest_warehouse_totals')->whereIn('manifest_id', $manifestIds)->delete();
        DB::table('manifests')->whereIn('id', $manifestIds)->delete();

        $this->command?->warn(
            'Histórico previo de agosto ('.count($manifestIds).' manifiestos) eliminado antes de recrear.'
        );
    }

    /**
     * @param  class-string  $subjectType
     * @param  array<int, mixed>  $ids
     */
    private function purgarBitacora(string $subjectType, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        DB::table('activity_log')
            ->where('subject_type', $subjectType)
            ->whereIn('subject_id', $ids)
            ->delete();
    }

    /** Tabla de control: lo que quedó en BD, para verificar de un vistazo. */
    private function resumen(): void
    {
        $rows = Manifest::whereIn('number', array_column(self::PLAN, 'number'))
            ->with('warehouseTotals.warehouse')
            ->orderBy('number')
            ->get();

        $this->command?->newLine();
        $this->command?->table(
            ['#', 'Bodega', 'Fecha', 'Fact.', 'Dev.', 'A depositar', 'Depositado', 'Diferencia', 'Estado', 'Ventana'],
            $rows->map(fn (Manifest $m) => [
                $m->number,
                $m->warehouseTotals->first()?->warehouse?->code ?? '—',
                $m->date?->format('d/m/Y'),
                $m->invoices_count,
                $m->returns_count,
                number_format((float) $m->total_to_deposit, 2),
                number_format((float) $m->total_deposited, 2),
                number_format((float) $m->difference, 2),
                $m->status,
                $m->returnsDeadlineLabel(),
            ])->all()
        );

        $this->command?->newLine();
        $this->command?->info('Totales del mes por bodega:');

        $porBodega = DB::table('manifest_warehouse_totals as t')
            ->join('warehouses as w', 'w.id', '=', 't.warehouse_id')
            ->join('manifests as m', 'm.id', '=', 't.manifest_id')
            ->whereIn('m.number', array_column(self::PLAN, 'number'))
            ->groupBy('w.code', 'w.name')
            ->orderBy('w.code')
            ->selectRaw('
                w.code,
                w.name,
                COUNT(*)                AS manifiestos,
                SUM(t.invoices_count)   AS facturas,
                SUM(t.returns_count)    AS devoluciones,
                SUM(t.total_invoices)   AS ventas,
                SUM(t.total_returns)    AS devuelto,
                SUM(t.total_to_deposit) AS a_depositar,
                SUM(t.total_deposited)  AS depositado
            ')
            ->get();

        $this->command?->table(
            ['Código', 'Bodega', 'Manif.', 'Fact.', 'Dev.', 'Ventas', 'Devuelto', 'A depositar', 'Depositado'],
            $porBodega->map(fn ($r) => [
                $r->code,
                $r->name,
                $r->manifiestos,
                $r->facturas,
                $r->devoluciones,
                number_format((float) $r->ventas, 2),
                number_format((float) $r->devuelto, 2),
                number_format((float) $r->a_depositar, 2),
                number_format((float) $r->depositado, 2),
            ])->all()
        );
    }
}
