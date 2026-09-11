<?php
/**
 * Alta de un huésped de hospedaje en la API de SES.Hospedajes.
 *
 * Fichero autónomo: no necesita composer ni otros ficheros del repositorio.
 * Requiere las extensiones curl y json.
 *
 * Ejecutar:
 *   SES_API_KEY=... SES_EST_ID=12 php alta_huesped.php
 *
 * Los datos son inventados y la letra de control del documento es incorrecta
 * a propósito: ver ejemplos/README.md.
 */

declare(strict_types=1);

$BASE    = getenv('SES_BASE') ?: 'https://seshospedajes.es/app/api/v1';
$API_KEY = getenv('SES_API_KEY') ?: exit("Falta SES_API_KEY.\n");
$EST_ID  = getenv('SES_EST_ID')  ?: exit("Falta SES_EST_ID. Lo obtienes con GET /establishments.\n");

/**
 * Una llamada a la API. Devuelve [código HTTP, cuerpo decodificado].
 */
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
        // Un fallo de red no dice si el alta se guardó. Reintenta con el MISMO
        // uuid: si ya se había guardado, la API lo devuelve sin duplicarlo.
        exit('Error de red: ' . curl_error($ch) . "\n");
    }
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$codigo, json_decode((string) $respuesta, true)];
}

/** Imprime un error de la API de forma legible, con TODOS los campos. */
function ses_mostrar_error(int $codigo, ?array $datos): void
{
    fwrite(STDERR, sprintf("HTTP %d  %s\n", $codigo, $datos['code'] ?? 'sin code'));
    fwrite(STDERR, ($datos['message'] ?? '') . "\n");

    foreach (($datos['fields'] ?? []) as $campo => $detalle) {
        // 'fields' trae todos los errores, no solo el primero. Enséñaselos al
        // operador tal cual: la ruta indica la persona exacta del contrato.
        fwrite(STDERR, sprintf("  · %s: %s\n", $campo, is_array($detalle) ? json_encode($detalle) : $detalle));
    }
}

// -----------------------------------------------------------------------------
// 1. Comprobar el entorno ANTES de escribir nada.
// -----------------------------------------------------------------------------
[$codigo, $ping] = ses_llamar('GET', '/ping');
if ($codigo !== 200) {
    ses_mostrar_error($codigo, $ping);
    exit(1);
}

$entorno = $ping['env'] ?? 'desconocido';
echo "Entorno: {$entorno}  (userId {$ping['userId']})\n";

if ($entorno !== 'sandbox' && getenv('SES_ALLOW_PRODUCTION') !== '1') {
    exit("ABORTADO: esto escribiría en producción y se comunicaría al Ministerio.\n"
       . "Si es lo que quieres: SES_ALLOW_PRODUCTION=1 php " . basename(__FILE__) . "\n");
}

// -----------------------------------------------------------------------------
// 2. El uuid se genera ANTES de la primera llamada, no después.
// -----------------------------------------------------------------------------
$uuid = getenv('SES_UUID') ?: sprintf(
    '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0x0fff), random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
);
echo "uuid: {$uuid}   (repite con SES_UUID={$uuid} para ver la idempotencia)\n";

// -----------------------------------------------------------------------------
// 3. El alta.
// -----------------------------------------------------------------------------
$huesped = [
    'uuid'               => $uuid,
    'establecimiento_id' => (int) $EST_ID,
    'nombre'             => 'Marta',
    'apellido1'          => 'Pruebas',
    'apellido2'          => 'Ficticias',
    // Código de 5 caracteres del Ministerio: NIF, NIE, PAS, OTRO.
    // "PASAPORTE" (9 caracteres) no cabe y se rechaza.
    'tipo_documento'     => 'NIF',
    'numero_documento'   => '12345678A',
    'fecha_nacimiento'   => '1988-04-17',
    'nacionalidad'       => 'ESP',          // ISO 3166-1 alpha-3, no "ESPAÑA"
    'genero'             => 'F',
    // El Ministerio exige teléfono o correo aunque el esquema los dé por
    // opcionales. Manda al menos uno: ver docs/errores-ministerio.md.
    'contacto_email'     => 'marta.pruebas@example.com',
    'contacto_telefono'  => '+34600000000',
    'fecha_entrada'      => '2026-07-15',
    'hora_entrada'       => '15:00:00',
    'fecha_salida'       => '2026-07-18',
];

[$codigo, $creado] = ses_llamar('POST', '/guests', $huesped);

if ($codigo >= 400) {
    ses_mostrar_error($codigo, $creado);
    exit(1);
}

$id = $creado['id'] ?? ($creado['data']['id'] ?? null);
echo "HTTP {$codigo}  huésped id={$id}";
echo !empty($creado['idempotent']) ? "  (ya existía: idempotente)\n" : "\n";

// -----------------------------------------------------------------------------
// 4. Comprobar que el registro NO va camino del Ministerio.
//    Esto es lo que conviene copiar a tu suite de pruebas.
// -----------------------------------------------------------------------------
if ($id !== null) {
    [$codigo, $detalle] = ses_llamar('GET', "/guests/{$id}");
    $estado = $detalle['data']['enviado_ministerio'] ?? $detalle['enviado_ministerio'] ?? null;

    if ($estado === null) {
        echo "No se pudo leer enviado_ministerio en el detalle. Compruébalo a mano.\n";
    } else {
        echo match ((int) $estado) {
            2       => "enviado_ministerio=2 → registro de sandbox. Correcto.\n",
            0       => "\n*** enviado_ministerio=0: ESTE REGISTRO ESTÁ EN COLA DE ENVÍO AL\n"
                     . "*** MINISTERIO. Detén las pruebas y avisa a soporte con el id {$id}.\n",
            1       => "enviado_ministerio=1 → ya comunicado al Ministerio.\n",
            default => "enviado_ministerio={$estado} (inesperado).\n",
        };
    }
}
