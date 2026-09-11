# Arranque rápido

De cero a un huésped comunicado. Unos veinte minutos si ya tienes la cuenta.

- [1. Conseguir la clave](#1-conseguir-la-clave)
- [2. Comprobar contra qué entorno hablas](#2-comprobar-contra-qué-entorno-hablas)
- [3. Tu establecimiento](#3-tu-establecimiento)
- [4. Primer huésped](#4-primer-huésped)
- [5. Saber si llegó al Ministerio](#5-saber-si-llegó-al-ministerio)
- [6. Rent-a-car: el alta atómica](#6-rent-a-car-el-alta-atómica)
- [Formatos que se aplican en todas partes](#formatos-que-se-aplican-en-todas-partes)
- [Paginación](#paginación)
- [Códigos de error](#códigos-de-error)

## 1. Conseguir la clave

Requisitos: cuenta en seshospedajes.es con **plan Premium** y la API habilitada.

Desde el panel de la cuenta se genera una clave de producción y, opcionalmente,
una **clave de sandbox**.

> 🔴 Antes de pedir la clave de sandbox, lee **[sandbox.md](sandbox.md)**.
> Sandbox no es un entorno aparte: comparte cuenta y base de datos con
> producción. Saberlo cambia cómo montas las pruebas.

La clave va en la cabecera `X-Api-Key`. No la metas en el código: variable de
entorno, gestor de secretos, lo que uses para el resto de credenciales.

```bash
export SES_API_KEY='...'          # tu clave
export SES_BASE='https://seshospedajes.es/app/api/v1'
```

## 2. Comprobar contra qué entorno hablas

Siempre. Es la primera llamada de cualquier integración y la que evita el
accidente caro.

```bash
curl -sS "$SES_BASE/ping" -H "X-Api-Key: $SES_API_KEY"
```

```json
{
  "ok": true,
  "ts": "2026-06-25T12:00:00+00:00",
  "userId": 123,
  "sandbox": true,
  "env": "sandbox"
}
```

**Muéstrale `env` a tu operador en pantalla.** Si tu aplicación no dice en qué
entorno está trabajando, alguien acabará dando de alta huéspedes de prueba en
producción, o al revés: probando durante semanas contra una clave real.

## 3. Tu establecimiento

Casi todo cuelga de un `establecimiento_id`, que debe pertenecer a tu cuenta.

```bash
curl -sS "$SES_BASE/establishments?limit=50" -H "X-Api-Key: $SES_API_KEY"
```

```json
{ "ok": true, "data": [ { "id": 12, "nombre": "Hotel Ejemplo", "numero_establecimiento": "0000000001" } ],
  "page": 1, "limit": 20, "total": 1 }
```

Guarda el `id` en tu configuración. El `numero_establecimiento` es el código que
el Ministerio conoce; el `id` es el de esta API.

## 4. Primer huésped

```bash
curl -sS -X POST "$SES_BASE/guests" \
  -H "X-Api-Key: $SES_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{
    "uuid": "8d2e1c0a-7b21-4f0a-9a91-000000000001",
    "establecimiento_id": 12,
    "nombre": "Marta",
    "apellido1": "Pruebas",
    "apellido2": "Ficticias",
    "tipo_documento": "NIF",
    "numero_documento": "12345678A",
    "fecha_nacimiento": "1988-04-17",
    "nacionalidad": "ESP",
    "genero": "F",
    "contacto_email": "marta.pruebas@example.com",
    "contacto_telefono": "+34600000000",
    "fecha_entrada": "2026-07-15",
    "hora_entrada": "15:00:00",
    "fecha_salida": "2026-07-18"
  }'
```

Tres cosas que ahorran una tarde:

1. **`tipo_documento` es un código de cinco letras**: `NIF`, `NIE`, `PAS`,
   `OTRO`. `DNI` y `PASAPORTE` se aceptan y se traducen, pero cualquier otro
   valor se rechaza. El porqué está en
   [errores-ministerio.md](errores-ministerio.md#1-tipodocumento-cabe-en-cinco-caracteres).
2. **Manda `contacto_email` o `contacto_telefono`.** Los dos figuran como
   opcionales y no lo son de verdad:
   [errores-ministerio.md](errores-ministerio.md#2-el-teléfono-o-el-correo-son-obligatorios-aunque-pongan-que-no).
3. **Genera el `uuid` antes de llamar** y reutilízalo si reintentas. Un timeout
   no te dice si el alta se guardó.

## 5. Saber si llegó al Ministerio

El envío es asíncrono. El `201` significa «guardado aquí», no «comunicado».

```bash
curl -sS "$SES_BASE/guests/123" -H "X-Api-Key: $SES_API_KEY"
```

| `enviado_ministerio` | Significado |
|---|---|
| `0` | Pendiente de envío. |
| `1` | Enviado al Ministerio. |
| `2` | Registro de sandbox. **No se enviará nunca.** |

Consulta el detalle un rato después del alta para confirmar la transición a `1`.
No lo consultes en bucle cada segundo: el envío es diferido por diseño.

Y no confundas `1` con «aceptado sin errores». Ahí empieza
[errores-ministerio.md](errores-ministerio.md#4-codigo-0-no-significa-aceptado).

## 6. Rent-a-car: el alta atómica

Para alquiler de vehículo **no montes el alta a mano** con cinco llamadas. Usa
`POST /rentacar/contracts`: contrato, vehículo, personas, permisos y direcciones
en una sola transacción. Si algo falla, no se persiste nada.

```json
{
  "uuid": "8d2e1c0a-7b21-4f0a-9a91-000000000002",
  "contract": { "establecimiento_id": 12, "referencia": "RC-2026-0007", "...": "..." },
  "vehicle":  { "marca": "SEAT", "modelo": "Ibiza", "matricula": "0000BBB", "...": "..." },
  "pickup_address":  { "...": "..." },
  "dropoff_address": { "...": "..." },
  "persons": [ { "rol": "TI", "...": "..." } ]
}
```

Reglas del sobre:

- Entre **1 y 3 personas**. Roles: `TI` (titular), `CP` (conductor principal),
  `CS` (conductor secundario). **No se repiten roles** en el mismo contrato.
- El `establecimiento_id` debe ser de tu cuenta.
- `CIF` es un `tipo_documento` válido aquí, para titulares que son empresa.

Ejemplo completo y ejecutable en [`ejemplos/`](../ejemplos/), en los cuatro
lenguajes. El cuerpo íntegro está en
[`openapi.yaml`](../openapi.yaml), en el ejemplo `completo` de la ruta.

Respuesta:

```json
{
  "ok": true,
  "id": 987,
  "vehicle_id": 654,
  "person_ids": [321, 322],
  "license_ids": [211, 212],
  "address_ids": { "pickup": 41, "dropoff": 42 },
  "mir_status": "sandbox",
  "idempotent": false
}
```

`mir_status` es `pending` en producción y `sandbox` si la clave es de sandbox.
Si reintentas con el mismo `uuid`, llega `200` con `"idempotent": true` y los
identificadores del contrato que ya existía.

## ⚠️ `M` no significa lo mismo en los dos sitios

El sexo se codifica distinto según el recurso, y la letra `M` está en los dos:

| Recurso | Campo | Valores |
|---|---|---|
| Huésped (`/guests`) | `genero` | `M` = masculino · `F` = femenino · `Otro` |
| Persona (`/persons`, rent-a-car) | `sexo` | `H` = hombre · **`M` = mujer** · `O` |

Mandar `M` en `sexo` pensando en «masculino» no da error: da un dato mal, que
llega así al Ministerio. Si mapeas desde un único campo interno, escribe las dos
conversiones por separado y pon un test a cada una.

## Formatos que se aplican en todas partes

| Dato | Formato | Ejemplo |
|---|---|---|
| Fecha | `YYYY-MM-DD` | `2026-07-15` |
| Hora | `HH:MM:SS` (24 h) | `15:00:00` |
| Fecha y hora | `YYYY-MM-DD HH:MM:SS` | `2026-07-15 15:00:00` |
| Zona horaria | Siempre `Europe/Madrid` | — |
| País y nacionalidad | ISO 3166-1 **alpha-3** | `ESP`, `FRA`, `GBR` |
| Municipio | 5 dígitos del INE | `28079` |
| Correo | RFC 5322 | — |

`ESPAÑA` no es un país válido; `ESP` sí. Es el error de formato más repetido.

## Paginación

Todos los listados aceptan `page` (desde 1), `limit` (20 por defecto, 200 máximo),
`q`, `from` y `to`. `/vehicles` y `/persons` aceptan además `contract_id`.

```json
{ "ok": true, "data": [], "page": 1, "limit": 20, "total": 137 }
```

Pagina con `total`, no hasta que `data` venga vacío.

## Códigos de error

| HTTP | `code` | Qué pasó |
|---|---|---|
| 400 | `bad_json` | El cuerpo no es JSON válido. |
| 400 | `no_changes` | El `PUT`/`PATCH` no trae campos modificables. |
| 401 | `auth_missing` | Falta la cabecera de autenticación. |
| 401 | `auth_invalid` | Clave no encontrada. |
| 401 | `auth_inactive` | Clave desactivada. |
| 403 | `plan_required` | El plan de la cuenta no permite usar la API. |
| 403 | `api_disabled` | La API no está habilitada para la cuenta. |
| 403 | `forbidden` | El recurso no es de la cuenta autenticada. |
| 404 | `not_found` | El recurso o la ruta no existen. |
| 405 | `method_not_allowed` | Verbo no permitido en esa ruta. |
| 422 | `missing_fields` | Faltan campos obligatorios. Detalle en `fields`. |
| 422 | `validation_error` | Algún campo tiene formato inválido. Detalle en `fields`. |
| 500 | `server_error` | Error inesperado. Las altas atómicas no dejan registros a medias. |
| 500 | `db_connect` | Indisponibilidad temporal de la base de datos. |

`403 forbidden` suele ser un `establecimiento_id` de otra cuenta, no un problema
de permisos de la clave.

Los `422` traen **todos** los campos mal en `fields`, no solo el primero.
Enséñalos tal cual al operador.

## Siguiente paso

Lee **[errores-ministerio.md](errores-ministerio.md)**. Un `201` de esta API no
es el final del camino: el Ministerio tiene sus propias reglas, y varias no
están en ningún esquema.
