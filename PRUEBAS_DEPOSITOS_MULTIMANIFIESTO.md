# Guion de pruebas — Depósitos multi-manifiesto, barrido y sobrepago

> Entorno: **pruebas.hozana.cloud** · Manifiestos sembrados por `DepositCasesSeeder` (rango 95xxxx)
> Actualizado: 28/07/2026

---

## Las tres reglas que se están probando

**1. Una boleta puede cubrir varios manifiestos.** Si el monto supera el saldo del manifiesto, el excedente se aplica solo, empezando por el más antiguo.

**2. El barrido automático es angosto a propósito.** Solo cubre deudas de hasta **L 10.00** en manifiestos de **más de 15 días**. Una deuda grande no es un redondeo: se deposita explícitamente. Y un manifiesto reciente sigue en la conciliación del encargado, así que no se toca.

**3. Lo que no encuentra dónde aplicarse queda como sobrepago.** El manifiesto muestra cuánto se depositó de más, y se puede cerrar dejando constancia de la justificación.

Ambos umbrales viven en `config/manifests.php` y se cambian sin tocar código.

---

## Estado inicial tras sembrar

| # | Bodega | A depositar | Depositado | Diferencia | Rol en la prueba |
|---|---|---|---|---|---|
| **950005** | OAC | 1,000.00 | 999.50 | **+0.50** | barrido, 1º |
| **950006** | OAC | 500.00 | 499.75 | **+0.25** | barrido, 2º |
| **950001** | OAC | 1,000.00 | 999.68 | **+0.32** | barrido, 3º |
| **950002** | OAC | 500.00 | 500.01 | **−0.01** | sobrepago heredado, sin justificación |
| **950008** | OAC | 300.00 | 300.00 | 0.00 · **CERRADO** | nunca recibe nada |
| **950004** | OAS | 300.00 | 0.00 | +300.00 | aislamiento entre bodegas |
| **950009** | OAC + OAS | 700.00 | 696.00 | **+4.00** | multi-bodega |
| **950010** | OAC | 1,000.00 | 975.00 | **+25.00** | deuda vieja pero **grande** |
| **950003** | OAC | 800.00 | 0.00 | +800.00 | manifiesto de trabajo |
| **950007** | OAC | 200.00 | 0.00 | +200.00 | prueba del sobrepago |
| **950011** | OAC | 100.00 | 98.00 | **+2.00** | **reciente** (3 días) |

Las deudas barribles de OAC suman **0.50 + 0.25 + 0.32 = 1.07**. El 950009 (4.00) también es barrible pero queda más adelante en la cola. El 950010 (25.00) y el 950011 (reciente) **no lo son**.

> ⚠️ Corré las pruebas **en orden**. Cada una deja el estado que espera la siguiente.

---

## T1 · Una transferencia limpia cuatro manifiestos

*El caso que pidió el cliente: no quiere hacer varias transferencias.*

1. Entrá al **950003** → **Registrar Depósito** → monto **801.07**.
2. Al salir del campo debe aparecer el desglose:

   | Manifiesto | Monto |
   |---|---|
   | #950003 *(este manifiesto)* | 800.00 |
   | #950005 | 0.50 |
   | #950006 | 0.25 |
   | #950001 | 0.32 |

3. Guardá **sin** justificación → debe rechazarlo pidiendo al menos 15 caracteres.
4. Escribí la justificación y guardá.

**Esperado:** los cuatro quedan en **0.00** con botón Cerrar. Tres de ellos llevaban semanas trabados por centavos y nadie los tocó.

✅ **Prueba principal.**

---

## T2 · No se cruza plata entre bodegas

El **950004** (OAS puro) sigue en **+300.00**. El depósito salió de un manifiesto de OAC.

---

## T3 · Un manifiesto cerrado nunca recibe dinero

El **950008** sigue cerrado, en 0.00, sin líneas nuevas en su pestaña **Dinero Aplicado**.

---

## T4 · Una deuda vieja pero grande no se barre

El **950010** sigue en **+25.00**. Es de hace meses, así que la antigüedad no es el problema: son 25 lempiras, y eso no es un redondeo bancario. El sistema no lo tapa con el sobrante de otra boleta — hay que depositarlo.

**Y tampoco le aparece el botón "Ajustar Diferencia"**, porque el tope del ajuste es L 1.00. Un faltante real no se resuelve ni barriendo ni ajustando: se deposita.

---

## T5 · Un manifiesto reciente no se toca

El **950011** es de hace 3 días y le faltan L 2.00 — una deuda chiquita que el barrido cubriría de sobra si no fuera por la fecha. Sigue en **+2.00**.

Esto es deliberado: ese manifiesto está en la conciliación del encargado ahora mismo. Si el sistema se lo pagara solo con plata que él mandó para otra cosa, le desordenaría el trabajo y le haría desconfiar de los números.

---

## T6 · El centavo que sobra — lo que el barrido NO puede arreglar

1. Abrí el **950002**: Diferencia **−0.01**, sin botón Cerrar.
2. Aparece **Ajustar Diferencia**, prellenado en `-0.01`. Escribí un motivo y guardá.

**Esperado:** diferencia **0.00**, chip **Ajuste** en el resumen financiero, y ahora sí botón Cerrar. En Registros de Actividad queda el ajuste con tu nombre y el motivo.

> Este manifiesto viene del código viejo, cuando el validador dejaba pasar un centavo de más sin pedir explicación. **Con el sistema actual ya no se puede generar uno así**: todo sobrepago nace con justificación obligatoria. El ajuste existe para limpiar los que quedaron de antes — en producción hay 4.

---

## T7 · Manifiesto multi-bodega

*Los manifiestos reales de la API abarcan varias bodegas a la vez.*

1. Entrá al **950004** (OAS, debe 300.00) → Registrar Depósito → monto **304.00**.
2. Desglose esperado:

   | Manifiesto | Monto |
   |---|---|
   | #950004 *(este manifiesto)* | 300.00 |
   | #950009 | 4.00 |

   El 950009 aparece porque comparte la bodega OAS, aunque también tenga facturas de OAC.
3. Justificá y guardá.

**Esperado:** 950004 en **0.00**; 950009 en **0.00**.

---

## T8 · Sobrepago: cuando no hay dónde aplicar el excedente

1. Entrá al **950007** (debe 200.00) → Registrar Depósito → monto **250.00**.
2. En el desglose los 250 van **completos a este manifiesto**: ya no quedan deudas viejas y chicas que barrer.
3. Justificá y guardá.

**Esperado:**

- El manifiesto queda en **Sobrepago L 50.00**, en ámbar y no en rojo — porque sobrar no es lo mismo que faltar.
- **El botón Cerrar Manifiesto SÍ aparece.** Al cerrarlo, el modal te avisa del sobrepago y queda registrado en el canal `finance` con el monto y la justificación.
- El botón "Ajustar Diferencia" **no** aparece: 50 lempiras exceden el tope de L 1.00.

Así el manifiesto no se queda trabado para siempre en Activos, y a la vez queda constancia de dónde se depositó de más.

---

## T9 · Trazabilidad

En el **950001** (que recibió 0.32 de una boleta ajena en T1):

1. Pestaña **Dinero Aplicado**: muestra de qué boleta salió cada lempira, con el manifiesto de origen marcado cuando es distinto.
2. **Registros de Actividad**: el evento del canal `finance` con el reparto completo y la justificación.

---

## Cómo volver a empezar

```bash
cd /var/www/hozana-pruebas
DEPOSIT_CASES_RESET=1 php artisan db:seed --class=DepositCasesSeeder --force
```

El reset borra los casos y los vuelve a sembrar, recalculando los manifiestos ajenos que hubieran recibido excedente. Se niega a correr si una boleta externa aplicó dinero a los casos de prueba.

---

## Qué prueba cada caso

| Prueba | Qué valida | Por qué importa |
|---|---|---|
| T1 | Reparto a varios manifiestos | El pedido literal del cliente |
| T2 | Aislamiento entre bodegas | Que no se mezcle plata de OAC con OAS |
| T3 | Manifiesto cerrado protegido | Un cierre es definitivo |
| T4 | Tope del barrido | Un faltante real se deposita, no se tapa |
| T5 | Antigüedad mínima | No desordenar la conciliación en curso |
| T6 | Ajuste de centavos | Los 4 manifiestos heredados de producción |
| T7 | Multi-bodega | Los manifiestos reales de la API son así |
| T8 | Sobrepago cerrable | Que no se acumulen manifiestos trabados |
| T9 | Auditoría | Responder "¿de dónde salió este dinero?" |
