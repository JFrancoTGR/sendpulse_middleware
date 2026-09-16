<?php
declare (strict_types = 1);

/**
 * Configuración compartida por:
 * - ghl_reopen_universe_query_v1.php
 * - ghl_reopen_send_v1.php
 *
 * Alcance:
 * - Solo oportunidades OPEN en etapa Enfriado.
 * - Credenciales sensibles centralizadas en .env.
 */

const ENV_PATH = __DIR__ . '/.env';

/**
 * Carga un archivo .env sencillo sin dependencias externas.
 *
 * Formatos admitidos:
 * KEY=value
 * KEY="value con espacios o caracteres especiales"
 * KEY='value con espacios o caracteres especiales'
 *
 * Las líneas vacías y las que comienzan con # se ignoran.
 * .env es el origen autoritativo para estas variables.
 */
function loadEnvFile(string $path): void
{
    if (! is_file($path)) {
        throw new RuntimeException(
            'No se encontró el archivo .env requerido: ' . $path
        );
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        throw new RuntimeException(
            'No fue posible leer el archivo .env: ' . $path
        );
    }

    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $separator = strpos($line, '=');

        if ($separator === false) {
            throw new RuntimeException(
                'Línea inválida en .env: ' . ($lineNumber + 1)
            );
        }

        $key   = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));

        if ($key === '' || ! preg_match('/^[A-Z0-9_]+$/', $key)) {
            throw new RuntimeException(
                'Nombre de variable inválido en .env, línea '
                . ($lineNumber + 1)
            );
        }

        if (
            strlen($value) >= 2
            && (
                ($value[0] === '"' && $value[-1] === '"')
                || ($value[0] === "'" && $value[-1] === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        // .env es el origen autoritativo de esta aplicación.
        // Se sobrescribe el entorno del proceso para evitar valores
        // residuales en workers PHP-FPM reutilizados entre peticiones.
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

function envRequired(string $key): string
{
    $value = getenv($key);

    if ($value === false || trim((string) $value) === '') {
        throw new RuntimeException(
            "Falta la variable requerida {$key} en .env."
        );
    }

    return trim((string) $value);
}

function envOptional(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    return trim((string) $value);
}

/**
 * Impide ejecutar config.php directamente desde HTTP.
 */
if (
    PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(403);
    exit('Forbidden');
}

loadEnvFile(ENV_PATH);

return [
    'ghl'       => [
        'token'         => envRequired('GHL_PIT'),
        'location_id'   => envRequired('GHL_LOCATION_ID'),
        'base_url'      => envOptional(
            'GHL_BASE_URL',
            'https://services.leadconnectorhq.com'
        ),
        'api_version'   => envOptional('GHL_API_VERSION', 'v3'),

        /**
         * Clave independiente para ejecutar los endpoints Reopen vía HTTP.
         */
        'execution_key' => envRequired('REOPEN_EXECUTION_KEY'),
    ],

    'sendpulse' => [
        'base_url'              => envOptional(
            'SENDPULSE_BASE_URL',
            'https://api.sendpulse.com'
        ),

        'auth_mode'             => envOptional(
            'SENDPULSE_AUTH_MODE',
            'api_key'
        ),

        'client_id'             => envOptional('SENDPULSE_CLIENT_ID'),
        'client_secret'         => envOptional('SENDPULSE_CLIENT_SECRET'),
        'api_key'               => envOptional('SENDPULSE_API_KEY'),

        'bot_id'                => envRequired('SENDPULSE_BOT_ID'),

        /**
         * Señal histórica del flujo inicial.
         */
        'decision_variable'     => envOptional(
            'SENDPULSE_DECISION_VARIABLE',
            'nl_decision'
        ),

        /**
         * Quick replies.
         * index 0 -> Interesado
         * index 1 -> No interesado
         */
        'flow_interesado_id'    => envRequired(
            'SENDPULSE_FLOW_INTERESADO_ID'
        ),
        'flow_no_interesado_id' => envRequired(
            'SENDPULSE_FLOW_NO_INTERESADO_ID'
        ),

        'request_delay_ms'      => 125,
    ],

    'runtime'   => [
        'timezone'                                        => 'America/Mexico_City',

        'ghl_page_limit'                                  => 100,
        'ghl_max_pages_per_stage'                         => 100,

        /**
         * Primer punto de contacto Reopen.
         *
         * Un contacto puede entrar a la secuencia cuando cumple al menos
         * 2 días desde su último cambio a Enfriado.
         */
        'reopen_min_days_in_stage'                        => 2,

        /**
         * Secuencia Reopen.
         *
         * Cada día se interpreta contra la fecha de entrada a Enfriado,
         * no contra la fecha del envío anterior.
         *
         * El sender deberá:
         * - enviar únicamente el siguiente step pendiente;
         * - detenerse si la oportunidad deja Enfriado;
         * - detenerse si nl_decision cambia a "No interesado";
         * - marcar sequence_complete después de reopen_4 si no existe
         *   ninguna salida detectable.
         */
        'reopen_sequence'                                 => [
            1 => [
                'template'        => 'reopen_1',
                'day_in_enfriado' => 2,
            ],
            2 => [
                'template'        => 'reopen_2',
                'day_in_enfriado' => 4,
            ],
            3 => [
                'template'        => 'reopen_3',
                'day_in_enfriado' => 6,
            ],
            4 => [
                'template'        => 'reopen_4',
                'day_in_enfriado' => 7,
            ],

        ],

        'reopen_live_enabled'                             => true,
        'reopen_live_max_batch_size'                      => 1,
        'reopen_live_require_item_id'                     => true,

        'reopen_automation_enabled'                       => true,
        'reopen_automation_base_url'                      => 'https://estrategiaurbana.info/sendpulse/automation',

        'snapshot_default_batch_size'                     => 25,
        'snapshot_max_batch_size'                         => 50,
        'snapshot_processing_stale_minutes'               => 30,

        'reopen_send_default_batch_size'                  => 10,
        'reopen_send_max_batch_size'                      => 25,
        'reopen_send_processing_stale_minutes'            => 30,

        'http_timeout_seconds'                            => 35,
        'http_connect_timeout_seconds'                    => 10,
        'http_max_attempts'                               => 3,

        'reopen_automation_universe_script'               => 'ghl_reopen_universe_query_v1.php',
        'reopen_automation_sender_script'                 => 'ghl_reopen_send_live_guarded.php',

        'reopen_automation_universe_batch_size'           => 50,
        'reopen_automation_max_send_items_per_invocation' => 150,
        'reopen_automation_max_runtime_seconds'           => 600,
        'reopen_automation_endpoint_timeout_seconds'      => 120,
    ],

    'projects'  => [
        'chilpancingo' => [
            'label'                 => 'COVA CHILPANCINGO',
            'template_project_name' => 'COVA Chilpancingo',
            'pipeline_id'           => 'mBsz4BC5Yw9yVf9cfdZj',

            'stages'                => [
                'enfriado' => [
                    'label'         => 'Enfriado',
                    'capi_stage_id' => 236,
                    'ghl_stage_id'  => '6b538a94-3960-4219-965d-7e58bd2bc3bc',
                ],
            ],
        ],

        'juarez'       => [
            'label'                 => 'SENNSE JUÁREZ',
            'template_project_name' => 'SENNSE Juárez',
            'pipeline_id'           => 'RoLbh8p7EVEykLlVL2IB',

            'stages'                => [
                'enfriado' => [
                    'label'         => 'Enfriado',
                    'capi_stage_id' => 242,
                    'ghl_stage_id'  => '1f6d2e65-a41c-40c5-b2d2-8dd58fdabb3f',
                ],
            ],
        ],

        'liverpool'    => [
            'label'                 => 'SENNSE LIVERPOOL',
            'template_project_name' => 'SENNSE Liverpool',
            'pipeline_id'           => 'MTUZz0ZEQial6a52574i',

            'stages'                => [
                'enfriado' => [
                    'label'         => 'Enfriado',
                    'capi_stage_id' => 246,
                    'ghl_stage_id'  => 'da93e96d-0f28-4a62-ab39-50db867b2992',
                ],
            ],
        ],

        'tabacalera'   => [
            'label'                 => 'SENNSE TABACALERA',
            'template_project_name' => 'SENNSE Tabacalera',
            'pipeline_id'           => '6s1owHRay62fiFyuuugl',

            'stages'                => [
                'enfriado' => [
                    'label'         => 'Enfriado',
                    'capi_stage_id' => 330,
                    'ghl_stage_id'  => 'a5e3c461-2e6c-4272-9ddb-4652953af82f',
                ],
            ],
        ],

        'hamburgo'     => [
            'label'                 => 'SENNSE HAMBURGO',
            'template_project_name' => 'SENNSE Hamburgo',
            'pipeline_id'           => 'oUHz8QHC9rIw0J7QnNwa',

            'stages'                => [
                'enfriado' => [
                    'label'        => 'Enfriado',
                    'ghl_stage_id' => 'de1938af-4e2b-4067-b437-ce263ffc4821',
                ],
            ],
        ],

        'vasconcelos'  => [
            'label'                 => 'SENNSE VASCONCELOS',
            'template_project_name' => 'SENNSE Vasconcelos',
            'pipeline_id'           => 'gspiDeL4dIerU82mCS7O',

            'stages'                => [
                'enfriado' => [
                    'label'        => 'Enfriado',
                    'ghl_stage_id' => 'b7567041-b4d7-4323-87a8-785e0d013ab8',
                ],
            ],
        ],
    ],
];
