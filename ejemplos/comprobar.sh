#!/usr/bin/env bash
# Comprueba los ejemplos de este directorio.
#
#   bash comprobar.sh            → solo sintaxis (no necesita clave ni red)
#   SES_API_KEY=... SES_EST_ID=12 bash comprobar.sh
#                                → además los ejecuta contra SANDBOX
#
# Con clave, aborta si /ping no responde env=sandbox: estos ejemplos dan de
# alta registros, y en producción se comunican al Ministerio del Interior.
set -uo pipefail

cd "$(dirname "$0")"
fallos=0
ok()   { printf '  \033[32mOK\033[0m    %s\n' "$1"; }
malo() { printf '  \033[31mFALLO\033[0m %s\n' "$1"; fallos=$((fallos + 1)); }
hay()  { command -v "$1" >/dev/null 2>&1; }

echo "== Sintaxis =="
for f in curl/*.sh comprobar.sh; do
  bash -n "$f" && ok "$f" || malo "$f"
done
if hay php; then
  for f in php/*.php; do php -l "$f" >/dev/null && ok "$f" || malo "$f"; done
else echo "  (php no instalado, omitido)"; fi
if hay node; then
  for f in node/*.mjs; do node --check "$f" && ok "$f" || malo "$f"; done
else echo "  (node no instalado, omitido)"; fi
if hay python3; then
  for f in python/*.py; do python3 -m py_compile "$f" && ok "$f" || malo "$f"; done
  rm -rf python/__pycache__
else echo "  (python3 no instalado, omitido)"; fi

if [ -z "${SES_API_KEY:-}" ]; then
  echo
  echo "Sin SES_API_KEY: no se ha ejecutado nada contra la API."
  echo "Para la comprobación completa:"
  echo "  SES_API_KEY=<clave de sandbox> SES_EST_ID=<id> bash comprobar.sh"
  [ "$fallos" -eq 0 ] && echo "Sintaxis: todo correcto." || echo "$fallos fallo(s) de sintaxis."
  exit $((fallos > 0))
fi

# --- Guarda de entorno --------------------------------------------------------
BASE="${SES_BASE:-https://seshospedajes.es/app/api/v1}"
entorno=$(curl -sS "$BASE/ping" -H "X-Api-Key: $SES_API_KEY" \
          | tr -d ' ' | grep -o '"env":"[^"]*"' | cut -d'"' -f4)

echo
echo "== Ejecución contra la API =="
echo "Entorno: ${entorno:-desconocido}"

if [ "$entorno" != "sandbox" ]; then
  echo "ABORTADO: la clave no es de sandbox. No se ejecuta nada."
  echo "Estos ejemplos crean registros; en producción se comunican al Ministerio."
  exit 1
fi

: "${SES_EST_ID:?Falta SES_EST_ID para las altas.}"

correr() {
  local etiqueta="$1"; shift
  if salida=$("$@" 2>&1); then ok "$etiqueta"; else malo "$etiqueta"; fi
  printf '%s\n' "$salida" | sed 's/^/        /'
}

correr "curl   ping"          bash curl/00-ping.sh
correr "curl   huésped"       bash curl/01-alta-huesped.sh
correr "curl   rent-a-car"    bash curl/02-alta-rentacar.sh
hay php     && correr "php    huésped"    php  php/alta_huesped.php
hay php     && correr "php    rent-a-car" php  php/alta_rentacar.php
hay node    && correr "node   huésped"    node node/alta-huesped.mjs
hay node    && correr "node   rent-a-car" node node/alta-rentacar.mjs
hay python3 && correr "python huésped"    python3 python/alta_huesped.py
hay python3 && correr "python rent-a-car" python3 python/alta_rentacar.py

echo
if [ "$fallos" -eq 0 ]; then
  echo "Todo correcto. Los registros creados son de sandbox (enviado_ministerio=2)."
  echo "Bórralos si no los necesitas: DELETE /v1/contracts/{id}"
else
  echo "$fallos fallo(s). Revisa la salida de arriba antes de publicar."
fi
exit $((fallos > 0))
