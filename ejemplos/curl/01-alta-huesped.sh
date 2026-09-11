#!/usr/bin/env bash
# Alta de un huésped de hospedaje.
# Datos inventados; la letra de control del documento es incorrecta a propósito.
set -euo pipefail

BASE="${SES_BASE:-https://seshospedajes.es/app/api/v1}"
: "${SES_API_KEY:?Falta SES_API_KEY.}"
: "${SES_EST_ID:?Falta SES_EST_ID. Lo obtienes con GET /establishments.}"

# --- Guarda de entorno: no escribir en producción sin decirlo ---------------
entorno=$(curl -sS "$BASE/ping" -H "X-Api-Key: $SES_API_KEY" \
          | tr -d ' ' | grep -o '"env":"[^"]*"' | cut -d'"' -f4)
echo "Entorno: ${entorno:-desconocido}"

if [ "$entorno" != "sandbox" ] && [ "${SES_ALLOW_PRODUCTION:-0}" != "1" ]; then
  echo "ABORTADO: esto escribiría en producción y se comunicaría al Ministerio."
  echo "Si es lo que quieres: SES_ALLOW_PRODUCTION=1 $0"
  exit 1
fi

# --- Idempotencia: genera el uuid ANTES de llamar ---------------------------
uuid="${SES_UUID:-$(uuidgen 2>/dev/null || cat /proc/sys/kernel/random/uuid)}"
echo "uuid: $uuid   (repite con SES_UUID=$uuid para ver la idempotencia)"

cuerpo=$(cat <<JSON
{
  "uuid": "$uuid",
  "establecimiento_id": $SES_EST_ID,
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
}
JSON
)

echo "--- POST /guests ---"
curl -sS -w '\nHTTP %{http_code}\n' -X POST "$BASE/guests" \
  -H "X-Api-Key: $SES_API_KEY" \
  -H 'Content-Type: application/json' \
  -d "$cuerpo"

# Con la respuesta en la mano, consulta el detalle y confirma que
# enviado_ministerio vale 2 (sandbox). Un 0 significa que está en cola de envío:
#   curl -sS "$BASE/guests/<id>" -H "X-Api-Key: $SES_API_KEY"
