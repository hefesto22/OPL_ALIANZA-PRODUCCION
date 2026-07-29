# Guion de pruebas — Sobrepago y ajuste de centavos

> Entorno: **pruebas.hozana.cloud** · Casos sembrados por `DepositCasesSeeder`
> Actualizado: 29/07/2026

---

## Lo que se prueba

Un depósito se aplica **íntegro** al manifiesto donde se registra. Puede superar el total: entonces se exige justificación, el manifiesto queda **sobrepagado**, y aun así se puede cerrar. Nada se mueve solo a otro manifiesto.

Los centavos que ningún depósito va a cubrir se resuelven con un **ajuste**, que alguien firma.

---

## Estado inicial

| # | Bodega | A depositar | Depositado | Diferencia | Rol |
|---|---|---|---|---|---|
| **950001** | OAC | 1,000.00 | 999.68 | **+0.32** | faltan centavos → ajuste positivo |
| **950002** | OAC | 500.00 | 500.01 | **−0.01** | sobrepago heredado, sin justificación |
| **950003** | OAC | 800.00 | 0.00 | +800.00 | manifiesto de trabajo |
| **950004** | OAS | 300.00 | 300.00 | 0.00 · **CERRADO** | control |

---

## T1 · Depositar de más

1. Entrá al **950003** → **Registrar Depósito** → monto **850.00** (debe 800.00).
2. Aparece el campo **Justificación del depósito en exceso**. Guardá vacío → debe rechazarlo.
3. Escribí `nada` (4 caracteres) → debe pedirte al menos 15.
4. Escribí una justificación real y guardá.

**Esperado:**

- El aviso dice `HNL 850.00 — BANCO · Sobrepago de HNL 50.00`.
- El resumen financiero muestra **(=) Sobrepago −L 50.00** en ámbar, no en rojo.
- **Aparece el botón Cerrar Manifiesto.**

---

## T2 · Cerrar con sobrepago

Dale a **Cerrar Manifiesto**. El modal debe avisar:

> *Este manifiesto tiene un SOBREPAGO de HNL 50.00. Se cerrará dejando constancia del exceso y de la justificación del depósito.*

Confirmá. En **Registros de Actividad** deben quedar dos eventos del canal `finance`: *Depósito registrado por encima del total* y *Manifiesto cerrado con sobrepago*.

---

## T3 · Ajuste de un faltante de centavos

1. Abrí el **950001**: Diferencia **+L 0.32**, sin botón Cerrar.
2. Botón **Ajustar Diferencia**, prellenado en `0.32`. Motivo obligatorio.
3. Guardá.

**Esperado:** Depositado **sigue en 999.68** (plata real intacta), aparece **(−) Ajuste L 0.32**, la Diferencia queda en **0.00** y sale el botón Cerrar.

---

## T4 · Ajuste de un sobrepago heredado

1. Abrí el **950002**: Sobrepago **−L 0.01**, y **no** tiene botón Cerrar — porque ese depósito no lleva justificación.
2. **Ajustar Diferencia**, prellenado en `-0.01`. Motivo y guardar.

**Esperado:** Diferencia **0.00** y aparece Cerrar.

> Este es el caso real de producción: 4 manifiestos generados por el margen de tolerancia del código viejo, que dejaba pasar un centavo sin pedir explicación. Con el sistema actual ya no se pueden crear.

---

## T5 · Lo que NO debe pasar

**El manifiesto cerrado (950004)** no debe ofrecer Registrar Depósito ni Ajustar Diferencia.

**El tope del ajuste.** En el 950003 después de T1 (sobrepago de 50.00), el botón Ajustar **no** aparece: cincuenta lempiras no son un redondeo. El tope es L 1.00.

---

## Reiniciar los casos

```bash
cd /var/www/hozana-pruebas
DEPOSIT_CASES_RESET=1 php artisan db:seed --class=DepositCasesSeeder --force
```

---

## Resumen

| Prueba | Qué valida |
|---|---|
| T1 | Depositar de más con justificación obligatoria |
| T2 | Que el sobrepago no deje el manifiesto trabado |
| T3 | Ajuste de faltante sin inflar "Depositado" |
| T4 | Los 4 manifiestos heredados de producción |
| T5 | Cerrado protegido y tope del ajuste |
