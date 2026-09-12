#!/usr/bin/env bash
# Comprobación de conectividad, autenticación y entorno.
# La primera llamada de cualquier integración.
set -euo pipefail

BASE="${SES_BASE:-https://seshospedajes.es/app/api/v1}"
: "${SES_API_KEY:?Falta SES_API_KEY. Exporta tu clave antes de ejecutar.}"

respuesta=$(curl -sS "$BASE/ping" -H "X-Api-Key: $SES_API_KEY")
echo "$respuesta"

entorno=$(printf '%s' "$respuesta" | tr -d ' ' | grep -o '"env":"[^"]*"' | cut -d'"' -f4)

case "$entorno" in
  sandbox)    echo "→ SANDBOX. Los registros NO se envían al Ministerio." ;;
  production) echo "→ PRODUCCIÓN. Todo lo que crees se comunicará de verdad." ;;
  *)          echo "→ No se pudo leer 'env'. Revisa la clave."; exit 1 ;;
esac
