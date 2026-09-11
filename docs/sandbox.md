# Entorno de pruebas (sandbox)

> 🔴 **Lee esta página entera antes de usar una clave de sandbox.**
> El sandbox de esta API no es lo que normalmente llamamos un sandbox. Si das por
> hecho el comportamiento habitual, vas a meter datos de prueba en la cuenta real
> de un cliente.

## Qué es exactamente

El sandbox **es una marca en tu clave de API**. Nada más.

No es un servidor distinto. No es una base de datos distinta. No es una cuenta
distinta. Es la misma API, la misma base de datos y la misma cuenta, con una
única diferencia de comportamiento:

> Los registros creados con una clave de sandbox se marcan con
> `enviado_ministerio = 2` y **nunca se envían al Ministerio del Interior**.

Todo lo demás es real:

| | Sandbox |
|---|---|
| URL | La misma: `https://seshospedajes.es/app/api/v1` |
| Cuenta | La misma cuenta del cliente |
| Base de datos | La misma, las mismas tablas |
| Registros creados | **Reales y permanentes** |
| Identificadores | Reales, del mismo contador que producción |
| Visibles en el panel del cliente | **Sí**, junto a los datos de verdad |
| Validaciones (`422`) | Las mismas |
| Envío al Ministerio | **No, nunca** |

## Las tres consecuencias que importan

### 1. Tus datos de prueba conviven con los reales

Un huésped que crees para probar aparece en el panel del cliente, en el mismo
listado, entre sus huéspedes de verdad. No hay una pestaña «pruebas». No
desaparece solo.

Si haces una carga de prueba de doscientos huéspedes un martes por la tarde, el
cliente ve doscientos huéspedes nuevos un martes por la tarde.

**Habla con el cliente antes de probar contra su cuenta.** Y limpia después:

```bash
curl -sS -X DELETE "$SES_BASE/contracts/987" -H "X-Api-Key: $SES_API_KEY"
```

Borrar un registro de sandbox no afecta a producción.

### 2. Los datos de prueba son datos reales en una base de datos real

Como el registro se persiste de verdad, **todo lo que escribas queda escrito**.

No uses personas reales para probar. Ni un huésped que se alojó de verdad, ni un
compañero de oficina, ni tú mismo con tu DNI bueno. En el momento en que lo
mandas, hay un dato personal real guardado en una base de datos de producción sin
una base legal que lo ampare.

Usa personas inventadas. Los [ejemplos](../ejemplos/) de este repositorio ya van
así, con documentos cuya letra de control es deliberadamente incorrecta para que
no puedan coincidir con un documento real.

### 3. El sandbox no prueba la parte del Ministerio

Es la limitación que más sorprende: como los registros de sandbox **no se envían
nunca**, el sandbox no puede decirte si el Ministerio habría aceptado tus datos.

Lo que **sí** se prueba en sandbox:

- Autenticación, rutas, verbos y forma de las respuestas.
- Las validaciones de esta API: los `422` con su `fields`.
- Idempotencia, paginación, filtros.
- El camino de error: campos que faltan, formatos malos, reenvíos.

Lo que **no** se prueba en sandbox:

- Que el Ministerio acepte el parte.
- Las reglas que el Ministerio aplica al procesar y no al validar —la del
  teléfono o correo obligatorio, por ejemplo. Están en
  [errores-ministerio.md](errores-ministerio.md).
- La transición `enviado_ministerio` de `0` a `1`.

Por eso el primer alta en producción hay que mirarla. Repasa
[errores-ministerio.md](errores-ministerio.md) antes de cambiar de clave: el
sandbox no te va a avisar de nada de aquello.

## Cómo saber siempre contra qué estás hablando

### Antes de empezar: `GET /ping`

```bash
curl -sS "$SES_BASE/ping" -H "X-Api-Key: $SES_API_KEY"
```

```json
{ "ok": true, "userId": 123, "sandbox": true, "env": "sandbox" }
```

`env` es `"sandbox"` o `"production"`. Es la forma fiable de saberlo, y la única.
Por el aspecto de la clave no se distingue.

**Muestra `env` en la pantalla de tu aplicación**, donde el operador lo vea sin
buscarlo. No en un log: en la pantalla.

### Al crear: `mir_status`

`POST /rentacar/contracts` devuelve `mir_status`:

| Valor | Significado |
|---|---|
| `pending` | Producción. Se enviará al Ministerio, de forma diferida. |
| `sandbox` | Clave de sandbox. **No se enviará nunca.** |

### Después de crear: `enviado_ministerio`

Consulta el detalle del registro con `GET /contracts/{id}` o `GET /guests/{id}`:

| Valor | Significado |
|---|---|
| `0` | Pendiente de envío al Ministerio. |
| `1` | Enviado. |
| `2` | Registro de sandbox. No se enviará nunca. |

## 🔴 El protocolo de verificación

La separación entre pruebas y producción es **un valor en un campo**, no un muro.
Un campo puede quedarse sin poner. Compruébalo tú, que te cuesta una llamada:

1. **Al abrir la sesión de pruebas**, llama a `GET /ping` y confirma que
   `env` es `"sandbox"`. Si no lo es, para.
2. **Tras crear el primer registro de cada sesión**, consulta su detalle y
   confirma que `enviado_ministerio` es **`2`**.
3. **Si ves un `0`** en un registro creado con una clave de sandbox: **detén las
   pruebas inmediatamente**. Ese registro está en la cola de envío al Ministerio
   del Interior. Escribe al correo del
   [README](../README.md#issues-y-contacto) indicando el `id` del registro,
   cuanto antes: mientras siga en `0`, el envío puede salir.

El paso 2 son tres líneas de código en tu suite de pruebas y es la diferencia
entre enterarte en el momento o enterarte cuando el Ministerio conteste.

## Qué hacer en producción el primer día

1. Cambia la clave por la de producción.
2. `GET /ping` → confirma `env: "production"`.
3. Da de alta **un** registro real. Uno.
4. Consulta su detalle hasta ver `enviado_ministerio: 1`.
5. Solo entonces, abre el grifo.

## Nota sobre la especificación

[`openapi.yaml`](../openapi.yaml) describe `enviado_ministerio` con los valores
`0` y `1`. El valor `2` es real y es el que marca los registros de sandbox: está
documentado en la referencia publicada en `seshospedajes.es/app/api/docs/` y es el
que devuelve la API. La spec se corregirá; mientras tanto, cuenta con los tres
valores.
