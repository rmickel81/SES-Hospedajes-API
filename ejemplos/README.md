# Ejemplos

Alta de un huésped de hospedaje y alta de un contrato rent-a-car, en PHP, Node,
Python y curl. Cada fichero es **autónomo**: cópialo a tu proyecto y ajústalo. No
hay librería que instalar, ni dependencias entre ficheros.

## Configuración

Todos los ejemplos leen lo mismo del entorno. **Ninguno lleva credenciales
dentro.**

```bash
export SES_API_KEY='tu_clave'                                 # obligatoria
export SES_EST_ID='12'                                        # tu establecimiento
export SES_BASE='https://seshospedajes.es/app/api/v1'         # opcional
```

| Variable | Obligatoria | Para qué |
|---|---|---|
| `SES_API_KEY` | sí | Tu clave de API. |
| `SES_EST_ID` | sí (en las altas) | `establecimiento_id` de tu cuenta. Lo saca `GET /establishments`. |
| `SES_BASE` | no | Por defecto, producción. |
| `SES_UUID` | no | Fija el `uuid` del alta. Repite la llamada con el mismo valor para ver la idempotencia. |
| `SES_ALLOW_PRODUCTION` | no | Ponla a `1` para permitir que un ejemplo escriba en producción. |

## 🔴 Los ejemplos se niegan a escribir en producción

Cada ejemplo de alta llama primero a `GET /ping`, mira el campo `env` y **aborta
si no es `sandbox`**, salvo que pongas `SES_ALLOW_PRODUCTION=1`.

No es una formalidad. El sandbox de esta API comparte cuenta y base de datos con
producción; lo único que distingue un registro de prueba es un campo. Un ejemplo
que se ejecuta por error contra una clave de producción da de alta huéspedes
falsos en la cuenta de un cliente y los comunica al Ministerio del Interior.

Copia esa comprobación a tu propio código. Está explicada en
[docs/sandbox.md](../docs/sandbox.md).

## Ejecutar

```bash
# curl — se lee sin instalar nada
bash curl/00-ping.sh
bash curl/01-alta-huesped.sh
bash curl/02-alta-rentacar.sh

php  php/alta_huesped.php
php  php/alta_rentacar.php

node node/alta-huesped.mjs        # Node 18+ (fetch nativo)
node node/alta-rentacar.mjs

python3 python/alta_huesped.py    # solo biblioteca estándar
python3 python/alta_rentacar.py
```

Para comprobar los cuatro lenguajes de una vez, contra sandbox:

```bash
bash comprobar.sh
```

## Los datos de prueba son inventados a propósito

Las personas de estos ejemplos no existen, y **sus documentos llevan la letra de
control deliberadamente incorrecta**:

| Documento | Letra correcta | La que usamos |
|---|---|---|
| `12345678A` | `Z` | `A` |
| `87654321B` | `X` | `B` |

Así ningún ejemplo puede coincidir con el documento de una persona real, ni
acabar comunicado al Ministerio como si lo fuera. Los correos usan `example.com`,
reservado por la RFC 2606.

**Mantén ese criterio en tus pruebas.** Como el sandbox escribe en la base de
datos real, probar con el DNI de un compañero deja un dato personal real guardado
en producción.

> Si en algún momento la plataforma llega a validar la letra de control, estos
> ejemplos devolverán un `422` en ese campo. Es el precio correcto: mejor eso que
> repartir documentos válidos por una documentación pública.

## Qué hace cada ejemplo

1. Lee la configuración del entorno y aborta si falta algo.
2. `GET /ping` y comprueba el entorno.
3. Genera un `uuid` y lo imprime.
4. Hace el alta.
5. Si hay `422`, imprime **todos** los campos de `fields`, no solo el primero.
6. Consulta el detalle y comprueba que `enviado_ministerio` es `2`.

Ese punto 6 es el que conviene copiar.

## Licencia

[MIT](LICENSE). Cópialos, cámbialos y publícalos sin pedir permiso.
