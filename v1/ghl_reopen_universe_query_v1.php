<?php
declare(strict_types=1);

/**
 * GHL -> SendPulse dry run reanudable por snapshot.
 * Universo exclusivo: oportunidades OPEN en etapa Enfriado.
 * Elegibilidad temporal: tiempo mínimo desde lastStageChangeAt.
 * La señal nl_decision se conserva como diagnóstico:
 * - Interesado => segmento A, candidato fuerte.
 * - No interesado => segmento D, excluido.
 * - Vacío => segmento BC, pendiente de contrastar con actividad.
 *
 * Acciones HTTP:
 * - action=create_snapshot
 * - action=process_batch&run_id=...&batch_size=25
 * - action=status&run_id=...
 *
 * No genera CSV, no envía mensajes y no modifica GHL ni SendPulse.
 */

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    ignore_user_abort(true);
    set_time_limit(0);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

const CONFIG_PATH = __DIR__ . '/config.php';

if (!is_file(CONFIG_PATH)) {
    respondAndExit([
        'ok' => false,
        'reason' => 'config_not_found',
        'path' => basename(CONFIG_PATH),
    ], 500);
}

$config = require CONFIG_PATH;

if (!is_array($config)) {
    respondAndExit([
        'ok' => false,
        'reason' => 'invalid_config',
        'message' => 'config.php debe devolver un arreglo.',
    ], 500);
}

$ghl = $config['ghl'] ?? [];
$sendpulse = $config['sendpulse'] ?? [];
$runtime = $config['runtime'] ?? [];
$projects = $config['projects'] ?? [];

$ghlToken = trim((string) ($ghl['token'] ?? ''));
$ghlLocationId = trim((string) ($ghl['location_id'] ?? ''));
$ghlBaseUrl = rtrim((string) ($ghl['base_url'] ?? ''), '/');
$ghlApiVersion = trim((string) ($ghl['api_version'] ?? 'v3'));
$executionKey = trim((string) ($ghl['execution_key'] ?? ''));

$spBaseUrl = rtrim((string) ($sendpulse['base_url'] ?? ''), '/');
$spAuthMode = strtolower(trim((string) ($sendpulse['auth_mode'] ?? 'api_key')));
$spClientId = trim((string) ($sendpulse['client_id'] ?? ''));
$spClientSecret = trim((string) ($sendpulse['client_secret'] ?? ''));
$spApiKey = trim((string) ($sendpulse['api_key'] ?? ''));
$spBotId = trim((string) ($sendpulse['bot_id'] ?? ''));
$spDecisionVariable = trim((string) ($sendpulse['decision_variable'] ?? 'nl_decision'));
$spRequestDelayMs = max(0, (int) ($sendpulse['request_delay_ms'] ?? 125));

$timezone = trim((string) ($runtime['timezone'] ?? 'America/Mexico_City'));
$ghlPageLimit = (int) ($runtime['ghl_page_limit'] ?? 100);
$ghlMaxPages = (int) ($runtime['ghl_max_pages_per_stage'] ?? 100);
$httpTimeout = (int) ($runtime['http_timeout_seconds'] ?? 35);
$httpConnectTimeout = (int) ($runtime['http_connect_timeout_seconds'] ?? 10);
$httpMaxAttempts = (int) ($runtime['http_max_attempts'] ?? 3);
if (!array_key_exists('reopen_min_days_in_stage', $runtime)) {
    respondAndExit([
        'ok' => false,
        'mode' => 'preflight_validation',
        'dry_run' => true,
        'reason' => 'missing_reopen_min_days_in_stage',
        'message' => (
            'Define runtime.reopen_min_days_in_stage explícitamente '
            . 'antes de crear un snapshot Reopen.'
        ),
    ], 500);
}

$reopenMinDaysInStage = max(
    0,
    (int) $runtime['reopen_min_days_in_stage']
);

$defaultBatchSize = max(
    1,
    (int) ($runtime['snapshot_default_batch_size'] ?? 25)
);
$maxBatchSize = max(
    $defaultBatchSize,
    (int) ($runtime['snapshot_max_batch_size'] ?? 50)
);
$processingStaleMinutes = max(
    1,
    (int) ($runtime['snapshot_processing_stale_minutes'] ?? 30)
);

date_default_timezone_set($timezone);

if (!$isCli) {
    authorizeHttpExecution($executionKey);
}

try {
    validateConfig(
        ghlToken: $ghlToken,
        ghlLocationId: $ghlLocationId,
        ghlBaseUrl: $ghlBaseUrl,
        projects: $projects,
        ghlPageLimit: $ghlPageLimit,
        ghlMaxPages: $ghlMaxPages,
        spBaseUrl: $spBaseUrl,
        spAuthMode: $spAuthMode,
        spClientId: $spClientId,
        spClientSecret: $spClientSecret,
        spApiKey: $spApiKey,
        spBotId: $spBotId,
        spDecisionVariable: $spDecisionVariable
    );
} catch (Throwable $exception) {
    respondAndExit([
        'ok' => false,
        'mode' => 'preflight_validation',
        'dry_run' => true,
        'reason' => 'invalid_configuration',
        'error' => $exception->getMessage(),
    ], 500);
}

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$runId = trim((string) ($_GET['run_id'] ?? ''));
$debug = queryBoolean('debug');
$retryErrors = queryBoolean('retry_errors');

$batchSizeRequested = isset($_GET['batch_size'])
    ? (int) $_GET['batch_size']
    : $defaultBatchSize;

$batchSize = min(
    $maxBatchSize,
    max(1, $batchSizeRequested)
);

$storageRoot = __DIR__ . '/storage/reopen_runs';
$logDir = __DIR__ . '/logs';
$globalLogPath = $logDir . '/ghl_reopen_universe_v1.log';

ensureProtectedDirectory($storageRoot);
ensureProtectedDirectory($logDir);

switch ($action) {
    case 'create_snapshot':
        createSnapshotAction(
            storageRoot: $storageRoot,
            globalLogPath: $globalLogPath,
            ghlToken: $ghlToken,
            ghlLocationId: $ghlLocationId,
            ghlBaseUrl: $ghlBaseUrl,
            ghlApiVersion: $ghlApiVersion,
            projects: $projects,
            ghlPageLimit: $ghlPageLimit,
            ghlMaxPages: $ghlMaxPages,
            reopenMinDaysInStage: $reopenMinDaysInStage,
            httpTimeout: $httpTimeout,
            httpConnectTimeout: $httpConnectTimeout,
            httpMaxAttempts: $httpMaxAttempts
        );
        break;

    case 'process_batch':
        processBatchAction(
            storageRoot: $storageRoot,
            globalLogPath: $globalLogPath,
            runId: $runId,
            batchSize: $batchSize,
            retryErrors: $retryErrors,
            debug: $debug,
            processingStaleMinutes: $processingStaleMinutes,
            spBaseUrl: $spBaseUrl,
            spAuthMode: $spAuthMode,
            spClientId: $spClientId,
            spClientSecret: $spClientSecret,
            spApiKey: $spApiKey,
            spBotId: $spBotId,
            spDecisionVariable: $spDecisionVariable,
            spRequestDelayMs: $spRequestDelayMs,
            httpTimeout: $httpTimeout,
            httpConnectTimeout: $httpConnectTimeout,
            httpMaxAttempts: $httpMaxAttempts
        );
        break;

    case 'status':
        statusAction(
            storageRoot: $storageRoot,
            runId: $runId
        );
        break;

    default:
        respondAndExit([
            'ok' => false,
            'reason' => 'invalid_action',
            'allowed_actions' => [
                'create_snapshot',
                'process_batch',
                'status',
            ],
            'examples' => [
                '?action=create_snapshot',
                '?action=process_batch&run_id=RUN_ID&batch_size=25',
                '?action=status&run_id=RUN_ID',
            ],
        ], 400);
}

/*
|--------------------------------------------------------------------------
| Acciones
|--------------------------------------------------------------------------
*/

function createSnapshotAction(
    string $storageRoot,
    string $globalLogPath,
    string $ghlToken,
    string $ghlLocationId,
    string $ghlBaseUrl,
    string $ghlApiVersion,
    array $projects,
    int $ghlPageLimit,
    int $ghlMaxPages,
    int $reopenMinDaysInStage,
    int $httpTimeout,
    int $httpConnectTimeout,
    int $httpMaxAttempts
): never {
    $startedAt = microtime(true);
    $createLockPath = $storageRoot . '/create_snapshot.lock';
    $createLock = acquireExclusiveLock($createLockPath);

    if ($createLock === null) {
        respondAndExit([
            'ok' => false,
            'reason' => 'snapshot_creation_already_running',
        ], 409);
    }

    $runId = date('Ymd_His') . '_' . bin2hex(random_bytes(2));
    $runDir = $storageRoot . '/' . $runId;

    try {
        ensureProtectedDirectory($runDir);

        $runLogPath = $runDir . '/run.log';

        logLine(
            $globalLogPath,
            "CREATE_START run_id={$runId} "
            . "stage=enfriado min_days={$reopenMinDaysInStage}"
        );
        logLine(
            $runLogPath,
            "CREATE_START run_id={$runId} "
            . "stage=enfriado min_days={$reopenMinDaysInStage}"
        );

        $ghlRows = [];
        $projectSummaries = [];
        $ghlApiCalls = 0;

        foreach ($projects as $projectKey => $projectConfig) {
            $projectLabel = trim((string) (
                $projectConfig['label'] ?? $projectKey
            ));
            $pipelineId = trim((string) (
                $projectConfig['pipeline_id'] ?? ''
            ));
            $baselineEnfriadoOpenCount = array_key_exists(
                'baseline_enfriado_open_count',
                $projectConfig
            )
                ? max(0, (int) $projectConfig['baseline_enfriado_open_count'])
                : null;

            $stages = [
                'enfriado' => $projectConfig['stages']['enfriado'] ?? [],
            ];

            $projectCount = 0;
            $stageSummaries = [];

            foreach ($stages as $stageKey => $stageConfig) {
                $stageLabel = trim((string) (
                    $stageConfig['label'] ?? $stageKey
                ));
                $stageId = trim((string) (
                    $stageConfig['ghl_stage_id'] ?? ''
                ));
                $capiStageId = (int) (
                    $stageConfig['capi_stage_id'] ?? 0
                );

                $stageResult = fetchGhlStageOpportunities(
                    token: $ghlToken,
                    baseUrl: $ghlBaseUrl,
                    apiVersion: $ghlApiVersion,
                    locationId: $ghlLocationId,
                    pipelineId: $pipelineId,
                    stageId: $stageId,
                    pageLimit: $ghlPageLimit,
                    maxPages: $ghlMaxPages,
                    httpTimeout: $httpTimeout,
                    httpConnectTimeout: $httpConnectTimeout,
                    httpMaxAttempts: $httpMaxAttempts,
                    logPath: $runLogPath
                );

                $ghlApiCalls += $stageResult['api_calls'];
                $stageCount = count($stageResult['opportunities']);
                $projectCount += $stageCount;

                foreach ($stageResult['opportunities'] as $opportunity) {
                    $snapshot = opportunitySnapshot($opportunity);

                    $ghlRows[] = array_merge($snapshot, [
                        'project_key' => (string) $projectKey,
                        'project_label' => $projectLabel,
                        'pipeline_id_config' => $pipelineId,
                        'stage_key' => (string) $stageKey,
                        'stage_label' => $stageLabel,
                        'stage_id_config' => $stageId,
                        'capi_stage_id' => $capiStageId,
                    ]);
                }

                $stageSummaries[$stageKey] = [
                    'label' => $stageLabel,
                    'count' => $stageCount,
                    'pages' => $stageResult['pages'],
                    'duration_ms' => $stageResult['duration_ms'],
                ];
            }

            $projectSummaries[$projectKey] = [
                'label' => $projectLabel,
                'baseline_enfriado_open_count' => $baselineEnfriadoOpenCount,
                'actual_open_enfriado_count' => $projectCount,
                'difference' => $baselineEnfriadoOpenCount !== null
                    ? $projectCount - $baselineEnfriadoOpenCount
                    : null,
                'matches_baseline' => $baselineEnfriadoOpenCount !== null
                    ? $projectCount === $baselineEnfriadoOpenCount
                    : null,
                'stages' => $stageSummaries,
            ];
        }

        $phoneGroups = [];
        $invalidItems = [];

        foreach ($ghlRows as $row) {
            $phone = normalizePhone((string) ($row['phone'] ?? ''));

            if ($phone === '') {
                $itemId = 'invalid_' . hash(
                    'sha256',
                    (string) ($row['opportunity_id'] ?? '')
                );

                $invalidItems[] = [
                    'item_id' => $itemId,
                    'phone_normalized' => '',
                    'ghl_opportunity_count' => 1,
                    'ghl_opportunities' => [
                        compactGhlOpportunity($row),
                    ],
                ];
                continue;
            }

            $phoneGroups[$phone][] = $row;
        }

        ksort($phoneGroups, SORT_STRING);

        $snapshotItems = [];

        foreach ($phoneGroups as $phoneKey => $group) {
            $phone = (string) $phoneKey;

            $snapshotItems[] = [
                'item_id' => 'phone_' . hash('sha256', $phone),
                'phone_normalized' => $phone,
                'ghl_opportunity_count' => count($group),
                'ghl_opportunities' => array_map(
                    'compactGhlOpportunity',
                    $group
                ),
            ];
        }

        foreach ($invalidItems as $invalidItem) {
            $snapshotItems[] = $invalidItem;
        }

        $createdAt = date(DATE_ATOM);
        $baselineEnfriadoCounts = [];

        foreach ($projects as $projectConfig) {
            if (!array_key_exists('baseline_enfriado_open_count', $projectConfig)) {
                $baselineEnfriadoCounts = [];
                break;
            }

            $baselineEnfriadoCounts[] = max(
                0,
                (int) $projectConfig['baseline_enfriado_open_count']
            );
        }

        $baselineEnfriadoOpenTotal = $baselineEnfriadoCounts !== []
            ? array_sum($baselineEnfriadoCounts)
            : null;
        $eligibilityCutoffAt = date(
            DATE_ATOM,
            time() - ($reopenMinDaysInStage * 86400)
        );

        $snapshot = [
            'schema_version' => 1,
            'run_id' => $runId,
            'created_at' => $createdAt,
            'frozen' => true,
            'filters' => [
                'opportunity_status' => 'open',
                'stage_keys' => ['enfriado'],
                'reopen_min_days_in_stage' => $reopenMinDaysInStage,
                'eligibility_reference_at' => $createdAt,
                'last_stage_change_cutoff_at' => $eligibilityCutoffAt,
            ],
            'api_calls' => [
                'ghl' => $ghlApiCalls,
            ],
            'ghl_universe' => [
                'baseline_enfriado_open_total' => $baselineEnfriadoOpenTotal,
                'actual_open_enfriado_opportunities' => count($ghlRows),
                'actual_open_opportunities' => count($ghlRows),
                'difference' => $baselineEnfriadoOpenTotal !== null
                    ? count($ghlRows) - $baselineEnfriadoOpenTotal
                    : null,
                'unique_phone_groups' => count($phoneGroups),
                'rows_without_valid_phone' => count($invalidItems),
                'snapshot_items' => count($snapshotItems),
                'projects' => $projectSummaries,
            ],
            'items' => $snapshotItems,
        ];

        $stateItems = [];
        $initialResultItems = [];

        foreach ($snapshotItems as $item) {
            $itemId = (string) $item['item_id'];
            $phone = (string) ($item['phone_normalized'] ?? '');

            if ($phone === '') {
                $stateItems[$itemId] = [
                    'status' => 'processed',
                    'attempts' => 0,
                    'started_at' => null,
                    'finished_at' => $createdAt,
                    'last_error' => null,
                ];

                $initialResultItems[$itemId] = [
                    'item_id' => $itemId,
                    'phone_normalized' => '',
                    'ghl_opportunity_count' => (
                        $item['ghl_opportunity_count'] ?? 0
                    ),
                    'ghl_opportunities' => (
                        $item['ghl_opportunities'] ?? []
                    ),
                    'sendpulse' => [
                        'lookup_status' => 'not_attempted',
                    ],
                    'evaluation' => [
                        'status' => 'review_required',
                        'reason' => 'invalid_or_empty_phone',
                    ],
                    'processed_at' => $createdAt,
                ];
            } else {
                $stateItems[$itemId] = [
                    'status' => 'pending',
                    'attempts' => 0,
                    'started_at' => null,
                    'finished_at' => null,
                    'last_error' => null,
                ];
            }
        }

        $state = [
            'schema_version' => 1,
            'run_id' => $runId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'items' => $stateItems,
        ];

        $results = [
            'schema_version' => 1,
            'run_id' => $runId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'items' => $initialResultItems,
        ];

        writeJsonAtomically(
            $runDir . '/snapshot.json',
            $snapshot
        );
        writeJsonAtomically(
            $runDir . '/state.json',
            $state
        );
        writeJsonAtomically(
            $runDir . '/results.json',
            $results
        );

        $summary = buildRunSummary($snapshot, $state, $results);

        $elapsedMs = elapsedMs($startedAt);

        logLine(
            $runLogPath,
            "CREATE_END run_id={$runId} elapsed_ms={$elapsedMs} "
            . "items={$summary['state']['total']}"
        );
        logLine(
            $globalLogPath,
            "CREATE_END run_id={$runId} elapsed_ms={$elapsedMs} "
            . "items={$summary['state']['total']}"
        );

        releaseLock($createLock);

        respondAndExit([
            'ok' => true,
            'mode' => 'snapshot_created',
            'dry_run' => true,
            'side_effects' => false,
            'run_id' => $runId,
            'elapsed_ms' => $elapsedMs,
            'api_calls' => [
                'ghl' => $ghlApiCalls,
                'sendpulse' => 0,
            ],
            'summary' => $summary,
            'files' => [
                'snapshot' => 'snapshot.json',
                'state' => 'state.json',
                'results' => 'results.json',
                'log' => 'run.log',
            ],
            'next_command' => (
                '?action=process_batch&run_id='
                . rawurlencode($runId)
                . '&batch_size=25'
            ),
        ], 200);
    } catch (Throwable $exception) {
        logLine(
            $globalLogPath,
            "CREATE_FATAL run_id={$runId} message="
            . $exception->getMessage()
        );

        releaseLock($createLock);

        respondAndExit([
            'ok' => false,
            'mode' => 'create_snapshot',
            'dry_run' => true,
            'run_id' => $runId,
            'error' => $exception->getMessage(),
        ], 500);
    }
}

function processBatchAction(
    string $storageRoot,
    string $globalLogPath,
    string $runId,
    int $batchSize,
    bool $retryErrors,
    bool $debug,
    int $processingStaleMinutes,
    string $spBaseUrl,
    string $spAuthMode,
    string $spClientId,
    string $spClientSecret,
    string $spApiKey,
    string $spBotId,
    string $spDecisionVariable,
    int $spRequestDelayMs,
    int $httpTimeout,
    int $httpConnectTimeout,
    int $httpMaxAttempts
): never {
    validateRunIdOrExit($runId);

    $startedAt = microtime(true);
    $paths = runPaths($storageRoot, $runId);

    assertRunFilesExist($paths);

    $runLock = acquireExclusiveLock($paths['lock']);

    if ($runLock === null) {
        respondAndExit([
            'ok' => false,
            'reason' => 'run_already_processing',
            'run_id' => $runId,
        ], 409);
    }

    try {
        $snapshot = loadJsonFile($paths['snapshot']);
        $state = loadJsonFile($paths['state']);
        $results = loadJsonFile($paths['results']);

        if (!array_key_exists(
            'reopen_min_days_in_stage',
            $snapshot['filters'] ?? []
        )) {
            throw new RuntimeException(
                'El snapshot no contiene reopen_min_days_in_stage.'
            );
        }

        $reopenMinDaysInStage = max(
            0,
            (int) $snapshot['filters']['reopen_min_days_in_stage']
        );
        $eligibilityReferenceAt = trim((string) (
            $snapshot['filters']['eligibility_reference_at']
            ?? $snapshot['created_at']
            ?? date(DATE_ATOM)
        ));

        $recovered = recoverStaleProcessingItems(
            state: $state,
            staleMinutes: $processingStaleMinutes
        );

        if ($recovered > 0) {
            $state['updated_at'] = date(DATE_ATOM);
            writeJsonAtomically($paths['state'], $state);

            logLine(
                $paths['log'],
                "RECOVER_STALE count={$recovered}"
            );
        }

        $candidateIds = selectBatchItemIds(
            state: $state,
            batchSize: $batchSize,
            retryErrors: $retryErrors
        );

        if ($candidateIds === []) {
            $summary = buildRunSummary(
                $snapshot,
                $state,
                $results
            );

            releaseLock($runLock);

            respondAndExit([
                'ok' => true,
                'mode' => 'process_batch',
                'dry_run' => true,
                'run_id' => $runId,
                'batch_size' => $batchSize,
                'processed_this_batch' => 0,
                'message' => (
                    $summary['state']['pending'] === 0
                    && $summary['state']['processing'] === 0
                    ? 'No hay items pendientes.'
                    : 'No se encontraron items procesables.'
                ),
                'summary' => $summary,
                'complete' => (
                    $summary['state']['pending'] === 0
                    && $summary['state']['processing'] === 0
                ),
            ], 200);
        }

        $snapshotById = indexSnapshotItems(
            $snapshot['items'] ?? []
        );

        /*
         * Autenticación diferida: solo se consulta SendPulse cuando GHL
         * confirma que el contacto ya cumple la ventana de Enfriado.
         */
        $bearerToken = '';
        $authDurationMs = 0;
        $sendPulseAuthCalls = 0;
        $sendPulseLookupCalls = 0;
        $lookupDurations = [];
        $processedThisBatch = 0;

        logLine(
            $paths['log'],
            "BATCH_START run_id={$runId} batch_size={$batchSize} "
            . "selected=" . count($candidateIds)
            . " retry_errors=" . ($retryErrors ? '1' : '0')
            . " debug=" . ($debug ? '1' : '0')
        );

        foreach ($candidateIds as $itemId) {
            $item = $snapshotById[$itemId] ?? null;

            if (!is_array($item)) {
                $state['items'][$itemId]['status'] = 'error';
                $state['items'][$itemId]['attempts'] = (
                    (int) ($state['items'][$itemId]['attempts'] ?? 0)
                ) + 1;
                $state['items'][$itemId]['finished_at'] = date(DATE_ATOM);
                $state['items'][$itemId]['last_error'] = (
                    'snapshot_item_not_found'
                );
                $state['updated_at'] = date(DATE_ATOM);
                writeJsonAtomically($paths['state'], $state);
                continue;
            }

            $phone = (string) (
                $item['phone_normalized'] ?? ''
            );

            $state['items'][$itemId]['status'] = 'processing';
            $state['items'][$itemId]['attempts'] = (
                (int) ($state['items'][$itemId]['attempts'] ?? 0)
            ) + 1;
            $state['items'][$itemId]['started_at'] = date(DATE_ATOM);
            $state['items'][$itemId]['finished_at'] = null;
            $state['items'][$itemId]['last_error'] = null;
            $state['updated_at'] = date(DATE_ATOM);

            writeJsonAtomically($paths['state'], $state);

            $ghlEvaluation = evaluateGhlReopenEligibility(
                item: $item,
                minDaysInStage: $reopenMinDaysInStage,
                referenceAt: $eligibilityReferenceAt
            );

            $lookup = [
                'status' => 'not_attempted',
                'http_status' => null,
                'contact' => null,
                'error' => '',
                'raw_response' => null,
            ];
            $lookupDurationMs = null;
            $spContact = null;
            $variablesMap = [];
            $tags = [];
            $decisionRaw = '';
            $decisionNormalized = '';
            $evaluation = $ghlEvaluation;

            if ($ghlEvaluation['status'] === 'eligible') {
                if ($bearerToken === '') {
                    $authStartedAt = microtime(true);

                    $bearerToken = acquireSendPulseBearerToken(
                        baseUrl: $spBaseUrl,
                        authMode: $spAuthMode,
                        clientId: $spClientId,
                        clientSecret: $spClientSecret,
                        apiKey: $spApiKey,
                        httpTimeout: $httpTimeout,
                        httpConnectTimeout: $httpConnectTimeout,
                        httpMaxAttempts: $httpMaxAttempts,
                        logPath: $paths['log']
                    );

                    $authDurationMs += elapsedMs($authStartedAt);
                    $sendPulseAuthCalls += $spAuthMode === 'oauth' ? 1 : 0;
                }

                if ($sendPulseLookupCalls > 0 && $spRequestDelayMs > 0) {
                    usleep($spRequestDelayMs * 1000);
                }

                $lookupStartedAt = microtime(true);

                $lookup = getSendPulseContactByPhone(
                    bearerToken: $bearerToken,
                    baseUrl: $spBaseUrl,
                    botId: $spBotId,
                    phone: $phone,
                    httpTimeout: $httpTimeout,
                    httpConnectTimeout: $httpConnectTimeout,
                    httpMaxAttempts: $httpMaxAttempts,
                    logPath: $paths['log']
                );

                $lookupDurationMs = elapsedMs($lookupStartedAt);
                $lookupDurations[] = $lookupDurationMs;
                $sendPulseLookupCalls++;

                $spContact = $lookup['contact'];

                $variablesMap = is_array($spContact)
                    ? extractSendPulseVariables($spContact)
                    : [];

                $tags = is_array($spContact)
                    ? extractSendPulseTags($spContact)
                    : [];

                $decisionRaw = scalarToString(
                    $variablesMap[$spDecisionVariable] ?? ''
                );
                $decisionNormalized = normalizeDecision(
                    $decisionRaw
                );

                $evaluation = evaluateSendPulseEligibility(
                    lookupStatus: $lookup['status'],
                    decisionRaw: $decisionRaw,
                    decisionNormalized: $decisionNormalized
                );
            }

            $resultItem = [
                'item_id' => $itemId,
                'phone_normalized' => $phone,
                'ghl_opportunity_count' => (
                    $item['ghl_opportunity_count'] ?? 0
                ),
                'ghl_opportunities' => (
                    $item['ghl_opportunities'] ?? []
                ),
                'ghl_evaluation' => $ghlEvaluation,
                'sendpulse' => [
                    'lookup_status' => $lookup['status'],
                    'http_status' => $lookup['http_status'],
                    'contact_id' => is_array($spContact)
                        ? firstNonEmpty([
                            $spContact['id'] ?? null,
                            $spContact['_id'] ?? null,
                            $spContact['contact_id'] ?? null,
                        ])
                        : '',
                    'name' => is_array($spContact)
                        ? firstNonEmpty([
                            $spContact['channel_data']['name'] ?? null,
                            $spContact['name'] ?? null,
                        ])
                        : '',
                    'phone' => is_array($spContact)
                        ? firstNonEmpty([
                            $spContact['channel_data']['phone'] ?? null,
                            $spContact['phone'] ?? null,
                        ])
                        : '',
                    'nl_decision_raw' => $decisionRaw,
                    'nl_decision_normalized' => $decisionNormalized,
                    'tags' => $tags,
                    'variables' => $variablesMap,
                    'lookup_duration_ms' => $lookupDurationMs,
                    'error' => $lookup['error'],
                ],
                'evaluation' => $evaluation,
                'processed_at' => date(DATE_ATOM),
            ];

            if ($debug && $lookup['status'] !== 'not_attempted') {
                $resultItem['sendpulse']['raw_data'] = $spContact;
                $resultItem['sendpulse']['raw_response'] = (
                    $lookup['raw_response']
                );
            }

            $results['items'][$itemId] = $resultItem;
            $results['updated_at'] = date(DATE_ATOM);

            if ($evaluation['status'] === 'error') {
                $state['items'][$itemId]['status'] = 'error';
                $state['items'][$itemId]['last_error'] = (
                    $lookup['error'] !== ''
                    ? $lookup['error']
                    : $evaluation['reason']
                );
            } else {
                $state['items'][$itemId]['status'] = 'processed';
                $state['items'][$itemId]['last_error'] = null;
            }

            $state['items'][$itemId]['finished_at'] = date(DATE_ATOM);
            $state['updated_at'] = date(DATE_ATOM);

            /*
             * Checkpoint después de cada contacto.
             * Una interrupción no pierde el avance del lote completo.
             */
            writeJsonAtomically($paths['results'], $results);
            writeJsonAtomically($paths['state'], $state);

            $processedThisBatch++;
        }

        $summary = buildRunSummary(
            $snapshot,
            $state,
            $results
        );

        $elapsedMs = elapsedMs($startedAt);
        $complete = (
            $summary['state']['pending'] === 0
            && $summary['state']['processing'] === 0
        );

        logLine(
            $paths['log'],
            "BATCH_END run_id={$runId} elapsed_ms={$elapsedMs} "
            . "processed={$processedThisBatch} "
            . "pending={$summary['state']['pending']} "
            . "errors={$summary['state']['error']}"
        );
        logLine(
            $globalLogPath,
            "BATCH_END run_id={$runId} elapsed_ms={$elapsedMs} "
            . "processed={$processedThisBatch} "
            . "pending={$summary['state']['pending']} "
            . "errors={$summary['state']['error']}"
        );

        releaseLock($runLock);

        respondAndExit([
            'ok' => true,
            'mode' => 'process_batch',
            'dry_run' => true,
            'side_effects' => false,
            'messages_sent' => 0,
            'ghl_records_modified' => 0,
            'sendpulse_records_modified' => 0,
            'run_id' => $runId,
            'elapsed_ms' => $elapsedMs,
            'batch_size' => $batchSize,
            'processed_this_batch' => $processedThisBatch,
            'recovered_stale_items' => $recovered,
            'api_calls' => [
                'ghl' => 0,
                'sendpulse_auth' => $sendPulseAuthCalls,
                'sendpulse_contact_lookup' => (
                    $sendPulseLookupCalls
                ),
            ],
            'performance' => [
                'sendpulse_auth_duration_ms' => $authDurationMs,
                'sendpulse_lookup' => [
                    'count' => count($lookupDurations),
                    'average_ms' => calculateAverage(
                        $lookupDurations
                    ),
                    'min_ms' => (
                        $lookupDurations !== []
                        ? min($lookupDurations)
                        : null
                    ),
                    'max_ms' => (
                        $lookupDurations !== []
                        ? max($lookupDurations)
                        : null
                    ),
                ],
            ],
            'summary' => $summary,
            'complete' => $complete,
            'next_command' => $complete
                ? (
                    '?action=status&run_id='
                    . rawurlencode($runId)
                )
                : (
                    '?action=process_batch&run_id='
                    . rawurlencode($runId)
                    . '&batch_size='
                    . $batchSize
                ),
        ], 200);
    } catch (Throwable $exception) {
        logLine(
            $globalLogPath,
            "BATCH_FATAL run_id={$runId} message="
            . $exception->getMessage()
        );

        releaseLock($runLock);

        respondAndExit([
            'ok' => false,
            'mode' => 'process_batch',
            'dry_run' => true,
            'run_id' => $runId,
            'error' => $exception->getMessage(),
        ], 500);
    }
}

function statusAction(
    string $storageRoot,
    string $runId
): never {
    validateRunIdOrExit($runId);

    $paths = runPaths($storageRoot, $runId);
    assertRunFilesExist($paths);

    $snapshot = loadJsonFile($paths['snapshot']);
    $state = loadJsonFile($paths['state']);
    $results = loadJsonFile($paths['results']);

    $summary = buildRunSummary(
        $snapshot,
        $state,
        $results
    );

    $complete = (
        $summary['state']['pending'] === 0
        && $summary['state']['processing'] === 0
    );

    respondAndExit([
        'ok' => true,
        'mode' => 'status',
        'dry_run' => true,
        'run_id' => $runId,
        'snapshot_created_at' => (
            $snapshot['created_at'] ?? null
        ),
        'summary' => $summary,
        'complete' => $complete,
        'files' => [
            'snapshot' => 'snapshot.json',
            'state' => 'state.json',
            'results' => 'results.json',
            'log' => 'run.log',
        ],
        'next_command' => $complete
            ? null
            : (
                '?action=process_batch&run_id='
                . rawurlencode($runId)
                . '&batch_size=25'
            ),
    ], 200);
}

/*
|--------------------------------------------------------------------------
| Snapshot y checkpoints
|--------------------------------------------------------------------------
*/

function runPaths(string $storageRoot, string $runId): array
{
    $runDir = $storageRoot . '/' . $runId;

    return [
        'dir' => $runDir,
        'snapshot' => $runDir . '/snapshot.json',
        'state' => $runDir . '/state.json',
        'results' => $runDir . '/results.json',
        'log' => $runDir . '/run.log',
        'lock' => $runDir . '/process.lock',
    ];
}

function validateRunIdOrExit(string $runId): void
{
    if (
        $runId === ''
        || !preg_match(
            '/^[0-9]{8}_[0-9]{6}_[a-f0-9]{4}$/',
            $runId
        )
    ) {
        respondAndExit([
            'ok' => false,
            'reason' => 'invalid_run_id',
            'run_id' => $runId,
        ], 400);
    }
}

function assertRunFilesExist(array $paths): void
{
    foreach (['snapshot', 'state', 'results'] as $key) {
        if (!is_file((string) $paths[$key])) {
            respondAndExit([
                'ok' => false,
                'reason' => 'run_file_not_found',
                'file' => basename((string) $paths[$key]),
            ], 404);
        }
    }
}

function acquireExclusiveLock(string $path)
{
    $handle = fopen($path, 'c');

    if (
        $handle === false
        || !flock($handle, LOCK_EX | LOCK_NB)
    ) {
        if (is_resource($handle)) {
            fclose($handle);
        }

        return null;
    }

    return $handle;
}

function loadJsonFile(string $path): array
{
    $content = file_get_contents($path);

    if ($content === false) {
        throw new RuntimeException(
            "No fue posible leer {$path}."
        );
    }

    $decoded = json_decode($content, true);

    if (!is_array($decoded)) {
        throw new RuntimeException(
            "El JSON {$path} es inválido."
        );
    }

    return $decoded;
}

function indexSnapshotItems(array $items): array
{
    $indexed = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemId = trim((string) ($item['item_id'] ?? ''));

        if ($itemId !== '') {
            $indexed[$itemId] = $item;
        }
    }

    return $indexed;
}

function selectBatchItemIds(
    array $state,
    int $batchSize,
    bool $retryErrors
): array {
    $selected = [];

    foreach (($state['items'] ?? []) as $itemId => $itemState) {
        if (!is_array($itemState)) {
            continue;
        }

        $status = (string) ($itemState['status'] ?? '');

        $processable = $status === 'pending'
            || ($retryErrors && $status === 'error');

        if (!$processable) {
            continue;
        }

        $selected[] = (string) $itemId;

        if (count($selected) >= $batchSize) {
            break;
        }
    }

    return $selected;
}

function recoverStaleProcessingItems(
    array &$state,
    int $staleMinutes
): int {
    $recovered = 0;
    $threshold = time() - ($staleMinutes * 60);

    foreach (($state['items'] ?? []) as $itemId => $itemState) {
        if (!is_array($itemState)) {
            continue;
        }

        if (($itemState['status'] ?? '') !== 'processing') {
            continue;
        }

        $startedAt = strtotime((string) (
            $itemState['started_at'] ?? ''
        ));

        if ($startedAt !== false && $startedAt > $threshold) {
            continue;
        }

        $state['items'][$itemId]['status'] = 'pending';
        $state['items'][$itemId]['started_at'] = null;
        $state['items'][$itemId]['finished_at'] = null;
        $state['items'][$itemId]['last_error'] = (
            'recovered_stale_processing'
        );

        $recovered++;
    }

    return $recovered;
}

function buildRunSummary(
    array $snapshot,
    array $state,
    array $results
): array {
    $stateCounts = [
        'total' => 0,
        'pending' => 0,
        'processing' => 0,
        'processed' => 0,
        'error' => 0,
        'unknown' => 0,
    ];

    foreach (($state['items'] ?? []) as $itemState) {
        if (!is_array($itemState)) {
            continue;
        }

        $stateCounts['total']++;
        $status = (string) ($itemState['status'] ?? 'unknown');

        if (!array_key_exists($status, $stateCounts)) {
            $status = 'unknown';
        }

        $stateCounts[$status]++;
    }

    $cross = [
        'evaluated' => 0,
        'ghl_ready_for_sendpulse_check' => 0,
        'ghl_not_ready' => 0,
        'ghl_review_required' => 0,
        'sendpulse_lookup_skipped' => 0,
        'sendpulse_found' => 0,
        'sendpulse_not_found' => 0,
        'eligible' => 0,
        'not_eligible' => 0,
        'review_required' => 0,
        'error' => 0,
        'decision_interesado' => 0,
        'decision_no_interesado' => 0,
        'decision_empty' => 0,
        'decision_unknown' => 0,
        'duplicate_phone_groups' => 0,
        'reopen_segments' => [
            'A_interested_confirmed' => 0,
            'BC_activity_pending' => 0,
            'D_not_interested' => 0,
            'not_found' => 0,
            'review_unknown_decision' => 0,
            'error' => 0,
        ],
        'eligible_by_project' => [],
        'reason_counts' => [],
    ];

    foreach (($results['items'] ?? []) as $resultItem) {
        if (!is_array($resultItem)) {
            continue;
        }

        $evaluation = $resultItem['evaluation'] ?? [];
        $ghlEvaluation = $resultItem['ghl_evaluation'] ?? [];
        $sendpulse = $resultItem['sendpulse'] ?? [];

        $evaluationStatus = (string) (
            $evaluation['status'] ?? ''
        );
        $ghlStatus = (string) (
            $ghlEvaluation['status'] ?? ''
        );
        $reason = (string) (
            $evaluation['reason'] ?? ''
        );
        $lookupStatus = (string) (
            $sendpulse['lookup_status'] ?? ''
        );
        $reopenSegment = (string) (
            $evaluation['reopen_segment'] ?? ''
        );

        $cross['evaluated']++;

        if (
            $reopenSegment !== ''
            && array_key_exists(
                $reopenSegment,
                $cross['reopen_segments']
            )
        ) {
            $cross['reopen_segments'][$reopenSegment]++;
        }

        if ($ghlStatus === 'eligible') {
            $cross['ghl_ready_for_sendpulse_check']++;
        } elseif ($ghlStatus === 'not_eligible') {
            $cross['ghl_not_ready']++;
        } elseif ($ghlStatus === 'review_required') {
            $cross['ghl_review_required']++;
        }

        if ($lookupStatus === 'not_attempted') {
            $cross['sendpulse_lookup_skipped']++;
        } elseif ($lookupStatus === 'found') {
            $cross['sendpulse_found']++;

            $decision = (string) (
                $sendpulse['nl_decision_normalized'] ?? ''
            );
            $decisionRaw = trim((string) (
                $sendpulse['nl_decision_raw'] ?? ''
            ));

            if ($decision === 'Interesado') {
                $cross['decision_interesado']++;
            } elseif ($decision === 'No interesado') {
                $cross['decision_no_interesado']++;
            } elseif ($decisionRaw === '') {
                $cross['decision_empty']++;
            } else {
                $cross['decision_unknown']++;
            }
        } elseif ($lookupStatus === 'not_found') {
            $cross['sendpulse_not_found']++;
        }

        if (array_key_exists($evaluationStatus, $cross)) {
            $cross[$evaluationStatus]++;
        }

        if (
            (int) ($resultItem['ghl_opportunity_count'] ?? 0) > 1
        ) {
            $cross['duplicate_phone_groups']++;
        }

        if ($evaluationStatus === 'eligible') {
            $opportunities = $resultItem['ghl_opportunities'] ?? [];
            $projectKey = is_array($opportunities)
                && isset($opportunities[0])
                && is_array($opportunities[0])
                ? trim((string) ($opportunities[0]['project_key'] ?? ''))
                : '';

            if ($projectKey !== '') {
                incrementCounter(
                    $cross['eligible_by_project'],
                    $projectKey
                );
            }
        }

        if ($reason !== '') {
            incrementCounter($cross['reason_counts'], $reason);
        }
    }

    ksort($cross['eligible_by_project'], SORT_NATURAL);
    ksort($cross['reason_counts'], SORT_NATURAL);

    return [
        'snapshot' => [
            'created_at' => $snapshot['created_at'] ?? null,
            'stage_keys' => $snapshot['filters']['stage_keys'] ?? [],
            'reopen_min_days_in_stage' => (
                $snapshot['filters']['reopen_min_days_in_stage']
                ?? null
            ),
            'last_stage_change_cutoff_at' => (
                $snapshot['filters']['last_stage_change_cutoff_at']
                ?? null
            ),
            'actual_open_enfriado_opportunities' => (
                $snapshot['ghl_universe'][
                    'actual_open_enfriado_opportunities'
                ]
                ?? $snapshot['ghl_universe']['actual_open_opportunities']
                ?? null
            ),
            'unique_phone_groups' => (
                $snapshot['ghl_universe']['unique_phone_groups']
                ?? null
            ),
            'rows_without_valid_phone' => (
                $snapshot['ghl_universe']['rows_without_valid_phone']
                ?? null
            ),
            'snapshot_items' => (
                $snapshot['ghl_universe']['snapshot_items']
                ?? null
            ),
        ],
        'state' => $stateCounts,
        'cross' => $cross,
    ];
}

function queryBoolean(string $name): bool
{
    if (!isset($_GET[$name])) {
        return false;
    }

    return in_array(
        strtolower(trim((string) $_GET[$name])),
        ['1', 'true', 'yes', 'on'],
        true
    );
}


/*
|--------------------------------------------------------------------------
| Funciones reutilizadas y adaptadas para universo Reopen / Enfriado
|--------------------------------------------------------------------------
*/

function authorizeHttpExecution(string $configuredKey): void
{
    if ($configuredKey === '') {
        respondAndExit([
            'ok' => false,
            'reason' => 'missing_execution_key',
        ], 500);
    }

    $providedKey = getRequestHeader('X-Reopen-Key');

    if ($providedKey === '' || !hash_equals($configuredKey, $providedKey)) {
        respondAndExit([
            'ok' => false,
            'reason' => 'unauthorized',
        ], 401);
    }
}

function getRequestHeader(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            foreach ($headers as $headerName => $value) {
                if (strcasecmp((string) $headerName, $name) === 0) {
                    return trim((string) $value);
                }
            }
        }
    }

    return '';
}

function respondAndExit(array $payload, int $statusCode): never
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($statusCode);
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    ) . PHP_EOL;

    exit($statusCode >= 400 ? 1 : 0);
}

/*
|--------------------------------------------------------------------------
| Validación
|--------------------------------------------------------------------------
*/

function validateConfig(
    string $ghlToken,
    string $ghlLocationId,
    string $ghlBaseUrl,
    array $projects,
    int $ghlPageLimit,
    int $ghlMaxPages,
    string $spBaseUrl,
    string $spAuthMode,
    string $spClientId,
    string $spClientSecret,
    string $spApiKey,
    string $spBotId,
    string $spDecisionVariable
): void {
    if ($ghlToken === '' || !str_starts_with($ghlToken, 'pit-')) {
        throw new RuntimeException(
            'Configura un PIT válido en ghl.token.'
        );
    }

    if ($ghlLocationId === '' || $ghlBaseUrl === '') {
        throw new RuntimeException(
            'Faltan ghl.location_id o ghl.base_url.'
        );
    }

    if ($projects === []) {
        throw new RuntimeException('No hay proyectos configurados.');
    }

    if ($ghlPageLimit < 1 || $ghlPageLimit > 100) {
        throw new RuntimeException(
            'runtime.ghl_page_limit debe estar entre 1 y 100.'
        );
    }

    if ($ghlMaxPages < 1) {
        throw new RuntimeException(
            'runtime.ghl_max_pages_per_stage debe ser mayor a cero.'
        );
    }

    if ($spBaseUrl === '') {
        throw new RuntimeException(
            'Falta sendpulse.base_url.'
        );
    }

    if (!in_array($spAuthMode, ['oauth', 'api_key'], true)) {
        throw new RuntimeException(
            'sendpulse.auth_mode debe ser oauth o api_key.'
        );
    }

    if (
        $spAuthMode === 'oauth'
        && ($spClientId === '' || $spClientSecret === '')
    ) {
        throw new RuntimeException(
            'Configura sendpulse.client_id y sendpulse.client_secret.'
        );
    }

    if ($spAuthMode === 'api_key' && $spApiKey === '') {
        throw new RuntimeException(
            'Configura sendpulse.api_key.'
        );
    }

    if ($spBotId === '') {
        throw new RuntimeException(
            'Configura sendpulse.bot_id.'
        );
    }

    if ($spDecisionVariable === '') {
        throw new RuntimeException(
            'Configura sendpulse.decision_variable.'
        );
    }

    foreach ($projects as $projectKey => $project) {
        if (trim((string) ($project['pipeline_id'] ?? '')) === '') {
            throw new RuntimeException(
                "Falta pipeline_id para {$projectKey}."
            );
        }

        $enfriadoStageId = trim((string) (
            $project['stages']['enfriado']['ghl_stage_id'] ?? ''
        ));

        if ($enfriadoStageId === '') {
            throw new RuntimeException(
                "Falta enfriado.ghl_stage_id para {$projectKey}."
            );
        }
    }
}

function evaluateGhlReopenEligibility(
    array $item,
    int $minDaysInStage,
    string $referenceAt
): array {
    $opportunities = $item['ghl_opportunities'] ?? [];
    $opportunityCount = (int) (
        $item['ghl_opportunity_count'] ?? 0
    );

    if ($opportunityCount > 1) {
        return [
            'status' => 'review_required',
            'reason' => 'duplicate_phone_multiple_open_opportunities',
            'min_days_in_stage' => $minDaysInStage,
            'reference_at' => $referenceAt,
        ];
    }

    if (
        $opportunityCount !== 1
        || !is_array($opportunities)
        || !isset($opportunities[0])
        || !is_array($opportunities[0])
    ) {
        return [
            'status' => 'review_required',
            'reason' => 'ghl_single_opportunity_not_available',
            'min_days_in_stage' => $minDaysInStage,
            'reference_at' => $referenceAt,
        ];
    }

    $opportunity = $opportunities[0];
    $status = normalizeComparableText((string) (
        $opportunity['status'] ?? ''
    ));
    $stageKey = normalizeComparableText((string) (
        $opportunity['stage_key'] ?? ''
    ));

    if ($status !== 'open') {
        return [
            'status' => 'not_eligible',
            'reason' => 'ghl_opportunity_not_open',
            'opportunity_status' => $status,
            'stage_key' => $stageKey,
            'min_days_in_stage' => $minDaysInStage,
            'reference_at' => $referenceAt,
        ];
    }

    if ($stageKey !== 'enfriado') {
        return [
            'status' => 'not_eligible',
            'reason' => 'ghl_opportunity_not_in_enfriado',
            'opportunity_status' => $status,
            'stage_key' => $stageKey,
            'min_days_in_stage' => $minDaysInStage,
            'reference_at' => $referenceAt,
        ];
    }

    if ($minDaysInStage === 0) {
        return [
            'status' => 'eligible',
            'reason' => 'ghl_open_enfriado_no_wait_required',
            'opportunity_status' => $status,
            'stage_key' => $stageKey,
            'min_days_in_stage' => 0,
            'reference_at' => $referenceAt,
            'last_stage_change_at' => trim((string) (
                $opportunity['last_stage_change_at'] ?? ''
            )),
        ];
    }

    $lastStageChangeAt = trim((string) (
        $opportunity['last_stage_change_at'] ?? ''
    ));
    $referenceTimestamp = strtotime($referenceAt);
    $lastStageChangeTimestamp = strtotime($lastStageChangeAt);

    if (
        $referenceTimestamp === false
        || $lastStageChangeAt === ''
        || $lastStageChangeTimestamp === false
    ) {
        return [
            'status' => 'review_required',
            'reason' => 'ghl_last_stage_change_missing_or_invalid',
            'opportunity_status' => $status,
            'stage_key' => $stageKey,
            'min_days_in_stage' => $minDaysInStage,
            'reference_at' => $referenceAt,
            'last_stage_change_at' => $lastStageChangeAt,
        ];
    }

    if ($lastStageChangeTimestamp > $referenceTimestamp + 300) {
        return [
            'status' => 'review_required',
            'reason' => 'ghl_last_stage_change_is_in_future',
            'opportunity_status' => $status,
            'stage_key' => $stageKey,
            'min_days_in_stage' => $minDaysInStage,
            'reference_at' => $referenceAt,
            'last_stage_change_at' => $lastStageChangeAt,
        ];
    }

    $elapsedSeconds = max(
        0,
        $referenceTimestamp - $lastStageChangeTimestamp
    );
    $requiredSeconds = $minDaysInStage * 86400;
    $eligibleAtTimestamp = $lastStageChangeTimestamp + $requiredSeconds;
    $remainingSeconds = max(
        0,
        $eligibleAtTimestamp - $referenceTimestamp
    );

    $details = [
        'opportunity_status' => $status,
        'stage_key' => $stageKey,
        'min_days_in_stage' => $minDaysInStage,
        'reference_at' => $referenceAt,
        'last_stage_change_at' => $lastStageChangeAt,
        'age_days' => round($elapsedSeconds / 86400, 2),
        'eligible_at' => date(DATE_ATOM, $eligibleAtTimestamp),
        'remaining_hours' => round($remainingSeconds / 3600, 2),
    ];

    if ($elapsedSeconds < $requiredSeconds) {
        return array_merge($details, [
            'status' => 'not_eligible',
            'reason' => 'ghl_enfriado_wait_not_elapsed',
        ]);
    }

    return array_merge($details, [
        'status' => 'eligible',
        'reason' => 'ghl_open_enfriado_wait_elapsed',
    ]);
}

/*
|--------------------------------------------------------------------------
| SendPulse
|--------------------------------------------------------------------------
*/

function acquireSendPulseBearerToken(
    string $baseUrl,
    string $authMode,
    string $clientId,
    string $clientSecret,
    string $apiKey,
    int $httpTimeout,
    int $httpConnectTimeout,
    int $httpMaxAttempts,
    string $logPath
): string {
    if ($authMode === 'api_key') {
        return $apiKey;
    }

    $response = httpJsonRequest(
        method: 'POST',
        url: $baseUrl . '/oauth/access_token',
        headers: [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        body: [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ],
        httpTimeout: $httpTimeout,
        httpConnectTimeout: $httpConnectTimeout,
        httpMaxAttempts: $httpMaxAttempts,
        logPath: $logPath,
        logLabel: 'SENDPULSE_AUTH'
    );

    if (!$response['ok']) {
        throw new RuntimeException(
            'No fue posible autenticar con SendPulse. '
            . "HTTP {$response['status']}; "
            . "body=" . mb_substr($response['body'], 0, 800)
        );
    }

    $accessToken = trim((string) (
        $response['decoded']['access_token'] ?? ''
    ));

    if ($accessToken === '') {
        throw new RuntimeException(
            'SendPulse no devolvió access_token.'
        );
    }

    return $accessToken;
}

function getSendPulseContactByPhone(
    string $bearerToken,
    string $baseUrl,
    string $botId,
    string $phone,
    int $httpTimeout,
    int $httpConnectTimeout,
    int $httpMaxAttempts,
    string $logPath
): array {
    $url = buildUrl(
        $baseUrl,
        '/whatsapp/contacts/getByPhone',
        [
            'phone' => $phone,
            'bot_id' => $botId,
        ]
    );

    $response = httpJsonRequest(
        method: 'GET',
        url: $url,
        headers: [
            'Accept: application/json',
            'Authorization: Bearer ' . $bearerToken,
        ],
        body: null,
        httpTimeout: $httpTimeout,
        httpConnectTimeout: $httpConnectTimeout,
        httpMaxAttempts: $httpMaxAttempts,
        logPath: $logPath,
        logLabel: 'SENDPULSE_LOOKUP'
    );

    $decoded = is_array($response['decoded'])
        ? $response['decoded']
        : [];

    $data = $decoded['data'] ?? null;

    if (
        $response['ok']
        && is_array($data)
        && $data !== []
    ) {
        return [
            'status' => 'found',
            'http_status' => $response['status'],
            'contact' => $data,
            'error' => '',
            'raw_response' => $decoded,
        ];
    }

    $bodyLower = mb_strtolower($response['body']);

    $looksNotFound = (
        in_array($response['status'], [404], true)
        || (
            $response['status'] >= 200
            && $response['status'] < 300
            && (
                $data === null
                || $data === []
                || (($decoded['success'] ?? null) === false)
            )
        )
        || str_contains($bodyLower, 'not found')
        || str_contains($bodyLower, 'no data')
        || str_contains($bodyLower, 'not exist')
    );

    if ($looksNotFound) {
        return [
            'status' => 'not_found',
            'http_status' => $response['status'],
            'contact' => null,
            'error' => '',
            'raw_response' => $decoded,
        ];
    }

    return [
        'status' => 'error',
        'http_status' => $response['status'],
        'contact' => null,
        'error' => firstNonEmpty([
            $response['error'],
            $decoded['message'] ?? null,
            mb_substr($response['body'], 0, 500),
        ]),
        'raw_response' => $decoded,
    ];
}

function extractSendPulseVariables(array $contact): array
{
    $variables = $contact['variables'] ?? [];

    if (!is_array($variables)) {
        return [];
    }

    if (!array_is_list($variables)) {
        $map = [];

        foreach ($variables as $name => $value) {
            $normalizedName = trim((string) $name);

            if ($normalizedName === '') {
                continue;
            }

            $map[$normalizedName] = $value;
        }

        return $map;
    }

    $map = [];

    foreach ($variables as $variable) {
        if (!is_array($variable)) {
            continue;
        }

        $name = firstNonEmpty([
            $variable['name'] ?? null,
            $variable['variable_name'] ?? null,
            $variable['variable']['name'] ?? null,
        ]);

        if ($name === '') {
            continue;
        }

        if (array_key_exists('value', $variable)) {
            $value = $variable['value'];
        } elseif (array_key_exists('variable_value', $variable)) {
            $value = $variable['variable_value'];
        } elseif (array_key_exists('contact_value', $variable)) {
            $value = $variable['contact_value'];
        } else {
            $value = '';
        }

        $map[$name] = $value;
    }

    return $map;
}

function extractSendPulseTags(array $contact): array
{
    $tags = $contact['tags'] ?? [];

    if (!is_array($tags)) {
        return [];
    }

    $result = [];

    foreach ($tags as $tag) {
        if (is_string($tag) || is_numeric($tag)) {
            $name = trim((string) $tag);
        } elseif (is_array($tag)) {
            $name = firstNonEmpty([
                $tag['name'] ?? null,
                $tag['tag'] ?? null,
                $tag['title'] ?? null,
            ]);
        } else {
            $name = '';
        }

        if ($name !== '') {
            $result[$name] = true;
        }
    }

    $names = array_keys($result);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);

    return $names;
}

function normalizeDecision(string $value): string
{
    $normalized = normalizeComparableText($value);

    if ($normalized === 'interesado') {
        return 'Interesado';
    }

    if (
        str_starts_with($normalized, 'no interes')
        || $normalized === 'no'
    ) {
        return 'No interesado';
    }

    return '';
}

function evaluateSendPulseEligibility(
    string $lookupStatus,
    string $decisionRaw,
    string $decisionNormalized
): array {
    if ($lookupStatus === 'error') {
        return [
            'status' => 'error',
            'reason' => 'sendpulse_lookup_error',
            'reopen_segment' => 'error',
        ];
    }

    if ($lookupStatus === 'not_found') {
        return [
            'status' => 'not_eligible',
            'reason' => 'sendpulse_contact_not_found',
            'reopen_segment' => 'not_found',
        ];
    }

    if ($decisionNormalized === 'Interesado') {
        return [
            'status' => 'eligible',
            'reason' => 'eligible_open_enfriado_and_sendpulse_interested',
            'reopen_segment' => 'A_interested_confirmed',
        ];
    }

    if ($decisionNormalized === 'No interesado') {
        return [
            'status' => 'not_eligible',
            'reason' => 'sendpulse_not_interested',
            'reopen_segment' => 'D_not_interested',
        ];
    }

    if (trim($decisionRaw) === '') {
        return [
            'status' => 'review_required',
            'reason' => 'sendpulse_nl_decision_empty_activity_check_required',
            'reopen_segment' => 'BC_activity_pending',
        ];
    }

    return [
        'status' => 'review_required',
        'reason' => 'sendpulse_nl_decision_unknown',
        'reopen_segment' => 'review_unknown_decision',
    ];
}

/*
|--------------------------------------------------------------------------
| GHL
|--------------------------------------------------------------------------
*/

function fetchGhlStageOpportunities(
    string $token,
    string $baseUrl,
    string $apiVersion,
    string $locationId,
    string $pipelineId,
    string $stageId,
    int $pageLimit,
    int $maxPages,
    int $httpTimeout,
    int $httpConnectTimeout,
    int $httpMaxAttempts,
    string $logPath
): array {
    $startedAt = microtime(true);
    $all = [];
    $seenIds = [];
    $apiCalls = 0;
    $apiTotal = null;

    for ($page = 1; $page <= $maxPages; $page++) {
        $url = buildUrl(
            $baseUrl,
            '/opportunities/search',
            [
                'locationId' => $locationId,
                'pipelineId' => $pipelineId,
                'pipelineStageId' => $stageId,
                'status' => 'open',
                'limit' => $pageLimit,
                'page' => $page,
            ]
        );

        $response = httpJsonRequest(
            method: 'GET',
            url: $url,
            headers: [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
                'Version: ' . $apiVersion,
            ],
            body: null,
            httpTimeout: $httpTimeout,
            httpConnectTimeout: $httpConnectTimeout,
            httpMaxAttempts: $httpMaxAttempts,
            logPath: $logPath,
            logLabel: 'GHL'
        );

        $apiCalls++;

        if (!$response['ok']) {
            throw new RuntimeException(
                'Error consultando oportunidades GHL. '
                . "HTTP {$response['status']}; "
                . "body=" . mb_substr($response['body'], 0, 1000)
            );
        }

        $decoded = $response['decoded'];
        $items = extractOpportunities($decoded);

        if ($apiTotal === null) {
            $apiTotal = extractApiTotal($decoded);
        }

        foreach ($items as $opportunity) {
            $opportunityId = trim((string) (
                $opportunity['id'] ?? ''
            ));

            if ($opportunityId === '') {
                continue;
            }

            if (isset($seenIds[$opportunityId])) {
                continue;
            }

            $actualPipelineId = trim((string) (
                $opportunity['pipelineId'] ?? ''
            ));

            $actualStageId = trim((string) (
                $opportunity['pipelineStageId'] ?? ''
            ));

            if (
                ($actualPipelineId !== '' && $actualPipelineId !== $pipelineId)
                || ($actualStageId !== '' && $actualStageId !== $stageId)
            ) {
                logLine(
                    $logPath,
                    "FILTER_MISMATCH opportunity_id={$opportunityId}"
                );
                continue;
            }

            $seenIds[$opportunityId] = true;
            $all[] = $opportunity;
        }

        $received = count($items);

        if (
            $received < $pageLimit
            || ($apiTotal !== null && count($all) >= $apiTotal)
        ) {
            return [
                'opportunities' => $all,
                'pages' => $page,
                'api_calls' => $apiCalls,
                'api_total' => $apiTotal,
                'duration_ms' => elapsedMs($startedAt),
            ];
        }
    }

    throw new RuntimeException(
        "Se alcanzó el máximo de {$maxPages} páginas para "
        . "pipeline={$pipelineId}, stage={$stageId}."
    );
}

function extractOpportunities(array $decoded): array
{
    $candidates = [
        $decoded['opportunities'] ?? null,
        $decoded['data']['opportunities'] ?? null,
        $decoded['data'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_array($candidate) && array_is_list($candidate)) {
            return $candidate;
        }
    }

    return [];
}

function extractApiTotal(array $decoded): ?int
{
    $candidates = [
        $decoded['meta']['total'] ?? null,
        $decoded['total'] ?? null,
        $decoded['data']['meta']['total'] ?? null,
        $decoded['data']['total'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_numeric($candidate)) {
            return (int) $candidate;
        }
    }

    return null;
}

function opportunitySnapshot(array $opportunity): array
{
    $contact = is_array($opportunity['contact'] ?? null)
        ? $opportunity['contact']
        : [];

    $phone = firstNonEmpty([
        $contact['phone'] ?? null,
        $opportunity['phone'] ?? null,
    ]);

    return [
        'opportunity_id' => (string) ($opportunity['id'] ?? ''),
        'opportunity_name' => (string) ($opportunity['name'] ?? ''),
        'contact_id' => firstNonEmpty([
            $opportunity['contactId'] ?? null,
            $contact['id'] ?? null,
        ]),
        'contact_name' => firstNonEmpty([
            $contact['name'] ?? null,
            $contact['contactName'] ?? null,
            $opportunity['contactName'] ?? null,
        ]),
        'phone' => $phone,
        'phone_normalized' => normalizePhone($phone),
        'email' => firstNonEmpty([
            $contact['email'] ?? null,
            $opportunity['email'] ?? null,
        ]),
        'assigned_to' => firstNonEmpty([
            $opportunity['assignedTo'] ?? null,
            $opportunity['assigned_to'] ?? null,
        ]),
        'status' => (string) ($opportunity['status'] ?? ''),
        'last_stage_change_at' => firstNonEmpty([
            $opportunity['lastStageChangeAt'] ?? null,
            $opportunity['last_stage_change_at'] ?? null,
        ]),
        'created_at' => firstNonEmpty([
            $opportunity['createdAt'] ?? null,
            $opportunity['created_at'] ?? null,
        ]),
        'updated_at' => firstNonEmpty([
            $opportunity['updatedAt'] ?? null,
            $opportunity['updated_at'] ?? null,
        ]),
    ];
}

function compactGhlOpportunity(array $row): array
{
    return [
        'opportunity_id' => $row['opportunity_id'] ?? '',
        'opportunity_name' => $row['opportunity_name'] ?? '',
        'contact_id' => $row['contact_id'] ?? '',
        'contact_name' => $row['contact_name'] ?? '',
        'phone' => $row['phone'] ?? '',
        'email' => $row['email'] ?? '',
        'project_key' => $row['project_key'] ?? '',
        'project_label' => $row['project_label'] ?? '',
        'stage_key' => $row['stage_key'] ?? '',
        'stage_label' => $row['stage_label'] ?? '',
        'pipeline_id' => $row['pipeline_id_config'] ?? '',
        'stage_id' => $row['stage_id_config'] ?? '',
        'assigned_to' => $row['assigned_to'] ?? '',
        'status' => $row['status'] ?? '',
        'last_stage_change_at' => $row['last_stage_change_at'] ?? '',
    ];
}

/*
|--------------------------------------------------------------------------
| HTTP y utilidades
|--------------------------------------------------------------------------
*/

function httpJsonRequest(
    string $method,
    string $url,
    array $headers,
    ?array $body,
    int $httpTimeout,
    int $httpConnectTimeout,
    int $httpMaxAttempts,
    string $logPath,
    string $logLabel
): array {
    $lastResult = [
        'ok' => false,
        'status' => 0,
        'body' => '',
        'decoded' => null,
        'error' => '',
        'duration_ms' => 0,
        'attempts' => 0,
    ];

    for ($attempt = 1; $attempt <= $httpMaxAttempts; $attempt++) {
        $startedAt = microtime(true);
        $ch = curl_init($url);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $httpTimeout,
            CURLOPT_CONNECTTIMEOUT => $httpConnectTimeout,
            CURLOPT_ENCODING => '',
        ];

        if ($body !== null) {
            $encodedBody = json_encode(
                $body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($encodedBody === false) {
                throw new RuntimeException(
                    'No fue posible codificar el body HTTP.'
                );
            }

            $options[CURLOPT_POSTFIELDS] = $encodedBody;
        }

        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $bodyString = is_string($responseBody) ? $responseBody : '';
        $decoded = json_decode($bodyString, true);
        $durationMs = elapsedMs($startedAt);

        $lastResult = [
            'ok' => (
                $curlError === ''
                && $status >= 200
                && $status < 300
                && is_array($decoded)
            ),
            'status' => $status,
            'body' => $bodyString,
            'decoded' => $decoded,
            'error' => $curlError,
            'duration_ms' => $durationMs,
            'attempts' => $attempt,
        ];

        logLine(
            $logPath,
            "{$logLabel} method={$method} attempt={$attempt} "
            . "status={$status} duration_ms={$durationMs} "
            . "url=" . sanitizeLoggedUrl($url)
        );

        if ($lastResult['ok']) {
            return $lastResult;
        }

        $isTemporary = (
            $curlError !== ''
            || $status === 408
            || $status === 429
            || $status >= 500
        );

        if (!$isTemporary || $attempt === $httpMaxAttempts) {
            return $lastResult;
        }

        usleep(500000 * $attempt);
    }

    return $lastResult;
}

function sanitizeLoggedUrl(string $url): string
{
    $parts = parse_url($url);

    if (!is_array($parts)) {
        return '[invalid_url]';
    }

    $safe = ($parts['scheme'] ?? 'https')
        . '://'
        . ($parts['host'] ?? '');

    if (isset($parts['path'])) {
        $safe .= $parts['path'];
    }

    return $safe;
}

function buildUrl(string $baseUrl, string $path, array $query): string
{
    return rtrim($baseUrl, '/')
        . '/'
        . ltrim($path, '/')
        . '?'
        . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function ensureProtectedDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(
                "No fue posible crear el directorio {$directory}."
            );
        }
    }

    $htaccess = $directory . '/.htaccess';

    if (!is_file($htaccess)) {
        file_put_contents(
            $htaccess,
            "Require all denied\nDeny from all\n"
        );
    }
}

function writeJsonAtomically(string $path, array $data): void
{
    $tempPath = $path . '.tmp';

    $encoded = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($encoded === false) {
        throw new RuntimeException(
            'No fue posible codificar el JSON de salida.'
        );
    }

    if (file_put_contents($tempPath, $encoded) === false) {
        throw new RuntimeException(
            "No fue posible escribir {$tempPath}."
        );
    }

    if (!rename($tempPath, $path)) {
        @unlink($tempPath);

        throw new RuntimeException(
            "No fue posible mover {$tempPath} a {$path}."
        );
    }
}

function normalizePhone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function normalizeComparableText(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));

    $ascii = iconv(
        'UTF-8',
        'ASCII//TRANSLIT//IGNORE',
        $value
    );

    if ($ascii !== false) {
        $value = strtolower($ascii);
    }

    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

    return trim(
        preg_replace('/\s+/', ' ', $value) ?? $value
    );
}

function scalarToString(mixed $value): string
{
    if (is_string($value) || is_numeric($value)) {
        return trim((string) $value);
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    return '';
}

function firstNonEmpty(array $values): string
{
    foreach ($values as $value) {
        if (is_string($value) || is_numeric($value)) {
            $string = trim((string) $value);

            if ($string !== '') {
                return $string;
            }
        }
    }

    return '';
}

function incrementCounter(array &$counter, string $key): void
{
    $counter[$key] = ($counter[$key] ?? 0) + 1;
}

function calculateAverage(array $values): ?float
{
    if ($values === []) {
        return null;
    }

    return round(array_sum($values) / count($values), 2);
}

function logLine(string $path, string $message): void
{
    file_put_contents(
        $path,
        '[' . date('Y-m-d H:i:s') . '] '
        . $message
        . PHP_EOL,
        FILE_APPEND
    );
}

function elapsedMs(float $startedAt): int
{
    return (int) round(
        (microtime(true) - $startedAt) * 1000
    );
}

function releaseLock($lockHandle): void
{
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
