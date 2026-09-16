<?php

declare(strict_types=1);

/**
 * SendPulse WhatsApp CTA → GHL Inbound Webhook
 *
 * Responsabilidades:
 * - Recibir el webhook generado por la acción de SendPulse.
 * - Validar origen, bot, teléfono y proyecto.
 * - Normalizar el teléfono.
 * - Transformar el arreglo de SendPulse en un objeto JSON plano.
 * - Enviar el contacto al Inbound Webhook de GHL.
 * - Conservar evidencia de QA en logs.
 *
 * No utiliza base de datos.
 */

date_default_timezone_set('America/Mexico_City');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/*
|--------------------------------------------------------------------------
| Configuración
|--------------------------------------------------------------------------
*/

/**
 * Mantén temporalmente el token que ya configuraste en SendPulse.
 * Después de concluir QA lo rotaremos.
 */
const WEBHOOK_TOKEN = '997f828027a3c91c9add83e4bb577bec156405aef85c532024053b2425653d3c';

/**
 * URL del trigger Inbound Webhook del workflow:
 * [QA] WhatsApp CTA - Multiproyecto
 */
const GHL_INBOUND_WEBHOOK_URL = 'https://services.leadconnectorhq.com/hooks/2cOAVW7auz2agTWyCnxF/webhook-trigger/e290ab59-dd75-4a31-9552-fa7884833d99';

/**
 * Bot maestro de Estrategia Urbana.
 */
const EXPECTED_SENDPULSE_BOT_ID = '699c711bc687e92ca105ad00';

const MAX_BODY_BYTES = 1048576; // 1 MB
const LOG_DIRECTORY  = __DIR__ . '/_logs';

/**
 * Durante QA conservaremos el body original.
 * Cambiar a false después de estabilizar la integración.
 */
const LOG_RAW_BODY = true;

/**
 * Si GHL falla, responderemos 200 a SendPulse para no interrumpir
 * la conversación ni impedir que se muestre el mensaje con botones.
 *
 * El fallo quedará registrado en el log para recuperación manual.
 */
const ACK_SENDPULSE_ON_GHL_FAILURE = true;

/*
|--------------------------------------------------------------------------
| Catálogo autorizado de proyectos
|--------------------------------------------------------------------------
*/

const PROJECTS = [
    'SENNSE TABACALERA' => [
        'project_key' => 'tabacalera',
    ],
    'SENNSE JUAREZ' => [
        'project_key' => 'juarez',
    ],
    'SENNSE LIVERPOOL' => [
        'project_key' => 'liverpool',
    ],
    'COVA CHILPANCINGO' => [
        'project_key' => 'chilpancingo',
    ],
    'SENNSE HAMBURGO' => [
        'project_key' => 'hamburgo',
    ],
    'SENNSE VASCONCELOS' => [
        'project_key' => 'vasconcelos',
    ],
];

/**
 * Variantes defensivas.
 *
 * SendPulse actualmente utiliza SENNSE JUAREZ sin acento como valor
 * canónico, pero aceptamos la variante acentuada para evitar fallos.
 */
const PROJECT_ALIASES = [
    'SENNSE JUÁREZ' => 'SENNSE JUAREZ',
];

/*
|--------------------------------------------------------------------------
| Utilidades
|--------------------------------------------------------------------------
*/

function jsonResponse(int $statusCode, array $payload): never
{
    http_response_code($statusCode);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * @return array<string, string>
 */
function getRequestHeadersSafe(): array
{
    $headers = [];

    if (function_exists('getallheaders')) {
        $receivedHeaders = getallheaders();

        if (is_array($receivedHeaders)) {
            foreach ($receivedHeaders as $name => $value) {
                $headers[(string) $name] = (string) $value;
            }
        }
    }

    foreach ($_SERVER as $key => $value) {
        if (!is_string($value) || !str_starts_with($key, 'HTTP_')) {
            continue;
        }

        $name = strtolower(substr($key, 5));
        $name = str_replace('_', '-', $name);

        $name = implode(
            '-',
            array_map(
                static fn(string $part): string => ucfirst($part),
                explode('-', $name)
            )
        );

        if (!array_key_exists($name, $headers)) {
            $headers[$name] = $value;
        }
    }

    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
    }

    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $headers['Content-Length'] = (string) $_SERVER['CONTENT_LENGTH'];
    }

    return $headers;
}

/**
 * @param array<string, string> $headers
 * @return array<string, string>
 */
function sanitizeHeaders(array $headers): array
{
    $blocked = [
        'authorization',
        'cookie',
        'set-cookie',
        'proxy-authorization',
        'x-api-key',
    ];

    $sanitized = [];

    foreach ($headers as $name => $value) {
        $sanitized[$name] = in_array(strtolower($name), $blocked, true)
            ? '[REDACTED]'
            : $value;
    }

    return $sanitized;
}

function writeDiagnosticLog(array $entry): void
{
    if (
        !is_dir(LOG_DIRECTORY)
        && !mkdir(LOG_DIRECTORY, 0750, true)
        && !is_dir(LOG_DIRECTORY)
    ) {
        throw new RuntimeException(
            'No fue posible crear el directorio de logs.'
        );
    }

    $logFile = sprintf(
        '%s/sendpulse_webhook_%s.log',
        LOG_DIRECTORY,
        date('Y-m-d')
    );

    $encoded = json_encode(
        $entry,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($encoded === false) {
        throw new RuntimeException(
            'No fue posible codificar la entrada del log.'
        );
    }

    $separator = PHP_EOL
        . str_repeat('=', 100)
        . PHP_EOL;

    $written = file_put_contents(
        $logFile,
        $encoded . $separator,
        FILE_APPEND | LOCK_EX
    );

    if ($written === false) {
        throw new RuntimeException(
            'No fue posible escribir el archivo de log.'
        );
    }
}

function normalizeProject(string $project): string
{
    $project = trim($project);
    $project = preg_replace('/\s+/', ' ', $project) ?? $project;
    $project = mb_strtoupper($project, 'UTF-8');

    return PROJECT_ALIASES[$project] ?? $project;
}

function normalizePhone(string $phone, string $country): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if ($digits === '') {
        return '';
    }

    $country = strtoupper(trim($country));

    /*
     * WhatsApp continúa enviando números mexicanos como:
     *
     * 521 + 10 dígitos
     *
     * GHL debe recibir:
     *
     * +52 + 10 dígitos
     */
    if (
        $country === 'MX'
        && strlen($digits) === 13
        && str_starts_with($digits, '521')
    ) {
        return '+52' . substr($digits, 3);
    }

    if (
        $country === 'MX'
        && strlen($digits) === 12
        && str_starts_with($digits, '52')
    ) {
        return '+' . $digits;
    }

    if ($country === 'MX' && strlen($digits) === 10) {
        return '+52' . $digits;
    }

    return '+' . $digits;
}

/**
 * @return array{
 *     status:int,
 *     body:string,
 *     error:string,
 *     decoded:mixed,
 *     duration_ms:int
 * }
 */
function postJson(string $url, array $payload): array
{
    $encodedPayload = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($encodedPayload === false) {
        throw new RuntimeException(
            'No fue posible codificar el payload hacia GHL.'
        );
    }

    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException(
            'No fue posible inicializar cURL.'
        );
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $encodedPayload,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
    ]);

    $startedAt = microtime(true);
    $response  = curl_exec($ch);
    $duration  = (int) round((microtime(true) - $startedAt) * 1000);

    $error  = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $body = is_string($response) ? $response : '';

    return [
        'status'      => $status,
        'body'        => $body,
        'error'       => $error,
        'decoded'     => json_decode($body, true),
        'duration_ms' => $duration,
    ];
}

/*
|--------------------------------------------------------------------------
| Request ID
|--------------------------------------------------------------------------
*/

try {
    $requestId = sprintf(
        'sp_%s_%s',
        date('Ymd_His'),
        bin2hex(random_bytes(5))
    );
} catch (Throwable) {
    $requestId = 'sp_' . date('Ymd_His') . '_' . uniqid();
}

/*
|--------------------------------------------------------------------------
| Validar método
|--------------------------------------------------------------------------
*/

$requestMethod = strtoupper(
    (string) ($_SERVER['REQUEST_METHOD'] ?? '')
);

if ($requestMethod !== 'POST') {
    header('Allow: POST');

    jsonResponse(405, [
        'ok'         => false,
        'error'      => 'method_not_allowed',
        'request_id' => $requestId,
    ]);
}

/*
|--------------------------------------------------------------------------
| Validar configuración
|--------------------------------------------------------------------------
*/

if (
    WEBHOOK_TOKEN === ''
    || WEBHOOK_TOKEN === 'COLOCA_AQUI_EL_TOKEN_ACTUAL'
    || GHL_INBOUND_WEBHOOK_URL === ''
    || GHL_INBOUND_WEBHOOK_URL === 'COLOCA_AQUI_LA_URL_DEL_INBOUND_WEBHOOK_DE_GHL'
) {
    jsonResponse(500, [
        'ok'         => false,
        'error'      => 'server_not_configured',
        'request_id' => $requestId,
    ]);
}

/*
|--------------------------------------------------------------------------
| Validar token
|--------------------------------------------------------------------------
*/

$receivedToken = trim((string) ($_GET['token'] ?? ''));

if (
    $receivedToken === ''
    || !hash_equals(WEBHOOK_TOKEN, $receivedToken)
) {
    jsonResponse(401, [
        'ok'         => false,
        'error'      => 'unauthorized',
        'request_id' => $requestId,
    ]);
}

/*
|--------------------------------------------------------------------------
| Leer body
|--------------------------------------------------------------------------
*/

$contentLength = isset($_SERVER['CONTENT_LENGTH'])
    ? (int) $_SERVER['CONTENT_LENGTH']
    : 0;

if ($contentLength > MAX_BODY_BYTES) {
    jsonResponse(413, [
        'ok'         => false,
        'error'      => 'payload_too_large',
        'request_id' => $requestId,
    ]);
}

$rawBody = file_get_contents('php://input');

if ($rawBody === false || trim($rawBody) === '') {
    jsonResponse(400, [
        'ok'         => false,
        'error'      => 'empty_body',
        'request_id' => $requestId,
    ]);
}

if (strlen($rawBody) > MAX_BODY_BYTES) {
    jsonResponse(413, [
        'ok'         => false,
        'error'      => 'payload_too_large',
        'request_id' => $requestId,
    ]);
}

try {
    $parsedBody = json_decode(
        $rawBody,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $exception) {
    jsonResponse(400, [
        'ok'         => false,
        'error'      => 'invalid_json',
        'message'    => $exception->getMessage(),
        'request_id' => $requestId,
    ]);
}

/*
|--------------------------------------------------------------------------
| Extraer evento de SendPulse
|--------------------------------------------------------------------------
*/

if (!is_array($parsedBody)) {
    jsonResponse(422, [
        'ok'         => false,
        'error'      => 'invalid_payload_structure',
        'request_id' => $requestId,
    ]);
}

/*
 * SendPulse entrega un arreglo:
 *
 * [
 *   {
 *      "service": "whatsapp",
 *      "bot": {...},
 *      "contact": {...}
 *   }
 * ]
 */
$event = isset($parsedBody[0]) && is_array($parsedBody[0])
    ? $parsedBody[0]
    : $parsedBody;

$service = strtolower(
    trim((string) ($event['service'] ?? ''))
);

$bot     = is_array($event['bot'] ?? null)
    ? $event['bot']
    : [];

$contact = is_array($event['contact'] ?? null)
    ? $event['contact']
    : [];

$botId = trim((string) ($bot['id'] ?? ''));

if ($service !== 'whatsapp') {
    jsonResponse(422, [
        'ok'         => false,
        'error'      => 'unsupported_service',
        'service'    => $service,
        'request_id' => $requestId,
    ]);
}

if ($botId !== EXPECTED_SENDPULSE_BOT_ID) {
    jsonResponse(422, [
        'ok'         => false,
        'error'      => 'unexpected_bot',
        'bot_id'     => $botId,
        'request_id' => $requestId,
    ]);
}

if ($contact === []) {
    jsonResponse(422, [
        'ok'         => false,
        'error'      => 'missing_contact',
        'request_id' => $requestId,
    ]);
}

/*
|--------------------------------------------------------------------------
| Extraer y normalizar datos
|--------------------------------------------------------------------------
*/

$variables = is_array($contact['variables'] ?? null)
    ? $contact['variables']
    : [];

$country = strtoupper(
    trim((string) ($contact['country'] ?? 'MX'))
);

$phoneRaw = trim((string) ($contact['phone'] ?? ''));
$phone    = normalizePhone($phoneRaw, $country);

$fullName = trim((string) ($contact['name'] ?? ''));

if ($fullName === '') {
    $fullName = 'Contacto WhatsApp';
}

$projectReceived = trim(
    (string) ($variables['proyecto_interes'] ?? '')
);

$project = normalizeProject($projectReceived);

if ($phone === '') {
    jsonResponse(422, [
        'ok'         => false,
        'error'      => 'missing_phone',
        'request_id' => $requestId,
    ]);
}

if ($project === '' || !isset(PROJECTS[$project])) {
    jsonResponse(422, [
        'ok'               => false,
        'error'            => 'unsupported_project',
        'project_received' => $projectReceived,
        'project_normalized' => $project,
        'request_id'       => $requestId,
    ]);
}

$lastMessage = trim(
    (string) ($contact['last_message'] ?? '')
);

$messageData = is_array($contact['last_message_data'] ?? null)
    ? $contact['last_message_data']
    : [];

$message = is_array($messageData['message'] ?? null)
    ? $messageData['message']
    : [];

$messageText = trim(
    (string) (
        $message['text']['body']
        ?? $lastMessage
    )
);

$messageId = trim(
    (string) (
        $messageData['message_id']
        ?? $message['id']
        ?? ''
    )
);

$sendPulseContactId = trim(
    (string) ($contact['id'] ?? '')
);

$projectConfig = PROJECTS[$project];

/*
|--------------------------------------------------------------------------
| Construir payload plano para GHL
|--------------------------------------------------------------------------
*/

$ghlPayload = [
    'event'                      => 'whatsapp_landing_intake',
    'request_id'                 => $requestId,

    'full_name'                  => $fullName,
    'phone'                      => $phone,
    'phone_raw'                  => $phoneRaw,
    'country'                    => $country,

    'project'                    => $project,
    'project_key'                => $projectConfig['project_key'],

    'source'                     => 'WhatsApp',
    'channel'                    => 'WhatsApp Inbound CTA',

    'initial_message'            => $messageText,

    'sendpulse_contact_id'       => $sendPulseContactId,
    'sendpulse_message_id'       => $messageId,
    'sendpulse_bot_id'           => $botId,
    'sendpulse_bot_phone'        => trim(
        (string) ($bot['external_id'] ?? '')
    ),

    'skip_sendpulse_first_touch' => true,

    'received_at'                => date(DATE_ATOM),
    'sendpulse_event_timestamp'  => isset($event['date'])
        ? (int) $event['date']
        : null,
];

/*
|--------------------------------------------------------------------------
| Enviar a GHL
|--------------------------------------------------------------------------
*/

$ghlResponse = postJson(
    GHL_INBOUND_WEBHOOK_URL,
    $ghlPayload
);

$ghlSuccess = (
    $ghlResponse['error'] === ''
    && $ghlResponse['status'] >= 200
    && $ghlResponse['status'] < 300
);

/*
|--------------------------------------------------------------------------
| Registrar evidencia
|--------------------------------------------------------------------------
*/

$logEntry = [
    'request_id'  => $requestId,
    'received_at' => date(DATE_ATOM),

    'request' => [
        'method'         => $requestMethod,
        'content_type'   => $_SERVER['CONTENT_TYPE'] ?? null,
        'content_length' => strlen($rawBody),
        'user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'headers'        => sanitizeHeaders(
            getRequestHeadersSafe()
        ),
    ],

    'sendpulse' => [
        'service'     => $service,
        'bot_id'      => $botId,
        'contact_id'  => $sendPulseContactId,
        'message_id'  => $messageId,
        'phone_raw'   => $phoneRaw,
        'project_raw' => $projectReceived,
        'raw_body'    => LOG_RAW_BODY
            ? $rawBody
            : null,
        'raw_sha256'  => hash('sha256', $rawBody),
    ],

    'normalized_payload' => $ghlPayload,

    'ghl' => [
        'success'     => $ghlSuccess,
        'status'      => $ghlResponse['status'],
        'error'       => $ghlResponse['error'],
        'duration_ms' => $ghlResponse['duration_ms'],
        'body'        => $ghlResponse['decoded']
            ?? $ghlResponse['body'],
    ],
];

try {
    writeDiagnosticLog($logEntry);
} catch (Throwable $exception) {
    error_log(
        sprintf(
            '[SendPulse CTA] request_id=%s log_error=%s',
            $requestId,
            $exception->getMessage()
        )
    );
}

/*
|--------------------------------------------------------------------------
| Respuesta a SendPulse
|--------------------------------------------------------------------------
*/

if (!$ghlSuccess) {
    if (ACK_SENDPULSE_ON_GHL_FAILURE) {
        jsonResponse(200, [
            'ok'         => true,
            'status'     => 'captured_but_ghl_failed',
            'forwarded'  => false,
            'request_id' => $requestId,
            'ghl_status' => $ghlResponse['status'],
        ]);
    }

    jsonResponse(502, [
        'ok'         => false,
        'error'      => 'ghl_forward_failed',
        'request_id' => $requestId,
        'ghl_status' => $ghlResponse['status'],
    ]);
}

jsonResponse(200, [
    'ok'         => true,
    'status'     => 'forwarded_to_ghl',
    'forwarded'  => true,
    'request_id' => $requestId,
    'project'    => $project,
    'phone'      => $phone,
]);