# Especificación técnica — Depósitos multi-manifiesto y ajuste de centavos

> Distribuidora Hozana · Grupo Olympo
> Fecha: 28/07/2026 · Autor: arquitectura
> Estado: **aprobada — pendiente de implementación**

---

## 1. Origen del requerimiento

El cliente reporta dos situaciones operativas reales:

1. **Una sola transferencia para varios manifiestos.** Para no hacer dos transferencias bancarias, el operador manda una sola boleta que cubre el saldo de dos o más manifiestos. Hoy el sistema lo rechaza: `DepositService::assertAmountWithinPending()` corta cualquier monto mayor al pendiente del manifiesto seleccionado.

2. **Centavos varados.** Los depósitos bancarios rara vez cuadran al centavo. En producción (114 manifiestos activos, **0 cerrados**) hay manifiestos con diferencias de `L 0.32`, `L 0.01` y `−L 0.01` que **no pueden cerrarse nunca**, porque `Manifest::isReadyToClose()` exige `difference == 0` exacto.

Ambas se resuelven con el mismo cambio de modelo, más un mecanismo de ajuste auditable.

---

## 2. Diagnóstico del modelo actual

| Punto | Ubicación | Problema |
|---|---|---|
| `deposits.manifest_id` es 1:1 | `2026_03_04_164559_create_deposits_table` | el monto completo cae en un solo manifiesto; no hay dónde repartirlo |
| `assertAmountWithinPending()` | `app/Services/DepositService.php` | corta montos `> pendiente + 0.01` |
| Margen `+ 0.01` en ese mismo validador | idem | **fabrica los `−L 0.01` de producción**: deja pasar depósitos un centavo por encima del pendiente |
| `difference = total_to_deposit − total_deposited` | `Manifest::recalculateTotals()` | queda negativa ante sobredepósito |
| `isReadyToClose()` exige `difference == 0` | `app/Models/Manifest.php` | cualquier centavo (de más o de menos) deja el manifiesto varado |

**Insight clave:** el reparto entre manifiestos resuelve los centavos que **faltan** (llega dinero real que los tapa), pero **no puede resolver los que sobran** (`−L 0.01`): no hay plata que mover, el manifiesto ya recibió de más. Esos requieren un mecanismo distinto — el ajuste auditable de la §5.

---

## 3. Decisiones aprobadas (28/07/2026)

| Decisión | Elección |
|---|---|
| Enfoque | **Plan A** — tabla `deposit_allocations` (aplicación de pagos) |
| Alcance del reparto | **Misma bodega** que el manifiesto de origen, filtrado por `WarehouseScope` |
| Remanente | **No existe "sobrante suelto"** — la plata siempre corresponde a manifiestos (ver §4.4 para el caso borde) |
| Centavos que sobran | **Ajuste de centavos auditable** — registro explícito con permiso, motivo y log. **No** tolerancia automática |
| Margen `+0.01` del validador | **Se elimina** |
| Tolerancia automática de cierre | **Descartada** |

---

## 4. Diseño — Depósito bancario + Aplicaciones

### 4.1 Modelo de datos

```
deposits (existente, + 2 columnas)      deposit_allocations (nueva)
──────────────────────────────────      ───────────────────────────
id                                      id
manifest_id   ← se conserva como        deposit_id      → deposits (cascade)
                manifiesto de ORIGEN    manifest_id     → manifests (restrict)
amount        (monto de la boleta)      amount          decimal(12,2)  CHECK > 0
justification ← NUEVA (text, null)      created_by      → users
allocated_amount ← NUEVA (cache)        timestamps
bank, reference, observations           UNIQUE (deposit_id, manifest_id)
receipt_image, cancelled_at, ...        INDEX  (manifest_id)
```

**Por qué se conserva `deposits.manifest_id`:** compatibilidad total hacia atrás. Todo el código actual que hace `$deposit->manifest` sigue funcionando, `DepositPolicy` y `scopeVisibleTo` no se tocan, y el backfill es trivial. Pasa a significar *"manifiesto desde el que se registró el depósito"*, no *"único manifiesto que cubre"*.

**Por qué `allocated_amount` como cache:** evita un `SUM` por fila al listar depósitos en la tabla de Filament. Se recalcula dentro de la misma transacción que toca allocations; nunca se escribe desde fuera del `AllocationService`.

### 4.2 Invariantes (blindadas por tests)

```
I1.  SUM(allocations WHERE deposit_id = D) == deposits.amount   (siempre, sin excepción)
I2.  allocation.amount > 0
I3.  manifest.total_deposited == SUM(allocations activas del manifiesto)
I4.  deposits.allocated_amount == SUM(allocations WHERE deposit_id = D)
I5.  ningún manifiesto destino puede estar cerrado (status = 'closed')
```

`I1` es la invariante fuerte: **no existe dinero registrado sin aplicar**. Un depósito de L 5,000 siempre suma exactamente L 5,000 repartidos entre manifiestos.

"Allocation activa" = la de un depósito con `cancelled_at IS NULL` y `deleted_at IS NULL`.

### 4.3 Cambio en `Manifest::recalculateTotals()`

```php
// ANTES
$totalDeposited = (float) $this->deposits()->active()->sum('amount');

// DESPUÉS
$totalDeposited = (float) DepositAllocation::query()
    ->where('deposit_allocations.manifest_id', $this->id)
    ->join('deposits', 'deposits.id', '=', 'deposit_allocations.deposit_id')
    ->whereNull('deposits.cancelled_at')
    ->whereNull('deposits.deleted_at')
    ->sum('deposit_allocations.amount');
```

Costo: index en `deposit_allocations(manifest_id)` + PK de `deposits`. Los depósitos por manifiesto son pocas decenas — irrelevante frente a `invoice_lines`. No hay riesgo de escala acá.

`difference` pasa a incluir el ajuste (§5):

```php
$this->difference = $this->total_to_deposit
                  - $this->total_deposited
                  - $this->adjustment_amount;
```

### 4.4 Reparto (FIFO)

Al registrar un depósito con monto mayor al pendiente del manifiesto de origen:

1. Se llena **primero el manifiesto de origen** hasta su pendiente.
2. El excedente se reparte entre los **manifiestos candidatos**, del más antiguo al más nuevo (`ORDER BY date ASC, id ASC`), hasta agotarlo.
3. **Manifiesto candidato** = manifiesto que cumple *todas*:
   - `status IN ('imported', 'processing')` (no cerrado)
   - `difference > 0` (le falta dinero)
   - comparte al menos una bodega con el manifiesto de origen — vía `manifest_warehouse_totals`, **no** vía `manifests.warehouse_id` (que es nullable y no representa manifiestos multi-bodega)
   - pasa el `WarehouseScope` del usuario
   - mismo `supplier_id`
4. El operador puede aceptar el reparto automático o editarlo monto por monto antes de guardar.

**Caso borde — el depósito supera la suma de TODOS los pendientes candidatos.** Ocurre si transfieren antes de que entre el manifiesto correspondiente. La invariante `I1` obliga a que ese remanente vaya a algún lado: se aplica al **manifiesto de origen** por encima de su pendiente, dejando su `difference` en negativo, y **la justificación pasa a ser obligatoria**. Ese manifiesto queda pendiente de resolver con un ajuste (§5) o con la reversión del depósito. Es el único escenario donde un manifiesto termina sobre-depositado.

### 4.5 Justificación

Campo `deposits.justification` (text, nullable en BD).

**Obligatorio** (validado en el form *y* en el `DepositService` — última línea de defensa) cuando:

```
monto > pendiente del manifiesto de origen
```

Mínimo 15 caracteres. Se registra en `activity('finance')` junto con el desglose completo del reparto:

```php
activity('finance')
    ->performedOn($deposit)
    ->causedBy(auth()->user())
    ->withProperties([
        'amount' => 5000.00,
        'justification' => '...',
        'allocations' => [
            ['manifest' => '785569', 'amount' => 160620.46],
            ['manifest' => '784907', 'amount' => 0.32],
        ],
    ])
    ->log('Depósito aplicado a múltiples manifiestos');
```

### 4.6 Concurrencia

Un depósito ahora bloquea **N manifiestos**. Regla no negociable:

```php
$manifests = Manifest::whereIn('id', $ids)
    ->orderBy('id')          // ← SIEMPRE ascendente, sin excepción
    ->lockForUpdate()
    ->get();
```

Sin el orden determinista, dos operadores repartiendo entre los mismos manifiestos en orden distinto se trabarían mutuamente (deadlock de Postgres) en horario pico. El `ORDER BY id ASC` es lo que lo previene.

### 4.7 Cancelación y edición

- **Cancelar depósito:** las allocations se marcan inactivas por cascada lógica (el depósito queda `cancelled_at`), y se recalculan **todos** los manifiestos que tenían allocation de ese depósito, dentro de la misma transacción.
- **Editar depósito:** si cambia el monto, se rehace el reparto completo; se recalculan tanto los manifiestos que salen como los que entran.
- `assertManifestOpen()` debe correr **por cada manifiesto destino**, no solo por el de origen. Hoy solo valida uno.

---

## 5. Ajuste de centavos auditable

Resuelve los manifiestos que quedan con diferencia distinta de cero y que **ningún depósito futuro va a cubrir** — en particular los `−L 0.01`.

### 5.1 Modelo

```
manifest_adjustments (nueva)          manifests (+ 1 columna)
────────────────────────────          ───────────────────────
id                                    adjustment_amount  decimal(12,2) default 0
manifest_id  → manifests
amount       decimal(12,2)  ← puede ser NEGATIVO
reason       string(500)    ← obligatorio
created_by   → users
timestamps
INDEX (manifest_id)
```

`manifests.adjustment_amount` = `SUM(manifest_adjustments.amount)`, recalculado en `recalculateTotals()`.

### 5.2 Reglas

- Permiso propio en Shield: **`Adjust:Manifest`** (custom permission, igual patrón que `ExportInvoicesPdf:Manifest`).
- Tope configurable: `config('manifests.ajustes.tope_hnl')`, arranca en **1.00**. Un ajuste mayor se rechaza en el Service.
- `reason` obligatorio, mínimo 10 caracteres.
- Registro en `activity('finance')` con monto, motivo, diferencia antes y después.
- No se puede ajustar un manifiesto cerrado.

### 5.3 Lo que NO cambia

- **`isReadyToClose()` se queda igual**: sigue exigiendo `difference == 0` exacto. No hay tolerancia, no hay cierre automático. El ajuste es simplemente la forma auditable de llegar a cero.
- **La columna "Depositado" sigue diciendo la verdad**: plata real en el banco. El ajuste se muestra aparte, como chip `Ajuste L 0.32` en el infolist y en la tabla.

Diferencia de fondo con una tolerancia automática: acá **alguien firma cada centavo**, con nombre, motivo y hora. Nada se cierra solo.

---

## 6. Eliminación del margen `+0.01`

En `DepositService::assertAmountWithinPending()`:

```php
if ($amount > $pending + 0.01) {   // ← se elimina el margen
```

Con el Plan A el monto ya no tiene tope por manifiesto, así que el método se reescribe: en vez de validar contra el pendiente de un manifiesto, valida que **la suma del reparto sea exactamente igual al monto del depósito** (invariante `I1`). El margen deja de tener propósito y de fabricar `−L 0.01`.

---

## 7. Impacto en reportes — riesgo de doble conteo

Regla que hay que respetar en todo el código de reportes:

| Métrica | Fuente correcta |
|---|---|
| Recaudación / dinero que entró al banco | `deposits.amount` agrupado por `deposit_date` |
| Cobertura de un manifiesto | `deposit_allocations.amount` |

**Nunca sumar ambas en el mismo número.** Archivos a revisar:

- `app/Filament/Widgets/MonthlySalesVsCollectionsChart.php`
- `app/Filament/Widgets/DashboardStatsOverview.php`
- `app/Filament/Widgets/ManifestAgingWidget.php`
- `app/Exports/DepositsExport.php` — pasa a mostrar el desglose de allocations
- `app/Filament/Resources/Manifests/RelationManagers/DepositsRelationManager.php` — debe listar allocations del manifiesto, no depósitos con `manifest_id` = este

---

## 8. Backfill

Migración de datos, por chunks de 1,000:

```
INSERT INTO deposit_allocations (deposit_id, manifest_id, amount, created_by, ...)
SELECT id, manifest_id, amount, created_by, ... FROM deposits;

UPDATE deposits SET allocated_amount = amount;
```

**Test de paridad obligatorio:** para cada manifiesto, `total_deposited` calculado con el método viejo debe ser **idéntico** al calculado con allocations. Si un solo manifiesto difiere, la migración falla y hace rollback.

---

## 9. Plan de entrega

| Fase | Contenido | Visible al usuario |
|---|---|---|
| **1** | Migraciones + backfill + `DepositAllocation` + `AllocationService` + tests | No — el sistema se comporta idéntico |
| **2** | `recalculateTotals()` pasa a allocations + contract test de paridad + quitar margen `+0.01` | No |
| **3** | UI del modal: monto libre, reparto FIFO editable, justificación condicional | Sí |
| **4** | Ajuste de centavos (tabla, permiso Shield, acción, chip en UI) | Sí |
| **5** | Ajuste de reportes, exports y `DepositsRelationManager` | Sí |

Cada fase es desplegable sola y reversible.

---

## 10. Cobertura de tests exigida

- [ ] Invariante `I1`: reparto siempre suma exacto al monto del depósito
- [ ] Reparto FIFO respeta orden por fecha y solo toca manifiestos de la misma bodega
- [ ] Manifiesto cerrado nunca recibe allocation
- [ ] Justificación obligatoria cuando el monto supera el pendiente de origen
- [ ] Cancelar depósito revierte **todas** sus allocations y recalcula **todos** los manifiestos
- [ ] Editar monto rehace el reparto y recalcula manifiestos que entran y que salen
- [ ] Paridad backfill: `total_deposited` idéntico pre/post migración
- [ ] Concurrencia: dos depósitos simultáneos sobre los mismos manifiestos no producen deadlock ni sobre-aplicación
- [ ] Ajuste rechazado si supera el tope configurado
- [ ] Ajuste rechazado sin permiso `Adjust:Manifest`
- [ ] `WarehouseScope`: un usuario de OAC no ve ni puede aplicar a manifiestos de OAS
- [ ] `MultiTenantContractTest` sigue verde con las tablas nuevas

---

## 11. Recordatorios de proceso

- Correr `vendor/bin/pint` sobre los archivos tocados **antes de cada commit** — el CI corta ahí antes de llegar a PHPUnit.
- **Nunca** correr `RolePermissionSeeder` en producción: prod tiene deltas manuales. El permiso `Adjust:Manifest` se agrega en prod con un comando puntual, no con el seeder.
