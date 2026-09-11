# Changelog

Cambios de la **API** de SES.Hospedajes. Los cambios de esta documentación que no
reflejen un cambio de la API van en el historial de git, no aquí.

La versión va en la ruta (`/v1`). Un cambio que rompa clientes existentes
estrenaría ruta (`/v2`); dentro de `/v1` solo se añade.

## v1.2.0 — 2026-06-25

- Nuevo endpoint atómico `POST /v1/rentacar/contracts`: contrato, vehículo,
  personas, permisos y direcciones en una sola transacción.
- Nuevos CRUDs: `/v1/vehicles`, `/v1/persons`, `/v1/licenses`, `/v1/addresses`.
- Idempotencia por `uuid` extendida a todos los recursos de rent-a-car.
- Modo sandbox: claves de API marcadas como sandbox, que ejercitan la API sin
  enviar datos al Ministerio del Interior. `GET /v1/ping` expone `env` y
  `sandbox`. Ver [docs/sandbox.md](docs/sandbox.md).
- Corregido un error en `GET /v1/establishments` al filtrar por `q`.
- Documentación reescrita y especificación OpenAPI 3.0.3 publicada.

## v1.1

- Filtros `q`, `from` y `to`, y paginación en todos los listados.
- Idempotencia en altas de contratos, huéspedes y establecimientos.
