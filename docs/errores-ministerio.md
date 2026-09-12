# Los errores del Ministerio que no están escritos en ninguna parte

Siete cosas que el servicio SES.Hospedajes del Ministerio del Interior hace y que
no vas a encontrar en su documentación, en su esquema, ni en ningún foro. Cada
una nos ha costado un incidente.

Están aquí para que a ti no te cuesten nada.

**Esta API comunica al Ministerio por ti**: no necesitas hablar con su servicio
SOAP. Pero los datos que nos mandas acaban allí, y sus reglas se aplican a lo que
tú escribes. Por eso la primera parte te afecta aunque nunca veas un XML.

- [Si mandas datos a esta API](#parte-a-si-mandas-datos-a-esta-api)
  - [1. `tipoDocumento` cabe en cinco caracteres](#1-tipodocumento-cabe-en-cinco-caracteres)
  - [2. El teléfono o el correo son obligatorios, aunque pongan que no](#2-el-teléfono-o-el-correo-son-obligatorios-aunque-pongan-que-no)
  - [3. Los límites de campo que todo el mundo se salta](#3-los-límites-de-campo-que-todo-el-mundo-se-salta)
- [Si además hablas con el Ministerio por tu cuenta](#parte-b-si-además-hablas-con-el-ministerio-por-tu-cuenta)
  - [4. `codigo 0` no significa «aceptado»](#4-codigo-0-no-significa-aceptado)
  - [5. El `codigoComunicacion` es tu única prueba](#5-el-codigocomunicacion-es-tu-única-prueba)
  - [6. El servicio no tiene listado](#6-el-servicio-no-tiene-listado)
  - [7. La etiqueta viene mal escrita](#7-la-etiqueta-viene-mal-escrita)

---

# Parte A: si mandas datos a esta API

## 1. `tipoDocumento` cabe en cinco caracteres

**Lo que pasa:** mandas `"PASAPORTE"` o `"DNI / NIF"` y el parte se rechaza días
después, con un error de esquema **en inglés** que no dice de qué persona del
contrato se queja. Si el contrato lleva tres conductores, a adivinar.

**Por qué:** el campo `tipoDocumento` del esquema del Ministerio es de tipo
`string5Type`. Cinco caracteres. `PASAPORTE` tiene nueve y `DNI / NIF` tiene
nueve. No caben.

**Valores válidos:**

| Código | Para |
|---|---|
| `NIF` | Documento nacional de identidad español. |
| `NIE` | Número de identidad de extranjero. |
| `PAS` | Pasaporte. |
| `CIF` | Titular que es una empresa (solo rent-a-car). |
| `OTRO` | Cualquier otro documento. |

**Qué hacer:** manda el código, no el nombre largo. Esta API acepta también `DNI`
y `PASAPORTE` y los traduce a `NIF` y `PAS` por compatibilidad, pero cualquier
otro valor se rechaza en el `422`, con el valor recibido en el mensaje:

```json
{ "fields": { "persons[1].tipo_documento": "recibido \"PASAPORTE\"; debe ser uno de: NIF, NIE, PAS, OTRO, CIF" } }
```

Mejor un `422` inmediato que te dice la persona exacta, que un rechazo del
Ministerio tres días después que no te lo dice.

> ⚠️ La referencia de endpoints publicada en
> `seshospedajes.es/app/api/docs/` todavía describe el `tipo_documento` de
> huésped como `DNI | NIE | PASAPORTE`. Los valores buenos son los de la tabla de
> arriba, que son los de [`openapi.yaml`](../openapi.yaml) y los del Ministerio.

## 2. El teléfono o el correo son obligatorios, aunque pongan que no

**Lo que pasa:** das de alta una persona sin teléfono ni correo. El esquema lo
valida sin protestar. El lote se rechaza al procesarse.

**Por qué:** en `tiposGenerales.xsd` los tres campos —`telefono`, `telefono2` y
`correo`— están declarados `minOccurs="0"`. Opcionales. Pero la especificación
oficial dice, para los tres:

> «Obligatorio incluir una de estas tres etiquetas: telefono, telefono2 o correo».

Es una regla que el Ministerio aplica **al procesar** el lote, no al validarlo
contra el esquema. Validar contra el XSD no la ve. Por eso pasa el control local
y revienta después.

**Qué hacer:** trátalo como obligatorio en tu formulario. Al menos uno de los
tres, siempre. Si el huésped no te da ninguno, no tienes un parte válido: tienes
un problema que descubrirás tarde.

## 3. Los límites de campo que todo el mundo se salta

Los que más se pasan, en orden de frecuencia:

| Campo | Límite | Lo que no cabe |
|---|---|---|
| Marca de vehículo | **10** caracteres | `MERCEDES-BENZ` son 13 |
| Color de vehículo | **10** caracteres | `Azul marino` son 11 |
| Nombre | 50 caracteres | |
| Número de documento | 15 caracteres | |
| Correo | 50 caracteres | |
| Nacionalidad y país | ISO 3166-1 **alpha-3** | `ESPAÑA` no; `ESP` sí |
| Código de municipio | 5 cifras del INE | `28079` = Madrid |

`MERCEDES-BENZ` y `Azul marino` son los dos casos reales que más veces hemos
visto rebotar. Diez caracteres es menos de lo que parece cuando el dato viene de
un desplegable que alguien rellenó sin pensar en el Ministerio.

**Qué hacer:** valida la longitud **en tu formulario**, donde el operador todavía
está mirando la pantalla y puede corregirlo. Esta API te devuelve un `422` con el
detalle:

```json
{ "fields": { "vehicle.marca": "máximo 10 caracteres; llegaron 13: \"MERCEDES-BENZ\"" } }
```

pero para entonces el cliente ya se ha ido del mostrador.

---

# Parte B: si además hablas con el Ministerio por tu cuenta

Esto no lo necesitas para usar esta API. Está aquí porque no está escrito en
ningún otro sitio, y a alguien le va a ahorrar una semana.

## 4. `codigo 0` no significa «aceptado»

**Lo que pasa:** recibes `codigo 0`, lo interpretas como éxito, marcas el parte
como comunicado y te olvidas. Meses después, en una inspección, resulta que no
constaba.

**Por qué:** el servicio es **asíncrono**, y `codigo 0` significa únicamente
«tu petición está bien formada». Nada más. El veredicto real viene en un bloque
`<resultado>` **del mismo mensaje**, con un `codigoEstado`:

| `codigoEstado` | Significado |
|---|---|
| **1** | Tramitado **sin** errores. El único que es un sí. |
| 2–5 | Estados intermedios y de rechazo. |
| **6** | Tramitado **con** errores en algunas comunicaciones. |

**El 6 es la trampa.** Dice «tramitado», lleva la palabra tramitado dentro, y es
un rechazo parcial: parte de tus comunicaciones no han entrado. Si tu código
busca la subcadena «tramitado» para decidir si fue bien, vas a dar por buenos
lotes que no lo son.

**Qué hacer:** lee el `<resultado>`, no el `codigo`. Trata **solo** el `1` como
éxito. Todo lo demás, incluido el 6, es «revisar a mano».

## 5. El `codigoComunicacion` es tu única prueba

Cada comunicación individual recibe un `codigoComunicacion` de **36 caracteres**.
Es el acuse de recibo de ese parte concreto —no del lote— y es **la única prueba
real ante una inspección**.

**Qué hacer:** guárdalo en tu base de datos, junto al registro, en cuanto lo
recibas. No en un log que rota a los treinta días. En la tabla, en una columna, con
el resto del parte.

El día que te pidan demostrar que comunicaste a un huésped concreto, ese código
de 36 caracteres es lo que tienes. Si no lo guardaste, no lo tienes: sigue
leyendo.

## 6. El servicio no tiene listado

**No existe** una operación de listado. No puedes preguntar «dame todo lo del
último mes», ni «dame lo del establecimiento X». Solo puedes preguntar por
**códigos de lote que ya tengas guardados**.

Esto significa que **lo que no anotes en su día no se puede consultar después**.
No hay recuperación, no hay export, no hay un panel donde mirarlo. El Ministerio
no te va a devolver lo que tú no apuntaste.

**Qué hacer:** persiste el código de lote y el `codigoComunicacion` de cada envío
en el momento del envío, en la misma transacción que el registro si puedes. Trata
esa escritura como parte del envío, no como un log.

## 7. La etiqueta viene mal escrita

El esquema declara la etiqueta `resultadoComunicacion`.

El servicio devuelve `resutadoComunicacion`. **Sin la «l».**

No es una errata de este documento. Es una errata del servicio, lleva años ahí, y
no va a cambiar por ti.

**Consecuencia:** si tu parser solo acepta la etiqueta que declara el esquema
—que es lo que hace cualquier parser generado automáticamente— leerás **cero
errores en todas las respuestas que traen errores**. Silencio absoluto, y todo
aparentemente correcto.

**Qué hacer:** acepta las dos grafías. Y no generes el cliente a partir del WSDL
sin revisarlo después.

```
resultadoComunicacion   ← la del esquema
resutadoComunicacion    ← la que llega de verdad
```

---

## Resumen

| # | Regla | Coste de no saberlo |
|---|---|---|
| 1 | `tipoDocumento` = 5 caracteres: `NIF`, `NIE`, `PAS`, `CIF`, `OTRO` | Rechazo en inglés, sin decir qué persona |
| 2 | Teléfono **o** correo, siempre | Rechazo al procesar, no al validar |
| 3 | Marca y color, 10 caracteres; país en ISO alpha-3 | Rebote con el cliente ya fuera |
| 4 | `codigo 0` ≠ aceptado; solo `codigoEstado 1` lo es | Partes no comunicados que crees comunicados |
| 5 | Guarda el `codigoComunicacion` de 36 caracteres | No poder probar nada en una inspección |
| 6 | No hay listado: lo no anotado se pierde | Idem, sin recuperación posible |
| 7 | Llega `resutadoComunicacion`, sin «l» | Cero errores leídos en respuestas con errores |

¿Te has encontrado con alguna más? Escribe al correo del
[README](../README.md#issues-y-contacto). Esta página es mejor cuanto más larga.
