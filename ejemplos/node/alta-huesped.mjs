/**
 * Alta de un huésped de hospedaje en la API de SES.Hospedajes.
 *
 * Node 18 o superior. Sin dependencias: usa fetch y crypto nativos.
 *
 * Ejecutar:
 *   SES_API_KEY=... SES_EST_ID=12 node alta-huesped.mjs
 *
 * Datos inventados; la letra de control del documento es incorrecta a
 * propósito (ver ejemplos/README.md).
 */

import { randomUUID } from 'node:crypto';

const BASE = process.env.SES_BASE ?? 'https://seshospedajes.es/app/api/v1';
const API_KEY = process.env.SES_API_KEY;
const EST_ID = process.env.SES_EST_ID;

if (!API_KEY) fallar('Falta SES_API_KEY.');
if (!EST_ID) fallar('Falta SES_EST_ID. Lo obtienes con GET /establishments.');

function fallar(mensaje) {
  console.error(mensaje);
  process.exit(1);
}

/** Una llamada a la API. Devuelve { status, datos }. */
async function llamar(metodo, ruta, cuerpo) {
  let respuesta;
  try {
    respuesta = await fetch(BASE + ruta, {
      method: metodo,
      headers: {
        'X-Api-Key': API_KEY,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo),
      signal: AbortSignal.timeout(30_000),
    });
  } catch (error) {
    // Un fallo de red no dice si el alta se guardó. Reintenta con el MISMO
    // uuid: si ya estaba, la API lo devuelve sin duplicarlo.
    fallar(`Error de red: ${error.message}`);
  }

  const texto = await respuesta.text();
  let datos = null;
  try {
    datos = JSON.parse(texto);
  } catch {
    datos = { message: texto.slice(0, 500) };
  }
  return { status: respuesta.status, datos };
}

/** Imprime un error de la API con TODOS los campos, no solo el primero. */
function mostrarError({ status, datos }) {
  console.error(`HTTP ${status}  ${datos?.code ?? 'sin code'}`);
  if (datos?.message) console.error(datos.message);
  for (const [campo, detalle] of Object.entries(datos?.fields ?? {})) {
    console.error(`  · ${campo}: ${typeof detalle === 'string' ? detalle : JSON.stringify(detalle)}`);
  }
}

// --- 1. Comprobar el entorno ANTES de escribir nada --------------------------
const ping = await llamar('GET', '/ping');
if (ping.status !== 200) {
  mostrarError(ping);
  process.exit(1);
}

const entorno = ping.datos?.env ?? 'desconocido';
console.log(`Entorno: ${entorno}  (userId ${ping.datos?.userId})`);

if (entorno !== 'sandbox' && process.env.SES_ALLOW_PRODUCTION !== '1') {
  fallar(
    'ABORTADO: esto escribiría en producción y se comunicaría al Ministerio.\n' +
      'Si es lo que quieres: SES_ALLOW_PRODUCTION=1 node alta-huesped.mjs',
  );
}

// --- 2. El uuid se genera ANTES de la primera llamada ------------------------
const uuid = process.env.SES_UUID ?? randomUUID();
console.log(`uuid: ${uuid}   (repite con SES_UUID=${uuid} para ver la idempotencia)`);

// --- 3. El alta --------------------------------------------------------------
const huesped = {
  uuid,
  establecimiento_id: Number(EST_ID),
  nombre: 'Marta',
  apellido1: 'Pruebas',
  apellido2: 'Ficticias',
  // Código de 5 caracteres del Ministerio: NIF, NIE, PAS, OTRO.
  // "PASAPORTE" (9 caracteres) no cabe y se rechaza.
  tipo_documento: 'NIF',
  numero_documento: '12345678A',
  fecha_nacimiento: '1988-04-17',
  nacionalidad: 'ESP', // ISO 3166-1 alpha-3, no "ESPAÑA"
  genero: 'F', // en huéspedes: M | F | Otro (en /persons el campo es `sexo`: H | M | O)
  // El Ministerio exige teléfono o correo aunque el esquema los dé por
  // opcionales: ver docs/errores-ministerio.md.
  contacto_email: 'marta.pruebas@example.com',
  contacto_telefono: '+34600000000',
  fecha_entrada: '2026-07-15',
  hora_entrada: '15:00:00',
  fecha_salida: '2026-07-18',
};

const creado = await llamar('POST', '/guests', huesped);
if (creado.status >= 400) {
  mostrarError(creado);
  process.exit(1);
}

const id = creado.datos?.id ?? creado.datos?.data?.id ?? null;
console.log(
  `HTTP ${creado.status}  huésped id=${id}` +
    (creado.datos?.idempotent ? '  (ya existía: idempotente)' : ''),
);

// --- 4. Comprobar que el registro NO va camino del Ministerio -----------------
//     Esto es lo que conviene copiar a tu suite de pruebas.
if (id !== null) {
  const detalle = await llamar('GET', `/guests/${id}`);
  const estado =
    detalle.datos?.data?.enviado_ministerio ?? detalle.datos?.enviado_ministerio ?? null;

  if (estado === null) {
    console.log('No se pudo leer enviado_ministerio en el detalle. Compruébalo a mano.');
  } else if (Number(estado) === 2) {
    console.log('enviado_ministerio=2 → registro de sandbox. Correcto.');
  } else if (Number(estado) === 0) {
    console.error(
      `\n*** enviado_ministerio=0: ESTE REGISTRO ESTÁ EN COLA DE ENVÍO AL\n` +
        `*** MINISTERIO. Detén las pruebas y avisa a soporte con el id ${id}.`,
    );
    process.exitCode = 1;
  } else if (Number(estado) === 1) {
    console.log('enviado_ministerio=1 → ya comunicado al Ministerio.');
  } else {
    console.log(`enviado_ministerio=${estado} (inesperado).`);
  }
}
