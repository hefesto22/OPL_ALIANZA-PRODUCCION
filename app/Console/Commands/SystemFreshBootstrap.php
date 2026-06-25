<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\User;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Bootstrap del sistema desde cero — para QA, staging y deploy inicial.
 *
 * BORRA TODA LA BASE DE DATOS y la deja con:
 *   • 5 roles (super_admin, admin, encargado, operador, finance)
 *   • Permisos Shield + 6 permisos custom asignados según matriz
 *   • 4 bodegas (OAC=Copán, OAS=Santa Bárbara, OAO=Ocotepeque, OAI=Intibucá)
 *   • Proveedor Jaremar
 *   • 30 motivos de devolución
 *   • 13 usuarios listos para QA:
 *       - super_admin (credenciales tipeadas por el operador en runtime)
 *       - 12 usuarios de bodega (3 roles × 4 bodegas)
 *   El rol 'admin' (gestor global) queda definido pero sin usuario sembrado.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  TRIPLE GUARD CONTRA DESTRUCCIÓN ACCIDENTAL
 * ──────────────────────────────────────────────────────────────────────
 *  1. Aborta si APP_ENV=production salvo --force-production.
 *  2. Cuenta registros existentes y los reporta antes de tocar nada.
 *  3. Exige escribir literalmente "BORRAR" — no "yes" ni Enter ciego.
 *
 *  Es operativamente IMPOSIBLE disparar este comando por accidente
 *  en producción mientras alguien preste un mínimo de atención.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  USO — INTERACTIVO (recomendado en operación normal)
 * ──────────────────────────────────────────────────────────────────────
 *  Local / staging:
 *    php artisan system:fresh-bootstrap
 *
 *  El comando preguntará email + password del super_admin con
 *  validación (formato email, password ≥ 8 caracteres). Estas
 *  credenciales NO se persisten en .env ni en logs — solo el
 *  operador que tipea las conoce.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  USO — NO INTERACTIVO (CI / tests / scripting)
 * ──────────────────────────────────────────────────────────────────────
 *  php artisan system:fresh-bootstrap \\
 *      --super-admin-email=admin@miempresa.com \\
 *      --super-admin-password='PasswordSeguro123!'
 *
 *  ⚠️  Pasar el password por CLI lo deja en el history del shell.
 *  Solo usar en CI controlado o tests, NUNCA en producción.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  USO — PRODUCCIÓN
 * ──────────────────────────────────────────────────────────────────────
 *  php artisan system:fresh-bootstrap --force-production
 *  (igual pide confirmación "BORRAR" y el super_admin interactivo)
 *
 * ──────────────────────────────────────────────────────────────────────
 *  CREDENCIALES DE LOS USUARIOS DE BODEGA
 * ──────────────────────────────────────────────────────────────────────
 *  Los 12 usuarios de bodega comparten password de TEST_USER_PASSWORD
 *  en .env (default: Hozana@2026; para local usa 12345678). Al final del
 *  comando se imprime ese password en pantalla. El password del
 *  super_admin NUNCA se imprime — solo lo conoce quien lo tipeó.
 */
class SystemFreshBootstrap extends Command
{
    protected $signature = 'system:fresh-bootstrap
                            {--force-production : Permitir ejecución en APP_ENV=production (RIESGO ALTO)}
                            {--super-admin-email= : Email del super_admin (si se omite, se pide interactivo)}
                            {--super-admin-password= : Password del super_admin, mínimo 8 chars (si se omite, se pide interactivo)}';

    protected $description = 'Borra la BD y la reconstruye con roles, permisos, bodegas y 11 usuarios listos para QA';

    /**
     * Credenciales del super_admin resueltas (flags o prompts).
     * Se llenan en collectSuperAdminCredentials() antes del bootstrap
     * para que si el operador cancela en el prompt no se haya tocado BD.
     */
    private ?string $superAdminEmail = null;

    private ?string $superAdminPassword = null;

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Bootstrap del Sistema · Distribuidora Hozana');

        // ── Guard 1: entorno productivo ────────────────────────────
        if (! $this->checkEnvironmentGuard()) {
            return self::FAILURE;
        }

        // ── Guard 2: reportar datos existentes ─────────────────────
        $this->reportExistingData();

        // ── Guard 3: confirmación interactiva escribiendo BORRAR ──
        if (! $this->confirmDestruction()) {
            $this->components->warn('Operación cancelada por el usuario.');

            return self::FAILURE;
        }

        // ── Recoger credenciales del super_admin ANTES de tocar BD ─
        // Si el operador cancela el prompt o las validaciones fallan,
        // abortamos sin destruir datos.
        try {
            $this->collectSuperAdminCredentials();
        } catch (Throwable $e) {
            $this->components->error('No se pudieron recoger las credenciales del super_admin: '.$e->getMessage());

            return self::FAILURE;
        }

        // ── Ejecución del bootstrap ───────────────────────────────
        try {
            $this->runBootstrap();
        } catch (Throwable $e) {
            $this->components->error('Bootstrap falló: '.$e->getMessage());
            $this->line($e->getTraceAsString());

            // En testing, re-lanzamos para que PHPUnit muestre el error
            // real en lugar de un opaco "exit code 1". En producción
            // queda el flujo normal (mensaje al usuario + FAILURE).
            if (app()->environment('testing')) {
                throw $e;
            }

            return self::FAILURE;
        }

        $this->renderSummary();

        return self::SUCCESS;
    }

    /**
     * Aborta si APP_ENV=production y no se pasó --force-production.
     */
    private function checkEnvironmentGuard(): bool
    {
        $env = app()->environment();

        if ($env === 'production' && ! $this->option('force-production')) {
            $this->components->error(
                "APP_ENV=production detectado. Este comando BORRA TODA LA BD.\n".
                'Si realmente quieres ejecutarlo, agrega --force-production.'
            );

            return false;
        }

        if ($env === 'production') {
            $this->components->warn(
                '⚠  Ejecutando en APP_ENV=production con --force-production. '.
                'Esto borrará datos productivos REALES.'
            );
        } else {
            $this->components->info("Entorno: {$env}");
        }

        return true;
    }

    /**
     * Reporta cuántos registros existen en las tablas críticas.
     * Esto le da al operador una idea de qué está a punto de borrar.
     */
    private function reportExistingData(): void
    {
        $hasUsersTable = $this->tableExists('users');

        if (! $hasUsersTable) {
            $this->components->info('Base de datos vacía (sin tabla users).');

            return;
        }

        $rows = [
            ['Usuarios', User::query()->withTrashed()->count()],
            ['Roles', Role::query()->count()],
            ['Permisos', Permission::query()->count()],
            ['Manifiestos', $this->safeCount(Manifest::class)],
            ['Facturas', $this->safeCount(Invoice::class)],
            ['Devoluciones', $this->safeCount(InvoiceReturn::class)],
            ['Depósitos', $this->safeCount(Deposit::class)],
        ];

        $this->newLine();
        $this->line('Datos actualmente en la BD que serán DESTRUIDOS:');
        $this->table(['Tabla', 'Registros'], $rows);
    }

    /**
     * Pide confirmación interactiva escribiendo "BORRAR".
     * No acepta yes/no/Enter — fuerza al operador a tipear la palabra.
     */
    private function confirmDestruction(): bool
    {
        $this->newLine();
        $this->components->warn(
            'Esta acción es IRREVERSIBLE. Para continuar, escribe la palabra BORRAR (en mayúsculas):'
        );

        $response = (string) $this->ask('Confirmación');

        return trim($response) === 'BORRAR';
    }

    /**
     * Recoge email + password del super_admin desde flags o prompts.
     *
     * Estrategia:
     *   - Si vienen ambas flags → valida y usa directamente.
     *   - Si vienen parcial → falla con mensaje claro (no mezclar modos).
     *   - Si no vienen → pregunta interactivamente con Laravel Prompts.
     *
     * En tests ($this->artisan()) se pasan los flags. En operación
     * normal el usuario tipea las credenciales en el TTY.
     *
     * @throws \InvalidArgumentException Si las credenciales son inválidas.
     */
    private function collectSuperAdminCredentials(): void
    {
        $emailFlag = $this->option('super-admin-email');
        $passwordFlag = $this->option('super-admin-password');

        // Modo mixto = error de usuario; mejor abortar que adivinar.
        $bothFlags = filled($emailFlag) && filled($passwordFlag);
        $noFlags = blank($emailFlag) && blank($passwordFlag);

        if (! $bothFlags && ! $noFlags) {
            throw new \InvalidArgumentException(
                'Debes pasar --super-admin-email y --super-admin-password JUNTOS, '.
                'o ninguno para modo interactivo.'
            );
        }

        if ($bothFlags) {
            $this->superAdminEmail = $this->validateSuperAdminEmail((string) $emailFlag);
            $this->superAdminPassword = $this->validateSuperAdminPassword((string) $passwordFlag);

            return;
        }

        // Modo interactivo: Laravel Prompts con validación inline.
        $this->newLine();
        $this->components->info('Configura las credenciales del super_admin del nuevo sistema:');

        $this->superAdminEmail = text(
            label: 'Email del super_admin',
            placeholder: 'admin@miempresa.com',
            required: true,
            validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL)
                ? null
                : 'El email no tiene un formato válido.',
        );

        $this->superAdminPassword = password(
            label: 'Password (mínimo 8 caracteres)',
            required: true,
            validate: fn (string $value): ?string => strlen($value) >= 8
                ? null
                : 'El password debe tener al menos 8 caracteres.',
        );
    }

    private function validateSuperAdminEmail(string $email): string
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Email inválido: '{$email}'");
        }

        return $email;
    }

    private function validateSuperAdminPassword(string $password): string
    {
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('El password debe tener al menos 8 caracteres.');
        }

        return $password;
    }

    /**
     * Ejecuta el bootstrap en el orden correcto.
     *
     * Orden:
     *   1. migrate:fresh --force          → tablas limpias
     *   2. db:seed --force                → roles + bodegas + supplier + motivos
     *                                       (NO crea super_admin; AdminUserSeeder fue retirado)
     *                                       (RolePermissionSeeder hace graceful skip aquí)
     *   3. shield:generate --all          → genera permisos Shield (solo permisos, no policies)
     *   4. db:seed CustomPermissionSeeder → crea los 6 permisos custom de botones
     *   5. provisionSuperAdmin()          → crea el User super_admin + shield:super-admin
     *   6. db:seed RolePermissionSeeder   → asigna permisos a admin/encargado/operador/finance
     *   7. db:seed TestUsersSeeder        → crea 12 usuarios de bodega
     *
     * NOTA: NO pasamos $this->output a Artisan::call. En contexto de
     * tests, $this->output es un mock de Mockery configurado solo para
     * questions — los sub-comandos que escriben al output (writeln,
     * info, etc.) explotarían con BadMethodCallException. Sin el
     * parámetro, Artisan::call usa un BufferedOutput interno y el
     * resultado se captura con Artisan::output() si necesitamos
     * diagnosticar.
     */
    private function runBootstrap(): void
    {
        $this->newLine();

        // ── Fase 1: BD limpia + datos base ─────────────────────────
        $this->runArtisanStep(
            label: 'Migrando BD desde cero (migrate:fresh)',
            command: 'migrate:fresh',
            parameters: ['--force' => true],
        );

        $this->runArtisanStep(
            label: 'Sembrando datos base (db:seed)',
            command: 'db:seed',
            parameters: ['--force' => true],
        );

        // ── Fase 2: permisos Shield ────────────────────────────────
        // shield:generate tiene 3 prompts interactivos por defecto:
        //   1. "Which panel?"               → --panel=admin
        //   2. "Would you like to select?"  → --option=permissions (lo salta)
        //   3. "What do you want?"          → --option=permissions (lo salta)
        //
        // Usamos --option=permissions (NO policies_and_permissions)
        // porque las Policies del proyecto ya están escritas a mano
        // (App\Policies\*Policy) con lógica de filtrado por bodega
        // vía HandlesWarehouseScope::userOwnsRecord(). Si Shield
        // regenerara las Policies, sobrescribiría esa lógica.
        $this->runArtisanStep(
            label: 'Generando permisos Shield (shield:generate --all --panel=admin --option=permissions)',
            command: 'shield:generate',
            parameters: [
                '--all' => true,
                '--panel' => 'admin',
                '--option' => 'permissions',
                '--no-interaction' => true,
            ],
        );

        // ── Fase 3: permisos custom de botones (no derivan de Resource) ──
        // Se crean ANTES de promover al super_admin para que shield:super-admin
        // también se los asigne a ese rol, y ANTES de RolePermissionSeeder
        // para que la matriz pueda referenciarlos (Close/Reopen:Manifest,
        // ExportPdf/Excel:Deposit/InvoiceReturn).
        $this->runArtisanStep(
            label: 'Creando permisos personalizados (CustomPermissionSeeder)',
            command: 'db:seed',
            parameters: ['--class' => 'Database\\Seeders\\CustomPermissionSeeder', '--force' => true],
        );

        // ── Fase 4: provisioning del super_admin (patrón Shield) ──
        $this->provisionSuperAdmin();

        // ── Fase 5: asignación de permisos a roles + usuarios QA ──
        $this->runArtisanStep(
            label: 'Asignando permisos a roles (RolePermissionSeeder)',
            command: 'db:seed',
            parameters: ['--class' => 'Database\\Seeders\\RolePermissionSeeder', '--force' => true],
        );

        $this->runArtisanStep(
            label: 'Creando usuarios de QA (TestUsersSeeder)',
            command: 'db:seed',
            parameters: ['--class' => TestUsersSeeder::class, '--force' => true],
        );
    }

    /**
     * Helper: corre un sub-comando artisan reportando el paso y validando
     * el exit code. Si falla, lanza RuntimeException con el output
     * capturado para diagnosticar desde el catch del handle().
     *
     * @param  array<string, mixed>  $parameters
     */
    private function runArtisanStep(string $label, string $command, array $parameters): void
    {
        $this->line(' • '.$label);

        $exitCode = Artisan::call($command, $parameters);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "Sub-comando '{$command}' falló con exit code {$exitCode}.\n".
                'Output capturado:'."\n".Artisan::output()
            );
        }
    }

    /**
     * Crea el User super_admin con las credenciales tipeadas por el
     * operador, y delega en `shield:super-admin --user=<id>` para que
     * Shield asigne el rol siguiendo su patrón canónico.
     *
     * Esto reemplaza al antiguo AdminUserSeeder que hardcodeaba defaults
     * inseguros (admin@gmail.com / password) y dependía de env vars
     * mezcladas con configuración general.
     */
    private function provisionSuperAdmin(): void
    {
        $this->line(' • Creando super_admin: '.$this->superAdminEmail);

        // Cast 'password' => 'hashed' del User model hashea al asignar.
        // Pasamos plaintext y dejamos que Eloquent lo haga (Hash::needsRehash
        // no re-hashea si ya viene hasheado, pero aquí garantizamos 1 sola
        // pasada usando Hash::make explícito).
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => $this->superAdminEmail,
            'password' => Hash::make($this->superAdminPassword),
            'is_active' => true,
        ]);

        // email_verified_at NO está en User::$fillable, así que Mass
        // Assignment lo filtra silenciosamente. Asignación directa
        // post-create lo deja correctamente seteado (el cast 'datetime'
        // lo serializa al persistir).
        $superAdmin->email_verified_at = now();
        $superAdmin->save();

        // shield:super-admin con --user=ID es 100% no interactivo:
        // promueve al user y le asigna todos los permisos (mismo backend
        // que Shield usa cuando lo corres a mano).
        $this->runArtisanStep(
            label: 'Promoviendo a super_admin vía Shield (shield:super-admin --user='.$superAdmin->id.')',
            command: 'shield:super-admin',
            parameters: [
                '--user' => $superAdmin->id,
                '--panel' => 'admin',
                '--no-interaction' => true,
            ],
        );
    }

    /**
     * Imprime el resumen final con credenciales para QA.
     *
     * Nota de seguridad: el password del super_admin NO se imprime —
     * lo conoce únicamente el operador que lo tipeó. Los passwords de
     * los 10 usuarios "de QA" SÍ se muestran porque son cuentas
     * compartidas con TEST_USER_PASSWORD del .env (no son secretos).
     */
    private function renderSummary(): void
    {
        $password = env('TEST_USER_PASSWORD', 'Hozana@2026');

        $this->newLine(2);
        $this->components->info('✓ Bootstrap completado exitosamente');
        $this->newLine();

        $this->line('<fg=cyan;options=bold>Super admin (password NO se imprime — lo conoces tú):</>');
        $this->table(
            ['Email', 'Rol', 'Bodega'],
            [[$this->superAdminEmail, 'super_admin', 'global']]
        );

        $this->line('<fg=cyan;options=bold>Usuarios de bodega (3 roles × 4 bodegas):</>');
        $slugs = [
            'OAC' => 'copan',
            'OAS' => 'santabarbara',
            'OAO' => 'ocotepeque',
            'OAI' => 'intibuca',
        ];
        $rows = [];
        foreach ($slugs as $code => $slug) {
            foreach (['encargado', 'operador', 'finance'] as $role) {
                $rows[] = ["{$role}.{$slug}@gmail.com", $password, $role, $code];
            }
        }
        $this->table(['Email', 'Password', 'Rol', 'Bodega'], $rows);

        $this->newLine();
        $this->components->warn(
            'Los 12 usuarios de bodega comparten el mismo password. '.
            'Cámbialos individualmente desde el panel antes de pasar a operación real.'
        );
        $this->newLine();
    }

    /**
     * Helper: ¿existe la tabla?  (la BD puede estar fresca/migrada o no).
     */
    private function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Helper: count() defensivo — si el modelo o tabla no existe aún, devuelve 0.
     */
    private function safeCount(string $modelClass): int
    {
        try {
            return $modelClass::query()->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
