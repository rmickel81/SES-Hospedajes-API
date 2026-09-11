# SES.Hospedajes API

Documentación pública de la API REST de [seshospedajes.es](https://seshospedajes.es)
para integradores: PMS hoteleros, ERPs y sistemas propios de rent-a-car que
necesiten registrar huéspedes y contratos de alquiler y comunicarlos al
Ministerio del Interior (SES.Hospedajes / VUD).

> **Este repositorio documenta la API. No la implementa.**
> No contiene código de la plataforma, ni el esquema de la base de datos, ni los
> XSD del Ministerio. Solo la especificación, la documentación y ejemplos de
> cliente que puedes copiar a tu proyecto.

---

## Empezar

```bash
curl -sS https://seshospedajes.es/app/api/v1/ping \
  -H "X-Api-Key: $SES_API_KEY"
```

```json
{ "ok": true, "ts": "2026-06-25T12:00:00+00:00", "userId": 123, "sandbox": true, "env": "sandbox" }
```

Si eso responde, ya tienes integración. El resto es rellenar campos.

- **[Arranque rápido](docs/arranque-rapido.md)** — conseguir la clave, primera llamada, primer huésped.
- **[Errores del Ministerio](docs/errores-ministerio.md)** — 🟢 lo que te va a pasar y cómo evitarlo. Empieza por aquí si vas a comunicar de verdad.
- **[Entorno de pruebas (sandbox)](docs/sandbox.md)** — 🔴 **léelo antes de usar una clave de sandbox.** No es un entorno separado.
- **[`openapi.yaml`](openapi.yaml)** — especificación OpenAPI 3.0.3. **Fuente de verdad.**
- **[Colección de Postman](postman/seshospedajes.postman_collection.json)** — importable tal cual.
- **[Ejemplos](ejemplos/)** — PHP, Node, Python y curl.

## Autenticación

Cabecera `X-Api-Key: <tu_clave>` en cada petición. Se aceptan también `Api-Key:`
y `?api_key=` en la query, esta última **solo para pruebas manuales**: acaba
escrita en los logs del servidor, en el historial del navegador y en la cabecera
`Referer`.

Requiere cuenta con plan Premium y la API habilitada. Las claves se generan desde
el panel de la cuenta.

## Las 17 rutas

Base: `https://seshospedajes.es/app/api/v1`

| Recurso | Rutas |
|---|---|
| Health | `GET /ping` |
| Establecimientos | `/establishments` · `/establishments/{id}` |
| Huéspedes (hospedaje) | `/guests` · `/guests/{id}` |
| Precheckins | `GET /precheckins` (solo lectura) |
| Contratos | `/contracts` · `/contracts/{id}` |
| **Rent-a-Car (alta atómica)** | `POST /rentacar/contracts` |
| Vehículos | `/vehicles` · `/vehicles/{id}` |
| Personas | `/persons` · `/persons/{id}` |
| Permisos de conducir | `/licenses` · `/licenses/{id}` |
| Direcciones | `/addresses` · `/addresses/{id}` |

Para rent-a-car, usa `POST /rentacar/contracts`: crea contrato, vehículo,
personas, permisos y direcciones en **una sola transacción**. Si falla cualquier
paso no se persiste nada. Los CRUDs sueltos existen para corregir después, no
para montar el alta a mano en cinco llamadas.

## Idempotencia: úsala

Todos los `POST` aceptan un `uuid` opcional. Si repites la llamada con el mismo
`uuid`, recibes `200 OK` con `"idempotent": true` y el registro que ya existía,
sin duplicar nada.

Genera el `uuid` **antes** de la primera llamada y reutilízalo en los reintentos.
Un timeout no te dice si el alta se guardó o no; el `uuid` sí.

Además hay claves naturales de respaldo:

| Endpoint | Clave natural |
|---|---|
| `POST /contracts` | `referencia` + cuenta |
| `POST /guests` | `numero_documento` + `fecha_entrada` + `establecimiento_id` |
| `POST /rentacar/contracts` | `uuid` del envoltorio, o `referencia` del contrato |

## Errores

Formato único para todos:

```json
{
  "ok": false,
  "code": "validation_error",
  "message": "Datos con formato inválido",
  "fields": {
    "persons[1].tipo_documento": "recibido \"PASAPORTE\"; debe ser uno de: NIF, NIE, PAS, OTRO, CIF",
    "vehicle.marca": "máximo 10 caracteres; llegaron 13: \"MERCEDES-BENZ\""
  }
}
```

`fields` trae **todos** los errores, no solo el primero, y la ruta dentro del
sobre cuando la petición es anidada. Enséñaselos a tu operador tal cual: la
alternativa es que te llame para decirte que «da error».

Los códigos (`auth_missing`, `plan_required`, `validation_error`…) están en la
tabla completa del [arranque rápido](docs/arranque-rapido.md#códigos-de-error).

## Límites de uso

Hoy no se aplican límites estrictos por defecto, pero **escribe el cliente como
si los hubiera**: están previstos por clave de API, con `429` y cabeceras
`X-RateLimit-*`. Un cliente que ya trata el `429` no se entera del cambio.

- Máximo 5 peticiones simultáneas por clave.
- Reintentos con backoff exponencial ante `5xx` y `429`: empieza en 1 s, dobla, tope 60 s.
- Reutiliza el `uuid` en cada reintento.
- Cargas iniciales de más de 10.000 registros: por lotes, con pausa entre lotes.

Detrás hay un servidor compartido con el panel de todos los clientes. Un bucle
sin freno no te perjudica solo a ti.

## Versionado y cambios

La versión va en la ruta (`/v1`). Los cambios se anotan en
[`CHANGELOG.md`](CHANGELOG.md). Versión documentada aquí: **1.2.0**.

## Issues y contacto

Los issues están **cerrados a propósito**. Detrás de esta API hay un equipo muy
pequeño, y un issue abierto sin contestar durante ocho meses dice de una API algo
peor que no tener issues.

Para dudas de integración, errores en esta documentación o una clave de sandbox:

**[CORREO-DE-CONTACTO]**

<!--
  PENDIENTE ANTES DE ANUNCIAR (§6 paso 9): sustituir [CORREO-DE-CONTACTO] por un
  buzón real y atendido. Sin esto el repositorio no debe anunciarse: el §5 del
  plan pide un correo visible precisamente porque los issues están cerrados.
-->

Si encuentras un fallo de seguridad, escribe a esa dirección en privado. No
abras un canal público para eso.

## Licencia

- Documentación y especificación: [CC BY 4.0](LICENSE).
- Ejemplos de `ejemplos/`: [MIT](ejemplos/LICENSE) — cópialos a tu proyecto sin pedir permiso.
