# Cómo contribuir

Gracias por pararte a leer esto. Este repositorio documenta la API de
seshospedajes.es; las correcciones de quien la usa de verdad son lo que lo
mantiene útil.

## Los issues están cerrados, y es a propósito

Detrás de esta API hay un equipo muy pequeño. Un issue abierto durante ocho meses
sin contestar le dice a un desarrollador que la API está abandonada, aunque no lo
esté. Preferimos no dar esa señal.

**Escribe al correo de contacto del [README](README.md#issues-y-contacto).** Se
lee.

Si en algún momento hay alguien que pueda comprometerse a contestar en un plazo,
se abrirán los issues y el plazo se dirá en el README.

## Qué es especialmente bienvenido

- **Un error del Ministerio que no esté en
  [docs/errores-ministerio.md](docs/errores-ministerio.md).** Es la página más
  valiosa del repositorio y crece con lo que os encontráis. Si has perdido una
  tarde con un rechazo incomprensible, cuéntalo: le ahorras esa tarde a otros.
- **Documentación que no coincide con lo que hace la API.** Con la petición y la
  respuesta que lo demuestren, si puedes (sin datos personales).
- **Un ejemplo que no funciona**, o que no funciona en tu versión del lenguaje.
- **Un lenguaje que falta** en `ejemplos/`.

## Las cuatro reglas

### 1. Ningún dato personal real. Ninguno.

Ni en un ejemplo, ni en un informe de error, ni en una captura. Ni nombres de
huéspedes, ni números de documento, ni matrículas, ni correos, ni teléfonos.

Las personas de los ejemplos son inventadas y sus documentos llevan **la letra de
control deliberadamente incorrecta**, para que no puedan coincidir con un
documento real:

| Documento | Letra correcta | La que usamos |
|---|---|---|
| `12345678A` | `Z` | `A` |
| `87654321B` | `X` | `B` |

Los correos usan `example.com` (RFC 2606). Mantén ese criterio.

### 2. Ninguna credencial

Ni claves de API, ni de las de pruebas. Los ejemplos leen la clave del entorno y
así debe seguir siendo. Si se te ha escapado una clave en un commit, dala por
comprometida: rótala desde el panel y avisa.

### 3. `openapi.yaml` manda

[`openapi.yaml`](openapi.yaml) es la única fuente de verdad. Si la documentación
y la especificación se contradicen, gana la especificación y se corrige la
documentación —no al revés.

Y el `openapi.yaml` de este repositorio es **una copia** del que publica la
plataforma en `seshospedajes.es/app/api/docs/`. Corregir aquí un error de la
especificación arregla la copia y deja el original mal: avisa por correo para que
se corrija en el origen y las dos versiones sigan diciendo lo mismo.

### 4. Aquí no va código de la plataforma

Este repositorio documenta la API, no la implementa. No van la implementación, ni
el esquema de la base de datos, ni los XSD del Ministerio —esos son suyos y se
descargan de su web.

## Ejemplos: se prueban antes

Todo ejemplo se ejecuta contra **sandbox** antes de publicarse. Un ejemplo que no
funciona es peor que no tener ejemplos: quien lo copia pierde el tiempo y
desconfía de todo lo demás.

```bash
cd ejemplos
bash comprobar.sh                                    # sintaxis, sin clave
SES_API_KEY=<clave de sandbox> SES_EST_ID=<id> \
  bash comprobar.sh                                  # además los ejecuta
```

El comprobador se niega a ejecutar nada si la clave no es de sandbox. Cada
ejemplo, por su cuenta, hace lo mismo.

Si añades un ejemplo en un lenguaje nuevo, que siga el mismo guion: leer la
configuración del entorno, comprobar `env` antes de escribir, generar el `uuid`
antes de la primera llamada, imprimir **todos** los campos de `fields` cuando hay
un `422`, y comprobar `enviado_ministerio` al terminar.

## Estilo

Español, tuteando al lector, y desde su punto de vista: «esto te va a pasar y así
se evita», mejor que «el sistema presenta la siguiente casuística».

Frases cortas. Si una tabla lo explica mejor que un párrafo, tabla.

## Seguridad

Si encuentras un fallo de seguridad, escribe **en privado** al correo del
[README](README.md#issues-y-contacto). No lo publiques en un canal abierto hasta
que esté corregido.

## Licencia de lo que aportes

La documentación se publica bajo [CC BY 4.0](LICENSE) y los ejemplos bajo
[MIT](ejemplos/LICENSE). Al contribuir aceptas que tu aportación se publique bajo
la licencia que corresponda al fichero que tocas.
