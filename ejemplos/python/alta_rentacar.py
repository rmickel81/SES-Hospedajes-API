#!/usr/bin/env python3
"""Alta atómica de un contrato rent-a-car.

Contrato, vehículo, personas, permisos de conducir y direcciones en UNA sola
transacción. Si cualquier paso falla, no se persiste ningún registro. Por eso
este endpoint es preferible a encadenar cinco POST a mano.

Solo biblioteca estándar:
    SES_API_KEY=... SES_EST_ID=12 python3 alta_rentacar.py

Datos inventados; letras de control incorrectas a propósito.
"""

from __future__ import annotations

import datetime as dt
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
    fallar("Falta SES_EST_ID.")


def llamar(metodo: str, ruta: str, cuerpo: dict | None = None) -> tuple[int, dict]:
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
        crudo = error.read()
        try:
            return error.code, json.loads(crudo or b"{}")
        except json.JSONDecodeError:
            return error.code, {"message": crudo.decode(errors="replace")[:500]}
    except urllib.error.URLError as error:
        fallar(f"Error de red: {error.reason}")
        raise


def mostrar_error(codigo: int, datos: dict) -> None:
    print(f"HTTP {codigo}  {datos.get('code', 'sin code')}", file=sys.stderr)
    if datos.get("message"):
        print(datos["message"], file=sys.stderr)
    for campo, detalle in (datos.get("fields") or {}).items():
        # La ruta del campo (persons[1].tipo_documento) dice de qué persona se
        # queja. El Ministerio, cuando rechaza, no lo dice.
        print(f"  · {campo}: {detalle}", file=sys.stderr)


# --- 1. Entorno ---------------------------------------------------------------
codigo, ping = llamar("GET", "/ping")
if codigo != 200:
    mostrar_error(codigo, ping)
    raise SystemExit(1)

entorno = ping.get("env", "desconocido")
print(f"Entorno: {entorno}")

if entorno != "sandbox" and os.environ.get("SES_ALLOW_PRODUCTION") != "1":
    fallar(
        "ABORTADO: esto escribiría en producción y se comunicaría al Ministerio.\n"
        "Si es lo que quieres: SES_ALLOW_PRODUCTION=1 python3 alta_rentacar.py"
    )

# --- 2. Idempotencia ----------------------------------------------------------
uuid = os.environ.get("SES_UUID") or str(uuid_mod.uuid4())
referencia = "RC-DEMO-" + dt.datetime.now().strftime("%Y%m%d%H%M%S")
print(f"uuid: {uuid}   referencia: {referencia}")

# --- 3. El sobre completo -----------------------------------------------------
MADRID = {"codigo_municipio": "28079", "nombre_municipio": "Madrid", "pais": "ESP"}

contrato = {
    "uuid": uuid,
    "contract": {
        "establecimiento_id": int(EST_ID),
        "referencia": referencia,
        "fecha_contrato": "2026-06-25",
        "fecha_recogida": "2026-06-26 10:00:00",
        "fecha_devolucion": "2026-06-30 18:00:00",
        "tipo_pago": "TARJETA",
        "fecha_pago": "2026-06-25",
        "medio_pago": "VISA",
        "titular_pago": "Marta Pruebas",
        "caducidad_tarjeta": "12/28",
    },
    "vehicle": {
        "categoria": "TURISMO_M1",
        "tipo": "TURISMO",
        # marca y color: MÁXIMO 10 CARACTERES cada uno. "MERCEDES-BENZ" (13) y
        # "Azul marino" (11) son los dos que más rebotan.
        "marca": "SEAT",
        "modelo": "Ibiza",
        "matricula": "0000BBB",
        "numero_bastidor": "ZZZZZZZZZZZZZ0000",
        "color": "BLANCO",
        "km_recogida": 12030,
    },
    "pickup_address": {**MADRID, "direccion": "Aeropuerto T1, Mostrador 4", "codigo_postal": "28042"},
    "dropoff_address": {**MADRID, "direccion": "Aeropuerto T4, Devolucion", "codigo_postal": "28042"},
    "persons": [
        {
            # Roles: TI titular, CP conductor principal, CS secundario.
            # Entre 1 y 3 personas, sin repetir rol.
            "rol": "TI",
            "nombre": "Marta",
            "apellido1": "Pruebas",
            "apellido2": "Ficticias",
            "tipo_documento": "NIF",  # CIF si el titular es una empresa
            "numero_documento": "12345678A",
            "fecha_nacimiento": "1988-04-17",
            "nacionalidad": "ESP",
            # Aquí M = mujer. En /guests el campo `genero` usa M = masculino.
            "sexo": "M",
            # Teléfono o correo: al menos uno, siempre.
            "telefono": "+34600000000",
            "correo": "marta.pruebas@example.com",
            "license": {"tipo": "B", "validez": "2030-01-01", "numero": "000000001"},
            "address": {**MADRID, "direccion": "Calle Inventada 1", "codigo_postal": "28013"},
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
            "license": {"tipo": "B", "validez": "2031-06-01", "numero": "000000002"},
            "address": {
                "direccion": "Avenida Imaginaria 100",
                "codigo_municipio": "08019",
                "nombre_municipio": "Barcelona",
                "codigo_postal": "08018",
                "pais": "ESP",
            },
        },
    ],
}

codigo, creado = llamar("POST", "/rentacar/contracts", contrato)
if codigo >= 400:
    mostrar_error(codigo, creado)
    raise SystemExit(1)

sufijo = "  (ya existía: idempotente)" if creado.get("idempotent") else ""
print(
    f"HTTP {codigo}  contrato id={creado.get('id')}  "
    f"vehículo={creado.get('vehicle_id')}  "
    f"personas={','.join(str(p) for p in creado.get('person_ids', []))}  "
    f"mir_status={creado.get('mir_status')}{sufijo}"
)

# mir_status: "sandbox" no se enviará nunca; "pending" se enviará de forma
# diferida. Si esperabas sandbox y lees pending, para y revisa la clave.
if creado.get("mir_status") == "pending" and entorno == "sandbox":
    print("\n*** mir_status=pending con una clave de sandbox: avisa a soporte.", file=sys.stderr)
    raise SystemExit(1)
