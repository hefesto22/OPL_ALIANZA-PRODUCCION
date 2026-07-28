# Guion de pruebas — Depósitos multi-manifiesto y ajuste de centavos

> Entorno: **pruebas.hozana.cloud** · Manifiestos sembrados por `DepositCasesSeeder` (rango 95xxxx)
> Fecha: 28/07/2026

---

## Estado inicial tras sembrar

Verificá que el listado de manifiestos muestre exactamente esto antes de empezar. Si algún número no coincide, avisá antes de seguir — el resto del guion depende de estos saldos.

| # | Bodega | Días atrás | A depositar | Depositado | Diferencia | Rol |
|---|---|---|---|---|---|---|
| **950005** | OAC | 28 | 1,000.00 | 999.50 | **+0.50** | candidato FIFO 1º |
| **950006** | OAC | 25 | 500.00 | 499.75 | **+0.25** | candidato FIFO 2º |
| **950001** | OAC | 20 | 1,000.00 | 999.68 | **+0.32** | candidato FIFO 3º |
| **950002** | OAC | 15 | 500.00 | 500.01 | **−0.01** | solo se cierra con ajuste |
| **950008** | OAC | 12 | 300.00 | 300.00 | 0.00 · **CERRADO** | nunca debe recibir dinero |
| **950004** | OAS | 10 | 300.00 | 0.00 | +300.00 | aislamiento entre bodegas |
| **950009** | OAC + OAS | 8 | 700.00 | 0.00 | +700.00 | multi-bodega |
| **950010** | OAC | 5 | 1,000.00 | 995.00 | **+5.00** | supera el tope de ajuste |
| **950003** | OAC | hoy | 800.00 | 0.00 | +800.00 | manifiesto de trabajo |
| **950007** | OAC | hoy | 200.00 | 0.00 | +200.00 | sobredepósito extremo |

**Los centavos pendientes de OAC suman 0.50 + 0.25 + 0.32 = 1.07.** Ese es el número que hace toda la demo.

> ⚠️ Corré las pruebas **en orden**. Cada una deja el estado que la siguiente espera.

---

## T1 · Una transferencia limpia cuatro manifiestos

*Es el caso exacto que pidió el cliente: no quiere hacer varias transferencias.*

1. Entrá al **950003** → **Registrar Depósito**.
2. Monto: **801.07**.
3. Al salir del campo debe aparecer el desglose:

   | Manifiesto | Monto |
   |---|---|
   | #950003 *(este manifiesto)* | 800.00 |
   | #950005 | 0.50 |
   | #950006 | 0.25 |
   | #950001 | 0.32 |

4. Intentá guardar **sin** escribir justificación → **debe rechazarlo**.
5. Escribí la justificación y guardá.

**Resultado esperado:** los cuatro manifiestos (950003, 950005, 950006, 950001) quedan en **0.00** y muestran el botón **Cerrar**. Ninguno tenía botón Cerrar antes.

✅ **Esta es la prueba principal.** Si sale bien, la funcionalidad hace lo que el cliente pidió.

---

## T2 · No se cruza plata entre bodegas

Abrí el **950004** (bodega OAS). Debe seguir intacto en **+300.00**.

El depósito de T1 salió de un manifiesto de OAC; un manifiesto exclusivo de OAS no puede recibir nada de ahí.

---

## T3 · Un manifiesto cerrado nunca recibe dinero

Abrí el **950008**. Sigue **cerrado**, en 0.00, y su pestaña **Dinero Aplicado** no tiene líneas nuevas.

---

## T4 · El centavo que sobra — lo que el reparto NO puede arreglar

*Un manifiesto sobre-depositado no se arregla con más dinero: ya tiene de más.*

1. Abrí el **950002**: Diferencia **−0.01**, sin botón Cerrar.
2. Debe aparecer el botón **Ajustar Diferencia**, prellenado con `-0.01`.
3. Escribí un motivo (mínimo 10 caracteres) y guardá.

**Resultado esperado:** Diferencia **0.00**, aparece el chip **Ajuste** en el resumen financiero, y ahora sí el botón **Cerrar**. En Registros de Actividad debe quedar el ajuste con tu nombre y el motivo.

---

## T5 · El tope del ajuste protege de cuadrar a mano

Abrí el **950010**: Diferencia **+5.00**.

**El botón "Ajustar Diferencia" NO debe aparecer.** Cinco lempiras no son un redondeo bancario — eso es un depósito que falta, y el sistema no permite taparlo con un ajuste. El tope está en L 1.00 (`config/manifests.php`).

---

## T6 · Manifiesto multi-bodega

*Un manifiesto con facturas de OAC y OAS participa del reparto de las dos.*

1. Entrá al **950004** (OAS, pendiente 300.00) → **Registrar Depósito**.
2. Monto: **350.00**.
3. Desglose esperado:

   | Manifiesto | Monto |
   |---|---|
   | #950004 *(este manifiesto)* | 300.00 |
   | #950009 | 50.00 |

   El 950009 aparece porque comparte la bodega OAS, aunque también tenga facturas de OAC.
4. Justificá y guardá.

**Resultado esperado:** 950004 en **0.00**; 950009 baja de 700.00 a **650.00**.

---

## T7 · Sobredepósito por encima de todo lo pendiente

*El caso raro que el cliente mencionó: depositar más de lo que se debe.*

1. Entrá al **950007** (pendiente 200.00) → **Registrar Depósito**.
2. Monto: **1,000.00**.
3. Desglose esperado:

   | Manifiesto | Monto | |
   |---|---|---|
   | #950007 *(este manifiesto)* | 200.00 | |
   | #950009 | 650.00 | lo que le quedaba |
   | #950010 | 5.00 | queda cuadrado |
   | #950007 | 145.00 | marcado **excede el total — requiere justificación** |

   El total aplicado al 950007 queda en **345.00**.
4. Justificá y guardá.

**Resultado esperado:**
- 950009 y 950010 en **0.00** (el 950010 se arregló con dinero real, no con ajuste — que era lo que T5 impedía).
- **950007 en −145.00**, y **sin** botón "Ajustar Diferencia" (145 supera el tope). Se resuelve revirtiendo el depósito o cuando entre el manifiesto que corresponde.

---

## T8 · Trazabilidad

En cualquiera de los manifiestos que recibió dinero de otra boleta (por ejemplo el **950001** después de T1):

1. Abrí la pestaña **Dinero Aplicado**.
2. Debe listar de qué boleta salió cada lempira, con el número del manifiesto **desde el que se registró** marcado en color cuando es distinto.
3. En **Registros de Actividad** debe estar el evento del canal `finance` con el reparto completo y la justificación.

---

## Cómo volver a empezar

El seeder es idempotente: si los manifiestos ya existen, no los toca. Para repetir el guion desde cero hay que borrar los manifiestos del rango 95xxxx y volver a correr:

```bash
cd /var/www/hozana-pruebas
php artisan db:seed --class=DepositCasesSeeder --force
```

---

## Resumen de qué prueba cada caso

| Prueba | Qué valida | Por qué importa |
|---|---|---|
| T1 | Reparto FIFO a varios manifiestos | Es el pedido literal del cliente |
| T2 | Aislamiento entre bodegas | Que no se mezcle plata de OAC con OAS |
| T3 | Manifiesto cerrado protegido | Un cierre es definitivo |
| T4 | Ajuste de centavos | Los 4 manifiestos varados de producción |
| T5 | Tope del ajuste | Que nadie cuadre manifiestos a mano |
| T6 | Multi-bodega | Los manifiestos reales de la API son así |
| T7 | Sobredepósito con justificación | El caso raro pero posible |
| T8 | Auditoría | Responder "¿de dónde salió este dinero?" |
