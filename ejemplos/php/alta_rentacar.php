<?php
/**
 * Alta atómica de un contrato rent-a-car: contrato, vehículo, personas,
 * permisos de conducir y direcciones en UNA sola transacción.
 *
 * Si cualquier paso falla, no se persiste ningún registro. Por eso este endpoint
 * es preferible a encadenar cinco POST a mano.
 *
 * Ejecutar:
 *   SES_API_KEY=... SES_EST_ID=12 php alta_rentacar.php
 *
 * Datos inventados; letras de control incorrectas a propósito.
 */

declare(strict_types=1);

$BASE    = getenv('SES_BASE') ?: 'https://seshospedajes.es/app/api/v1';
$API_KEY = getenv('SES_API_KEY') ?: exit("Falta SES_API_KEY.\n");
$EST_ID  = getenv('SES_EST_ID')  ?: exit("Falta SES_EST_ID.\n");

function ses_llamar(string $metodo, string $ruta, ?array $cuerpo = null): array
{
    global $BASE, $API_KEY;

    $ch = curl_init($BASE . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => [
            'X-Api-Key: ' . $API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($cuerpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE));
    }

    $respuesta = curl_exec($ch);
    if ($respuesta === false) {
        exit('Error de red: ' . curl_error($ch) . "\n");
    }
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$codigo, json_decode((string) $respuesta, true)];
}

function ses_mostrar_error(int $codigo, ?array $datos): void
{
    fwrite(STDERR, sprintf("HTTP %d  %s\n", $codigo, $datos['code'] ?? 'sin code'));
    fwrite(STDERR, ($datos['message'] ?? '') . "\n");
    foreach (($datos['fields'] ?? []) as $campo => $detalle) {
        // La ruta del campo (persons[1].tipo_documento) dice de qué persona se
        // queja. El Ministerio, cuando rechaza, no lo dice.
        fwrite(STDERR, sprintf("  · %s: %s\n", $campo, is_array($detalle) ? json_encode($detalle) : $detalle));
    }
}

// --- 1. Entorno --------------------------------------------------------------
[$codigo, $ping] = ses_llamar('GET', '/ping');
if ($codigo !== 200) {
    ses_mostrar_error($codigo, $ping);
    exit(1);
}

$entorno = $ping['env'] ?? 'desconocido';
echo "Entorno: {$entorno}\n";

if ($entorno !== 'sandbox' && getenv('SES_ALLOW_PRODUCTION') !== '1') {
    exit("ABORTADO: esto escribiría en producción y se comunicaría al Ministerio.\n"
       . "Si es lo que quieres: SES_ALLOW_PRODUCTION=1 php " . basename(__FILE__) . "\n");
}

// --- 2. Idempotencia ---------------------------------------------------------
$uuid = getenv('SES_UUID') ?: sprintf(
    '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0x0fff), random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
);
$referencia = 'RC-DEMO-' . date('YmdHis');
echo "uuid: {$uuid}   referencia: {$referencia}\n";

// --- 3. El sobre completo ----------------------------------------------------
$direccionMadrid = [
    'codigo_municipio' => '28079',   // 5 cifras del INE
    'nombre_municipio' => 'Madrid',
    'pais'             => 'ESP',     // ISO 3166-1 alpha-3
];

$contrato = [
    'uuid'     => $uuid,
    'contract' => [
        'establecimiento_id' => (int) $EST_ID,
        'referencia'         => $referencia,
        'fecha_contrato'     => '2026-06-25',
        'fecha_recogida'     => '2026-06-26 10:00:00',
        'fecha_devolucion'   => '2026-06-30 18:00:00',
        'tipo_pago'          => 'TARJETA',
        'fecha_pago'         => '2026-06-25',
        'medio_pago'         => 'VISA',
        'titular_pago'       => 'Marta Pruebas',
        'caducidad_tarjeta'  => '12/28',
    ],
    'vehicle' => [
        'categoria'       => 'TURISMO_M1',
        'tipo'            => 'TURISMO',
        // marca y color: MÁXIMO 10 CARACTERES cada uno. "MERCEDES-BENZ" (13) y
        // "Azul marino" (11) son los dos que más rebotan.
        'marca'           => 'SEAT',
        'modelo'          => 'Ibiza',
        'matricula'       => '0000BBB',
        'numero_bastidor' => 'ZZZZZZZZZZZZZ0000',
        'color'           => 'BLANCO',
        'km_recogida'     => 12030,
    ],
    'pickup_address'  => array_merge($direccionMadrid, ['direccion' => 'Aeropuerto T1, Mostrador 4', 'codigo_postal' => '28042']),
    'dropoff_address' => array_merge($direccionMadrid, ['direccion' => 'Aeropuerto T4, Devolucion', 'codigo_postal' => '28042']),
    'persons' => [
        [
            // Roles: TI titular, CP conductor principal, CS secundario.
            // Entre 1 y 3 personas, sin repetir rol.
            'rol'              => 'TI',
            'nombre'           => 'Marta',
            'apellido1'        => 'Pruebas',
            'apellido2'        => 'Ficticias',
            'tipo_documento'   => 'NIF',      // CIF si el titular es empresa
            'numero_documento' => '12345678A',
            'fecha_nacimiento' => '1988-04-17',
            'nacionalidad'     => 'ESP',
            'sexo'             => 'M',
            // Teléfono o correo: al menos uno, siempre.
            'telefono'         => '+34600000000',
            'correo'           => 'marta.pruebas@example.com',
            'license'          => ['tipo' => 'B', 'validez' => '2030-01-01', 'numero' => '000000001'],
            'address'          => array_merge($direccionMadrid, ['direccion' => 'Calle Inventada 1', 'codigo_postal' => '28013']),
        ],
        [
            'rol'              => 'CP',
            'nombre'           => 'Bruno',
            'apellido1'        => 'Ficticio',
            'apellido2'        => 'Muestra',
            'tipo_documento'   => 'NIF',
            'numero_documento' => '87654321B',
            'fecha_nacimiento' => '1990-07-08',
            'nacionalidad'     => 'ESP',
            'sexo'             => 'H',
            'correo'           => 'bruno.ficticio@example.com',
            'license'          => ['tipo' => 'B', 'validez' => '2031-06-01', 'numero' => '000000002'],
            'address'          => [
                'direccion'        => 'Avenida Imaginaria 100',
                'codigo_municipio' => '08019',
                'nombre_municipio' => 'Barcelona',
                'codigo_postal'    => '08018',
                'pais'             => 'ESP',
            ],
        ],
    ],
];

[$codigo, $creado] = ses_llamar('POST', '/rentacar/contracts', $contrato);

if ($codigo >= 400) {
    ses_mostrar_error($codigo, $creado);
    exit(1);
}

printf(
    "HTTP %d  contrato id=%s  vehículo=%s  personas=%s  mir_status=%s%s\n",
    $codigo,
    $creado['id'] ?? '?',
    $creado['vehicle_id'] ?? '?',
    implode(',', $creado['person_ids'] ?? []),
    $creado['mir_status'] ?? '?',
    !empty($creado['idempotent']) ? '  (ya existía: idempotente)' : ''
);

// mir_status: "sandbox" no se enviará nunca; "pending" se enviará de forma
// diferida. Si esperabas sandbox y lees pending, para y revisa la clave.
if (($creado['mir_status'] ?? '') === 'pending' && $entorno === 'sandbox') {
    fwrite(STDERR, "\n*** mir_status=pending con una clave de sandbox: avisa a soporte.\n");
}
