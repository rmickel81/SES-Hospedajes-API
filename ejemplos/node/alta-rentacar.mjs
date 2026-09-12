/**
 * Alta atómica de un contrato rent-a-car: contrato, vehículo, personas,
 * permisos de conducir y direcciones en UNA sola transacción.
 *
 * Si cualquier paso falla, no se persiste ningún registro. Por eso este
 * endpoint es preferible a encadenar cinco POST a mano.
 *
 * Node 18 o superior, sin dependencias.
 *   SES_API_KEY=... SES_EST_ID=12 node alta-rentacar.mjs
 */

import { randomUUID } from 'node:crypto';

const BASE = process.env.SES_BASE ?? 'https://seshospedajes.es/app/api/v1';
const API_KEY = process.env.SES_API_KEY;
const EST_ID = process.env.SES_EST_ID;

function fallar(mensaje) {
  console.error(mensaje);
  process.exit(1);
}

if (!API_KEY) fallar('Falta SES_API_KEY.');
if (!EST_ID) fallar('Falta SES_EST_ID.');

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
    fallar(`Error de red: ${error.message}`);
  }
  const texto = await respuesta.text();
  let datos;
  try {
    datos = JSON.parse(texto);
  } catch {
    datos = { message: texto.slice(0, 500) };
  }
  return { status: respuesta.status, datos };
}

function mostrarError({ status, datos }) {
  console.error(`HTTP ${status}  ${datos?.code ?? 'sin code'}`);
  if (datos?.message) console.error(datos.message);
  for (const [campo, detalle] of Object.entries(datos?.fields ?? {})) {
    // La ruta del campo (persons[1].tipo_documento) dice de qué persona se
    // queja. El Ministerio, cuando rechaza, no lo dice.
    console.error(`  · ${campo}: ${typeof detalle === 'string' ? detalle : JSON.stringify(detalle)}`);
  }
}

// --- 1. Entorno --------------------------------------------------------------
const ping = await llamar('GET', '/ping');
if (ping.status !== 200) {
  mostrarError(ping);
  process.exit(1);
}

const entorno = ping.datos?.env ?? 'desconocido';
console.log(`Entorno: ${entorno}`);

if (entorno !== 'sandbox' && process.env.SES_ALLOW_PRODUCTION !== '1') {
  fallar(
    'ABORTADO: esto escribiría en producción y se comunicaría al Ministerio.\n' +
      'Si es lo que quieres: SES_ALLOW_PRODUCTION=1 node alta-rentacar.mjs',
  );
}

// --- 2. Idempotencia ---------------------------------------------------------
const uuid = process.env.SES_UUID ?? randomUUID();
const referencia = `RC-DEMO-${new Date().toISOString().replace(/\D/g, '').slice(0, 14)}`;
console.log(`uuid: ${uuid}   referencia: ${referencia}`);

// --- 3. El sobre completo ----------------------------------------------------
const madrid = { codigo_municipio: '28079', nombre_municipio: 'Madrid', pais: 'ESP' };

const contrato = {
  uuid,
  contract: {
    establecimiento_id: Number(EST_ID),
    referencia,
    fecha_contrato: '2026-06-25',
    fecha_recogida: '2026-06-26 10:00:00',
    fecha_devolucion: '2026-06-30 18:00:00',
    tipo_pago: 'TARJETA',
    fecha_pago: '2026-06-25',
    medio_pago: 'VISA',
    titular_pago: 'Marta Pruebas',
    caducidad_tarjeta: '12/28',
  },
  vehicle: {
    categoria: 'TURISMO_M1',
    tipo: 'TURISMO',
    // marca y color: MÁXIMO 10 CARACTERES cada uno. "MERCEDES-BENZ" (13) y
    // "Azul marino" (11) son los dos que más rebotan.
    marca: 'SEAT',
    modelo: 'Ibiza',
    matricula: '0000BBB',
    numero_bastidor: 'ZZZZZZZZZZZZZ0000',
    color: 'BLANCO',
    km_recogida: 12030,
  },
  pickup_address: { ...madrid, direccion: 'Aeropuerto T1, Mostrador 4', codigo_postal: '28042' },
  dropoff_address: { ...madrid, direccion: 'Aeropuerto T4, Devolucion', codigo_postal: '28042' },
  persons: [
    {
      // Roles: TI titular, CP conductor principal, CS secundario.
      // Entre 1 y 3 personas, sin repetir rol.
      rol: 'TI',
      nombre: 'Marta',
      apellido1: 'Pruebas',
      apellido2: 'Ficticias',
      tipo_documento: 'NIF', // CIF si el titular es una empresa
      numero_documento: '12345678A',
      fecha_nacimiento: '1988-04-17',
      nacionalidad: 'ESP',
      sexo: 'M', // aquí M = mujer (en /guests el campo `genero` usa M = masculino)
      // Teléfono o correo: al menos uno, siempre.
      telefono: '+34600000000',
      correo: 'marta.pruebas@example.com',
      license: { tipo: 'B', validez: '2030-01-01', numero: '000000001' },
      address: { ...madrid, direccion: 'Calle Inventada 1', codigo_postal: '28013' },
    },
    {
      rol: 'CP',
      nombre: 'Bruno',
      apellido1: 'Ficticio',
      apellido2: 'Muestra',
      tipo_documento: 'NIF',
      numero_documento: '87654321B',
      fecha_nacimiento: '1990-07-08',
      nacionalidad: 'ESP',
      sexo: 'H',
      correo: 'bruno.ficticio@example.com',
      license: { tipo: 'B', validez: '2031-06-01', numero: '000000002' },
      address: {
        direccion: 'Avenida Imaginaria 100',
        codigo_municipio: '08019',
        nombre_municipio: 'Barcelona',
        codigo_postal: '08018',
        pais: 'ESP',
      },
    },
  ],
};

const creado = await llamar('POST', '/rentacar/contracts', contrato);
if (creado.status >= 400) {
  mostrarError(creado);
  process.exit(1);
}

const d = creado.datos;
console.log(
  `HTTP ${creado.status}  contrato id=${d?.id}  vehículo=${d?.vehicle_id}  ` +
    `personas=${(d?.person_ids ?? []).join(',')}  mir_status=${d?.mir_status}` +
    (d?.idempotent ? '  (ya existía: idempotente)' : ''),
);

// mir_status: "sandbox" no se enviará nunca; "pending" se enviará de forma
// diferida. Si esperabas sandbox y lees pending, para y revisa la clave.
if (d?.mir_status === 'pending' && entorno === 'sandbox') {
  console.error('\n*** mir_status=pending con una clave de sandbox: avisa a soporte.');
  process.exitCode = 1;
}
