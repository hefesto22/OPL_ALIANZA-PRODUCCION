# Depósitos: monto libre, sobrepago y ajuste de centavos

> Distribuidora Hozana · Grupo Olympo
> Decisión final: 29/07/2026 · Estado: **implementado, validado en pruebas**

---

## El pedido

Dos situaciones reales de la operación:

**Depositan de más, a propósito.** A veces transfieren una cifra redondeada, o más de lo que el manifiesto debe. El sistema lo rechazaba: `DepositService` cortaba cualquier monto mayor al saldo pendiente. Necesitan poder registrarlo, y que quede constancia de por qué.

**Centavos varados.** Los depósitos rara vez cuadran al centavo. Al 28/07/2026 producción tenía **114 manifiestos activos y 0 cerrados**, con 13 trabados por diferencias de menos de un lempira que nunca iban a cubrirse.

---

## Las reglas

**1. Un depósito se aplica íntegro a su manifiesto.** No se reparte a otros, no se guarda saldo a favor. Donde se registra, ahí queda.

**2. El monto no tiene tope.** Puede superar el saldo pendiente. Cuando lo supera, se exige una **justificación escrita de al menos 15 caracteres**, validada en el servicio y no solo en el formulario.

**3. El exceso queda como SOBREPAGO visible.** El manifiesto muestra `(=) Sobrepago` en ámbar en vez de `Diferencia` en rojo — porque sobrar no es lo mismo que faltar.

**4. Un manifiesto sobrepagado SÍ se puede cerrar**, siempre que el depósito que lo causó tenga justificación. Faltar dinero sigue bloqueando el cierre sin excepción: ahí hay plata que la empresa no recibió.

**5. Los centavos se resuelven con un ajuste auditable**, no con una tolerancia automática.

---

## Lo que se descartó, y por qué

**Repartir una boleta entre varios manifiestos.** Se llegó a implementar (tabla `deposit_allocations`, reparto FIFO a deudas viejas y chicas) y se validó en pruebas. La operación lo evaluó y prefirió no tenerlo: mover plata sola entre manifiestos hace más difícil explicar los números, y el objetivo real era registrar el depósito tal como ocurrió. Se eliminó por completo (migración `2026_07_29_100000_drop_deposit_allocations`).

**Saldo a favor de la bodega.** Descartado por el mismo motivo: prefieren ver el sobrepago marcado en el manifiesto donde ocurrió, no un crédito flotante que alguien tenga que acordarse de aplicar.

---

## El hallazgo: de dónde salieron los `−L 0.01`

`DepositService::assertAmountWithinPending()` tenía esto:

```php
if ($amount > $pending + 0.01) {   // margen de un centavo
```

Ese margen dejaba pasar depósitos un centavo por encima del pendiente **sin pedir explicación y sin dejar rastro**. Los 4 manifiestos con diferencia negativa que hay en producción los generó el propio sistema. **Se eliminó**: ahora cualquier exceso, aunque sea de un centavo, queda justificado.

Consecuencia: con el código actual ya no se puede crear un manifiesto sobrepagado sin justificación. Los que existen son cicatrices del código viejo, y para eso está el ajuste.

---

## El ajuste de centavos

| | |
|---|---|
| Tabla | `manifest_adjustments` (manifest_id, amount, reason, created_by) |
| Columna | `manifests.adjustment_amount` |
| Fórmula | `difference = total_to_deposit − total_deposited − adjustment_amount` |
| Tope | `config('manifests.ajustes.tope_hnl')` = **L 1.00** |
| Permiso | `Adjust:Manifest` (admin y finance) |
| Motivo | obligatorio, mínimo 10 caracteres |
| Auditoría | canal `finance` con diferencia antes y después |

**La columna "Depositado" nunca se infla.** El ajuste se muestra aparte como `(−) Ajuste`, porque Depositado debe seguir diciendo cuánta plata entró al banco.

**`isReadyToClose()` no se relajó**: sigue exigiendo cero exacto en los faltantes. El ajuste es simplemente la vía auditable de llegar a cero, con nombre y firma. Se descartó una tolerancia automática (`|difference| <= 0.05`) justamente porque no responde *"¿quién autorizó dar por buenos esos centavos?"*.

---

## Alcance del ajuste

Vale la pena tenerlo claro para no confundirse dentro de seis meses:

- **Un sobrepago de hoy no necesita ajuste.** Nace con justificación obligatoria y se cierra solo.
- **Un sobrepago heredado sí lo necesita.** No tiene justificación, así que el cierre está bloqueado, y como ya sobra plata no hay depósito que lo arregle.
- **Un faltante de centavos también lo usa**, cuando es una diferencia de redondeo que nadie va a depositar.

Hay un test que cubre cada caso, incluido el de "un sobrepago justificado no necesita ajuste", para que nadie lo tome como parte del flujo normal.

---

## Auditoría

| Evento | Canal | Cuándo |
|---|---|---|
| `Depósito registrado por encima del total` | finance | el manifiesto queda sobrepagado |
| `Depósito modificado por encima del total` | finance | idem, al editar |
| `Manifiesto cerrado con sobrepago` | finance | cierre con diferencia negativa |
| `Ajuste de diferencia registrado` | finance | cada ajuste, con diferencia antes/después |
| `Depósito cancelado` / `eliminado permanentemente` | finance | ya existían |

---

## Pendientes antes de producción

**1. Permisos del super_admin.** En pruebas se descubrió que el rol `super_admin` no tenía asignado ningún permiso custom y el `intercept_gate` de Shield no estaba actuando: no veía las pestañas Depósitos ni Devoluciones ni el botón de ajuste. Es un problema **preexistente**, no de este cambio. Se corrigió en pruebas con `syncPermissions(Permission::all())`. **Hay que verificar si producción tiene lo mismo** — si no, Mayra no verá el botón que necesita.

**2. Ensayo del backfill.** No aplica ya: la migración de allocations se crea y se destruye en la misma corrida. Lo único que toca datos existentes es agregar `deposits.justification` (nullable) y `manifests.adjustment_amount` (default 0), ambos sin riesgo.

**3. `RolePermissionSeeder` NUNCA en producción** — tiene deltas manuales. `Adjust:Manifest` se crea con `CustomPermissionSeeder` y se asigna a mano desde Shield.
