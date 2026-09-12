#!/usr/bin/env bash
# Alta atómica de un contrato rent-a-car: contrato, vehículo, personas,
# permisos y direcciones en una sola transacción.
# Datos inventados; letras de control incorrectas a propósito.
set -euo pipefail

BASE="${SES_BASE:-https://seshospedajes.es/app/api/v1}"
: "${SES_API_KEY:?Falta SES_API_KEY.}"
: "${SES_EST_ID:?Falta SES_EST_ID.}"

entorno=$(curl -sS "$BASE/ping" -H "X-Api-Key: $SES_API_KEY" \
          | tr -d ' ' | grep -o '"env":"[^"]*"' | cut -d'"' -f4)
echo "Entorno: ${entorno:-desconocido}"

if [ "$entorno" != "sandbox" ] && [ "${SES_ALLOW_PRODUCTION:-0}" != "1" ]; then
  echo "ABORTADO: esto escribiría en producción y se comunicaría al Ministerio."
  echo "Si es lo que quieres: SES_ALLOW_PRODUCTION=1 $0"
  exit 1
fi

uuid="${SES_UUID:-$(uuidgen 2>/dev/null || cat /proc/sys/kernel/random/uuid)}"
referencia="RC-DEMO-$(date +%Y%m%d%H%M%S)"
echo "uuid: $uuid   referencia: $referencia"

# marca y color: máximo 10 caracteres cada uno (límite del Ministerio).
# pais y nacionalidad: ISO 3166-1 alpha-3. codigo_municipio: 5 cifras del INE.
cuerpo=$(cat <<JSON
{
  "uuid": "$uuid",
  "contract": {
    "establecimiento_id": $SES_EST_ID,
    "referencia": "$referencia",
    "fecha_contrato": "2026-06-25",
    "fecha_recogida": "2026-06-26 10:00:00",
    "fecha_devolucion": "2026-06-30 18:00:00",
    "tipo_pago": "TARJETA",
    "fecha_pago": "2026-06-25",
    "medio_pago": "VISA",
    "titular_pago": "Marta Pruebas",
    "caducidad_tarjeta": "12/28"
  },
  "vehicle": {
    "categoria": "TURISMO_M1",
    "tipo": "TURISMO",
    "marca": "SEAT",
    "modelo": "Ibiza",
    "matricula": "0000BBB",
    "numero_bastidor": "ZZZZZZZZZZZZZ0000",
    "color": "BLANCO",
    "km_recogida": 12030
  },
  "pickup_address": {
    "direccion": "Aeropuerto T1, Mostrador 4",
    "codigo_municipio": "28079",
    "nombre_municipio": "Madrid",
    "codigo_postal": "28042",
    "pais": "ESP"
  },
  "dropoff_address": {
    "direccion": "Aeropuerto T4, Devolucion",
    "codigo_municipio": "28079",
    "nombre_municipio": "Madrid",
    "codigo_postal": "28042",
    "pais": "ESP"
  },
  "persons": [
    {
      "rol": "TI",
      "nombre": "Marta",
      "apellido1": "Pruebas",
      "apellido2": "Ficticias",
      "tipo_documento": "NIF",
      "numero_documento": "12345678A",
      "fecha_nacimiento": "1988-04-17",
      "nacionalidad": "ESP",
      "sexo": "M",
      "telefono": "+34600000000",
      "correo": "marta.pruebas@example.com",
      "license": { "tipo": "B", "validez": "2030-01-01", "numero": "000000001" },
      "address": {
        "direccion": "Calle Inventada 1",
        "codigo_municipio": "28079",
        "nombre_municipio": "Madrid",
        "codigo_postal": "28013",
        "pais": "ESP"
      }
    },
    {
      "rol": "CP",
      "nombre": "Bruno",
      "apellido1": "Ficticio",
      "apellido2": "Muestra",
      "tipo_documento": "NIF",
      "numero_documento": "87654321B",
      "fecha_nacimiento": "1990-07-08",
      "nacionalidad": "ESP",
      "sexo": "H",
      "correo": "bruno.ficticio@example.com",
      "license": { "tipo": "B", "validez": "2031-06-01", "numero": "000000002" },
      "address": {
        "direccion": "Avenida Imaginaria 100",
        "codigo_municipio": "08019",
        "nombre_municipio": "Barcelona",
        "codigo_postal": "08018",
        "pais": "ESP"
      }
    }
  ]
}
JSON
)

echo "--- POST /rentacar/contracts ---"
curl -sS -w '\nHTTP %{http_code}\n' -X POST "$BASE/rentacar/contracts" \
  -H "X-Api-Key: $SES_API_KEY" \
  -H 'Content-Type: application/json' \
  -d "$cuerpo"

# Respuesta: id, vehicle_id, person_ids, license_ids, address_ids y mir_status.
# mir_status = "sandbox" si la clave es de sandbox; "pending" en producción.
# Repetir con el mismo uuid devuelve 200 e "idempotent": true, sin duplicar.
