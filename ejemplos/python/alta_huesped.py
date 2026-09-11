#!/usr/bin/env python3
"""Alta de un huésped de hospedaje en la API de SES.Hospedajes.

Solo biblioteca estándar: no hace falta instalar nada.

Ejecutar:
    SES_API_KEY=... SES_EST_ID=12 python3 alta_huesped.py

Los datos son inventados y la letra de control del documento es incorrecta a
propósito (ver ejemplos/README.md).
"""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request
import uuid as uuid_mod

BASE = os.environ.get("SES_BASE", "https://seshospedajes.es/app/api/v1")
API_KEY = os.environ.get("SES_API_KEY")
EST_ID = os.environ.get("SES_EST_ID")


def fallar(mensaje: str) -> None:
    print(mensaje, file=sys.stderr)
    raise SystemExit(1)


if not API_KEY:
    fallar("Falta SES_API_KEY.")
if not EST_ID:
    fallar("Falta SES_EST_ID. Lo obtienes con GET /establishments.")


def llamar(metodo: str, ruta: str, cuerpo: dict | None = None) -> tuple[int, dict]:
    """Una llamada a la API. Devuelve (código HTTP, cuerpo decodificado)."""
    datos = json.dumps(cuerpo).encode() if cuerpo is not None else None
    peticion = urllib.request.Request(
        BASE + ruta,
        data=datos,
        method=metodo,
        headers={
            "X-Api-Key": API_KEY,
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(peticion, timeout=30) as respuesta:
            return respuesta.status, json.loads(respuesta.read() or b"{}")
    except urllib.error.HTTPError as error:
        # Los 4xx traen cuerpo JSON con el detalle: hay que leerlo, no descartarlo.
        crudo = error.read()
        try:
            return error.code, json.loads(crudo or b"{}")
        except json.JSONDecodeError:
            return error.code, {"message": crudo.decode(errors="replace")[:500]}
    except urllib.error.URLError as error:
        # Un fallo de red no dice si el alta se guardó. Reintenta con el MISMO
        # uuid: si ya estaba, la API lo devuelve sin duplicarlo.
        fallar(f"Error de red: {error.reason}")
        raise  # inalcanzable; para el analizador estático


def mostrar_error(codigo: int, datos: dict) -> None:
    """Imprime TODOS los campos de 'fields', no solo el primero."""
    print(f"HTTP {codigo}  {datos.get('code', 'sin code')}", file=sys.stderr)
    if datos.get("message"):
        print(datos["message"], file=sys.stderr)
    for campo, detalle in (datos.get("fields") or {}).items():
        print(f"  · {campo}: {detalle}", file=sys.stderr)


# --- 1. Comprobar el entorno ANTES de escribir nada ---------------------------
codigo, ping = llamar("GET", "/ping")
if codigo != 200:
    mostrar_error(codigo, ping)
    raise SystemExit(1)

entorno = ping.get("env", "desconocido")
print(f"Entorno: {entorno}  (userId {ping.get('userId')})")

if entorno != "sandbox" and os.environ.get("SES_ALLOW_PRODUCTION") != "1":
    fallar(
        "ABORTADO: esto escribiría en producción y se comunicaría al Ministerio.\n"
        "Si es lo que quieres: SES_ALLOW_PRODUCTION=1 python3 alta_huesped.py"
    )

# --- 2. El uuid se genera ANTES de la primera llamada -------------------------
uuid = os.environ.get("SES_UUID") or str(uuid_mod.uuid4())
print(f"uuid: {uuid}   (repite con SES_UUID={uuid} para ver la idempotencia)")

# --- 3. El alta ---------------------------------------------------------------
huesped = {
    "uuid": uuid,
    "establecimiento_id": int(EST_ID),
    "nombre": "Marta",
    "apellido1": "Pruebas",
    "apellido2": "Ficticias",
    # Código de 5 caracteres del Ministerio: NIF, NIE, PAS, OTRO.
    # "PASAPORTE" (9 caracteres) no cabe y se rechaza.
    "tipo_documento": "NIF",
    "numero_documento": "12345678A",
    "fecha_nacimiento": "1988-04-17",
    "nacionalidad": "ESP",  # ISO 3166-1 alpha-3, no "ESPAÑA"
    # En huéspedes: M | F | Otro. En /persons el campo es `sexo`: H | M | O.
    "genero": "F",
    # El Ministerio exige teléfono o correo aunque el esquema los dé por
    # opcionales: ver docs/errores-ministerio.md.
    "contacto_email": "marta.pruebas@example.com",
    "contacto_telefono": "+34600000000",
    "fecha_entrada": "2026-07-15",
    "hora_entrada": "15:00:00",
    "fecha_salida": "2026-07-18",
}

codigo, creado = llamar("POST", "/guests", huesped)
if codigo >= 400:
    mostrar_error(codigo, creado)
    raise SystemExit(1)

id_huesped = creado.get("id") or (creado.get("data") or {}).get("id")
sufijo = "  (ya existía: idempotente)" if creado.get("idempotent") else ""
print(f"HTTP {codigo}  huésped id={id_huesped}{sufijo}")

# --- 4. Comprobar que el registro NO va camino del Ministerio -----------------
#     Esto es lo que conviene copiar a tu suite de pruebas.
if id_huesped is not None:
    codigo, detalle = llamar("GET", f"/guests/{id_huesped}")
    cuerpo = detalle.get("data") if isinstance(detalle.get("data"), dict) else detalle
    estado = cuerpo.get("enviado_ministerio")

    if estado is None:
        print("No se pudo leer enviado_ministerio en el detalle. Compruébalo a mano.")
    elif int(estado) == 2:
        print("enviado_ministerio=2 → registro de sandbox. Correcto.")
    elif int(estado) == 0:
        print(
            "\n*** enviado_ministerio=0: ESTE REGISTRO ESTÁ EN COLA DE ENVÍO AL\n"
            f"*** MINISTERIO. Detén las pruebas y avisa a soporte con el id {id_huesped}.",
            file=sys.stderr,
        )
        raise SystemExit(1)
    elif int(estado) == 1:
        print("enviado_ministerio=1 → ya comunicado al Ministerio.")
    else:
        print(f"enviado_ministerio={estado} (inesperado).")
