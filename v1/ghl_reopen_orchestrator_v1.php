<?php
declare (strict_types = 1);

/**
 * Reopen V1 orchestrator — server-aligned
 *
 * Encadena de forma reanudable:
 *   Universe snapshot -> Universe batches -> Send run -> Send items live -> complete
 *
 * Topología productiva esperada en servidor:
 *   config.php
 *   ghl_reopen_universe_query_v1.php      (Universe productivo)
 *   ghl_reopen_send_live_guarded.php      (Sender productivo/live)
 *   ghl_reopen_send_selftest.php          (QA manual; NO lo invoca el orquestador)
 *   ghl_reopen_send_v1.php                (legado/referencia; NO lo invoca el orquestador)
 *
 * Diseño de seguridad:
 * - El cron/CLI no recibe secretos por argumentos. Lee config.php/.env.
 * - Los endpoints internos reciben X-Reopen-Key por header.
 * - No reintenta automáticamente una llamada live del sender si el transporte falla.
 * - Mantiene un state.json para reanudar un ciclo incompleto en la siguiente ejecución.
 * - Máximo un ciclo nuevo por día calendario local.
 * - Conserva reopen_live_require_item_id=true y procesa un send_item_id explícito a la vez.
 *
 * Acciones:
 *   action=selftest   Sin red, sin writes operativos.
 *   action=status     Estado del orquestador.
 *   action=run        Ejecuta/reanuda el ciclo diario.
 *   action=resume_send_run      Adopta/reanuda un send run live ya existente.
 *
 * CLI:
 *   php ghl_reopen_orchestrator_v1.php action=selftest
 *   php ghl_reopen_orchestrator_v1.php action=status
 *   php ghl_reopen_orchestrator_v1.php action=run
 */

$isCli = PHP_SAPI === 'cli';

$runToCompletion = $isCli;

if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

if (! $isCli) {
    ignore_user_abort(true);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

const CONFIG_PATH = __DIR__ . '/config.php';

if (! is_file(CONFIG_PATH)) {
    orchestratorRespond([
        'ok'     => false,
        'reason' => 'config_not_found',
        'path'   => basename(CONFIG_PATH),
    ], 500);
}

$config = require CONFIG_PATH;
if (! is_array($config)) {
    orchestratorRespond([
        'ok'      => false,
        'reason'  => 'invalid_config',
        'message' => 'config.php debe devolver un arreglo.',
    ], 500);
}

if ($isCli) {
    parseCliArguments($_SERVER['argv'] ?? []);
}

$ghl     = $config['ghl'] ?? [];
$runtime = $config['runtime'] ?? [];

$executionKey = trim((string) ($ghl['execution_key'] ?? ''));
$timezone     = trim((string) ($runtime['timezone'] ?? 'America/Mexico_City'));
date_default_timezone_set($timezone);

$automationEnabled = filter_var(
    $runtime['reopen_automation_enabled'] ?? false,
    FILTER_VALIDATE_BOOLEAN
);
$automationBaseUrl = rtrim(
    trim((string) ($runtime['reopen_automation_base_url'] ?? '')),
    '/'
);
$universeScript = trim((string) (
    $runtime['reopen_automation_universe_script'] ?? 'ghl_reopen_universe_query_v1.php'
));
$senderScript = trim((string) (
    $runtime['reopen_automation_sender_script'] ?? 'ghl_reopen_send_live_guarded.php'
));
$universeBatchSize = max(
    1,
    (int) ($runtime['reopen_automation_universe_batch_size'] ?? 50)
);
$maxSendItemsPerInvocation = max(
    1,
    (int) ($runtime['reopen_automation_max_send_items_per_invocation'] ?? 150)
);
$maxRuntimeSeconds = max(
    30,
    (int) ($runtime['reopen_automation_max_runtime_seconds'] ?? 600)
);
$endpointTimeoutSeconds = max(
    30,
    (int) ($runtime['reopen_automation_endpoint_timeout_seconds'] ?? 120)
);

$liveEnabled = filter_var(
    $runtime['reopen_live_enabled'] ?? false,
    FILTER_VALIDATE_BOOLEAN
);
$liveMaxBatchSize = max(
    1,
    (int) ($runtime['reopen_live_max_batch_size'] ?? 1)
);
$liveRequireItemId = filter_var(
    $runtime['reopen_live_require_item_id'] ?? true,
    FILTER_VALIDATE_BOOLEAN
);

if (! $isCli) {
    authorizeOrchestratorHttp($executionKey);
}

$storageRoot     = __DIR__ . '/storage/reopen_orchestrator';
$sendStorageRoot = __DIR__ . '/storage/reopen_send_runs';
$logDir          = __DIR__ . '/logs';
$statePath       = $storageRoot . '/state.json';
$lockPath        = $storageRoot . '/orchestrator.lock';
$logPath         = $logDir . '/ghl_reopen_orchestrator_v1.log';

ensureOrchestratorDirectory($storageRoot);
ensureOrchestratorDirectory($logDir);

$action             = strtolower(trim((string) ($_GET['action'] ?? '')));
$requestedSendRunId = trim((string) ($_GET['send_run_id'] ?? ''));

switch ($action) {
    case 'selftest':
        orchestratorSelfTest();
        break;

    case 'status':
        orchestratorStatus(
            statePath: $statePath,
            automationEnabled: $automationEnabled,
            liveEnabled: $liveEnabled,
            automationBaseUrl: $automationBaseUrl,
            universeScript: $universeScript,
            senderScript: $senderScript
        );
        break;

    case 'run':
        orchestratorRun(
            statePath: $statePath,
            lockPath: $lockPath,
            logPath: $logPath,
            sendStorageRoot: $sendStorageRoot,
            executionKey: $executionKey,
            automationEnabled: $automationEnabled,
            automationBaseUrl: $automationBaseUrl,
            universeScript: $universeScript,
            senderScript: $senderScript,
            universeBatchSize: $universeBatchSize,
            maxSendItemsPerInvocation: $maxSendItemsPerInvocation,
            maxRuntimeSeconds: $maxRuntimeSeconds,
            endpointTimeoutSeconds: $endpointTimeoutSeconds,
            liveEnabled: $liveEnabled,
            liveMaxBatchSize: $liveMaxBatchSize,
            liveRequireItemId: $liveRequireItemId,
            runToCompletion: $runToCompletion,
            responseMode: 'orchestrator_run',
            metrics: $metrics
        );
        break;

    case 'resume_send_run':
        orchestratorResumeSendRun(
            sendRunId: $requestedSendRunId,
            statePath: $statePath,
            lockPath: $lockPath,
            logPath: $logPath,
            sendStorageRoot: $sendStorageRoot,
            executionKey: $executionKey,
            automationEnabled: $automationEnabled,
            automationBaseUrl: $automationBaseUrl,
            universeScript: $universeScript,
            senderScript: $senderScript,
            maxSendItemsPerInvocation: $maxSendItemsPerInvocation,
            maxRuntimeSeconds: $maxRuntimeSeconds,
            endpointTimeoutSeconds: $endpointTimeoutSeconds,
            liveEnabled: $liveEnabled,
            liveMaxBatchSize: $liveMaxBatchSize,
            liveRequireItemId: $liveRequireItemId,
            runToCompletion: $runToCompletion
        );
        break;

    default:
        orchestratorRespond([
            'ok'              => false,
            'reason'          => 'invalid_action',
            'allowed_actions' => ['selftest', 'status', 'resume_send_run', 'run'],
            'cli_examples'    => [
                'php ghl_reopen_orchestrator_v1.php action=selftest',
                'php ghl_reopen_orchestrator_v1.php action=status',
                'php ghl_reopen_orchestrator_v1.php action=resume_send_run send_run_id=RUN_ID',
                'php ghl_reopen_orchestrator_v1.php action=run',
            ],
        ], 400);
}

function orchestratorResumeSendRun(
    string $sendRunId,
    string $statePath,
    string $lockPath,
    string $logPath,
    string $sendStorageRoot,
    string $executionKey,
    bool $automationEnabled,
    string $automationBaseUrl,
    string $universeScript,
    string $senderScript,
    int $maxSendItemsPerInvocation,
    int $maxRuntimeSeconds,
    int $endpointTimeoutSeconds,
    bool $liveEnabled,
    int $liveMaxBatchSize,
    bool $liveRequireItemId,
    bool $runToCompletion
): never {
    $startedAt = microtime(true);

    validateAutomationPreflight(
        executionKey: $executionKey,
        automationEnabled: $automationEnabled,
        automationBaseUrl: $automationBaseUrl,
        universeScript: $universeScript,
        senderScript: $senderScript,
        liveEnabled: $liveEnabled,
        liveMaxBatchSize: $liveMaxBatchSize,
        liveRequireItemId: $liveRequireItemId
    );

    if (! preg_match('/^\d{8}_\d{6}_[a-f0-9]{4}$/', $sendRunId)) {
        orchestratorRespond([
            'ok'     => false,
            'mode'   => 'orchestrator_resume_send_run',
            'reason' => 'invalid_send_run_id',
        ], 400);
    }

    $lock = acquireOrchestratorLock($lockPath);

    if ($lock === null) {
        orchestratorRespond([
            'ok'     => false,
            'mode'   => 'orchestrator_resume_send_run',
            'reason' => 'orchestrator_already_running',
        ], 409);
    }

    try {
        $state = loadOrchestratorState($statePath);

        /*
         * Si ya existe un ciclo activo, sólo permitimos continuar si es
         * exactamente este mismo send_run en fase send.
         */
        if (! empty($state['active_cycle'])) {

            if (! activeCycleMatchesSendRun($state, $sendRunId)) {
                releaseOrchestratorLock($lock);

                orchestratorRespond([
                    'ok'           => false,
                    'mode'         => 'orchestrator_resume_send_run',
                    'reason'       => 'different_active_cycle_exists',
                    'active_cycle' => $state['active_cycle'],
                ], 409);
            }

            if (! empty($state['blocked'])) {
                releaseOrchestratorLock($lock);

                orchestratorRespond([
                    'ok'           => false,
                    'mode'         => 'orchestrator_resume_send_run',
                    'status'       => 'review_required',
                    'reason'       => 'orchestrator_is_blocked',
                    'blocked'      => $state['blocked'],
                    'active_cycle' => $state['active_cycle'],
                ], 409);
            }

            $metrics = [
                'universe_http_calls'     => 0,
                'sender_http_calls'       => 0,
                'send_items_processed'    => 0,
                'messages_sent'           => 0,
                'ledger_records_modified' => 0,
            ];

            runSendPhase(
                state: $state,
                statePath: $statePath,
                lock: $lock,
                logPath: $logPath,
                sendStorageRoot: $sendStorageRoot,
                executionKey: $executionKey,
                automationBaseUrl: $automationBaseUrl,
                senderScript: $senderScript,
                endpointTimeoutSeconds: $endpointTimeoutSeconds,
                startedAt: $startedAt,
                maxRuntimeSeconds: $maxRuntimeSeconds,
                maxSendItemsPerInvocation: $maxSendItemsPerInvocation,
                runToCompletion: $runToCompletion,
                metrics: $metrics
            );
        }

        if (! empty($state['blocked'])) {
            releaseOrchestratorLock($lock);

            orchestratorRespond([
                'ok'      => false,
                'mode'    => 'orchestrator_resume_send_run',
                'status'  => 'review_required',
                'reason'  => 'orchestrator_is_blocked',
                'blocked' => $state['blocked'],
            ], 409);
        }

        /*
         * Validación read-only contra el sender antes de adoptar el run.
         */
        $status = callAutomationEndpoint(
            url: buildAutomationUrl(
                $automationBaseUrl,
                $senderScript
            ),
            query: [
                'action'      => 'status',
                'send_run_id' => $sendRunId,
            ],
            executionKey: $executionKey,
            timeoutSeconds: $endpointTimeoutSeconds
        );

        requireSuccessfulEndpointResponse(
            $status,
            expectedMode: 'send_status',
            phase: 'resume_send_status'
        );

        $statusJson = $status['json'];

        assertAdoptableSendRunStatus($statusJson);

        /*
         * Si ya terminó, no modificamos el state del orchestrator.
         */
        if (($statusJson['complete'] ?? false) === true) {
            releaseOrchestratorLock($lock);

            orchestratorRespond([
                'ok'           => true,
                'mode'         => 'orchestrator_resume_send_run',
                'status'       => 'already_complete',
                'side_effects' => false,
                'send_run_id'  => $sendRunId,
                'summary'      => $statusJson['summary'] ?? [],
            ], 200);
        }

        $sourceRunId = trim((string) (
            $statusJson['source_run_id'] ?? ''
        ));

        if ($sourceRunId === '') {
            throw new RuntimeException(
                'El send run no contiene source_run_id.'
            );
        }

        /*
         * Adopción. A partir de aquí el run queda bajo control
         * persistente del orchestrator.
         */
        $state['active_cycle'] = [
            'started_at'                => date(DATE_ATOM),
            'local_date'                => date('Y-m-d'),
            'phase'                     => 'send',
            'universe_run_id'           => $sourceRunId,
            'send_run_id'               => $sendRunId,
            'last_progress_at'          => date(DATE_ATOM),
            'adopted_existing_send_run' => true,
        ];

        $state['blocked']    = null;
        $state['updated_at'] = date(DATE_ATOM);

        writeOrchestratorJson($statePath, $state);

        orchestratorLog(
            $logPath,
            "SEND_RUN_ADOPTED send_run_id={$sendRunId} source_run_id={$sourceRunId}"
        );

        $metrics = [
            'universe_http_calls'     => 0,
            'sender_http_calls'       => 1,
            'send_items_processed'    => 0,
            'messages_sent'           => 0,
            'ledger_records_modified' => 0,
        ];

        runSendPhase(
            state: $state,
            statePath: $statePath,
            lock: $lock,
            logPath: $logPath,
            sendStorageRoot: $sendStorageRoot,
            executionKey: $executionKey,
            automationBaseUrl: $automationBaseUrl,
            senderScript: $senderScript,
            endpointTimeoutSeconds: $endpointTimeoutSeconds,
            startedAt: $startedAt,
            maxRuntimeSeconds: $maxRuntimeSeconds,
            maxSendItemsPerInvocation: $maxSendItemsPerInvocation,
            runToCompletion: $runToCompletion,
            metrics: $metrics
        );
    } catch (OrchestratorBlockedException $exception) {
        orchestratorLog(
            $logPath,
            'BLOCKED message='
            . sanitizeOrchestratorLog($exception->getMessage())
        );

        releaseOrchestratorLock($lock);

        $state = loadOrchestratorState($statePath);

        orchestratorRespond([
            'ok'           => false,
            'mode'         => 'orchestrator_resume_send_run',
            'status'       => 'review_required',
            'reason'       => $state['blocked']['reason'] ?? 'blocked',
            'blocked'      => $state['blocked'] ?? null,
            'active_cycle' => $state['active_cycle'] ?? null,
            'message'      => $exception->getMessage(),
        ], 409);
    } catch (Throwable $exception) {
        orchestratorLog(
            $logPath,
            'FATAL_RESUME message='
            . sanitizeOrchestratorLog($exception->getMessage())
        );

        releaseOrchestratorLock($lock);

        orchestratorRespond([
            'ok'     => false,
            'mode'   => 'orchestrator_resume_send_run',
            'status' => 'error',
            'error'  => $exception->getMessage(),
        ], 500);
    }
}

function orchestratorRun(
    string $statePath,
    string $lockPath,
    string $logPath,
    string $sendStorageRoot,
    string $executionKey,
    bool $automationEnabled,
    string $automationBaseUrl,
    string $universeScript,
    string $senderScript,
    int $universeBatchSize,
    int $maxSendItemsPerInvocation,
    int $maxRuntimeSeconds,
    int $endpointTimeoutSeconds,
    bool $liveEnabled,
    int $liveMaxBatchSize,
    bool $liveRequireItemId,
    bool $runToCompletion
): never {
    $startedAt = microtime(true);

    validateAutomationPreflight(
        executionKey: $executionKey,
        automationEnabled: $automationEnabled,
        automationBaseUrl: $automationBaseUrl,
        universeScript: $universeScript,
        senderScript: $senderScript,
        liveEnabled: $liveEnabled,
        liveMaxBatchSize: $liveMaxBatchSize,
        liveRequireItemId: $liveRequireItemId
    );

    $lock = acquireOrchestratorLock($lockPath);
    if ($lock === null) {
        orchestratorRespond([
            'ok'     => false,
            'mode'   => 'orchestrator_run',
            'reason' => 'orchestrator_already_running',
        ], 409);
    }

    try {
        $state = loadOrchestratorState($statePath);
        $today = date('Y-m-d');

        if (
            empty($state['active_cycle'])
            && ($state['last_completed_local_date'] ?? null) === $today
        ) {
            releaseOrchestratorLock($lock);
            orchestratorRespond([
                'ok'                => true,
                'mode'              => 'orchestrator_run',
                'status'            => 'already_completed_today',
                'side_effects'      => false,
                'local_date'        => $today,
                'last_completed_at' => $state['last_completed_at'] ?? null,
                'last_summary'      => $state['last_summary'] ?? null,
            ], 200);
        }

        if (! empty($state['blocked'])) {
            releaseOrchestratorLock($lock);
            orchestratorRespond([
                'ok'           => false,
                'mode'         => 'orchestrator_run',
                'status'       => 'review_required',
                'reason'       => 'orchestrator_is_blocked',
                'blocked'      => $state['blocked'],
                'active_cycle' => $state['active_cycle'] ?? null,
                'message'      => 'Revisión manual requerida. No se crea ni reintenta otro ciclo automáticamente.',
            ], 409);
        }

        $metrics = [
            'universe_http_calls'     => 0,
            'sender_http_calls'       => 0,
            'send_items_processed'    => 0,
            'messages_sent'           => 0,
            'ledger_records_modified' => 0,
        ];

        if (empty($state['active_cycle'])) {
            $createUniverse = callAutomationEndpoint(
                url: buildAutomationUrl($automationBaseUrl, $universeScript),
                query: ['action' => 'create_snapshot'],
                executionKey: $executionKey,
                timeoutSeconds: $endpointTimeoutSeconds
            );
            $metrics['universe_http_calls']++;

            requireSuccessfulEndpointResponse(
                $createUniverse,
                expectedMode: 'snapshot_created',
                phase: 'create_universe'
            );

            $universeRunId = trim((string) (
                $createUniverse['json']['run_id'] ?? ''
            ));
            if ($universeRunId === '') {
                throw new RuntimeException('Universe no devolvió run_id.');
            }

            $state['active_cycle'] = [
                'started_at'       => date(DATE_ATOM),
                'local_date'       => $today,
                'phase'            => 'universe',
                'universe_run_id'  => $universeRunId,
                'send_run_id'      => null,
                'last_progress_at' => date(DATE_ATOM),
            ];
            $state['updated_at'] = date(DATE_ATOM);
            writeOrchestratorJson($statePath, $state);
            orchestratorLog($logPath, "CYCLE_START universe_run_id={$universeRunId}");
        }

        // Fase Universe: reanuda hasta completar o agotar presupuesto temporal.
        if (($state['active_cycle']['phase'] ?? '') === 'universe') {
            $universeRunId = (string) $state['active_cycle']['universe_run_id'];

            while (true) {
                if (! $runToCompletion
                    && elapsedSeconds($startedAt) >= $maxRuntimeSeconds) {
                    $state['active_cycle']['last_progress_at'] = date(DATE_ATOM);
                    $state['updated_at']                       = date(DATE_ATOM);
                    writeOrchestratorJson($statePath, $state);
                    releaseOrchestratorLock($lock);
                    orchestratorRespond([
                        'ok'           => true,
                        'mode'         => 'orchestrator_run',
                        'status'       => 'partial_time_budget',
                        'phase'        => 'universe',
                        'active_cycle' => $state['active_cycle'],
                        'metrics'      => $metrics,
                    ], 200);
                }

                $batch = callAutomationEndpoint(
                    url: buildAutomationUrl($automationBaseUrl, $universeScript),
                    query: [
                        'action'     => 'process_batch',
                        'run_id'     => $universeRunId,
                        'batch_size' => $universeBatchSize,
                    ],
                    executionKey: $executionKey,
                    timeoutSeconds: $endpointTimeoutSeconds
                );
                $metrics['universe_http_calls']++;

                requireSuccessfulEndpointResponse(
                    $batch,
                    expectedMode: 'process_batch',
                    phase: 'process_universe'
                );

                $summary    = $batch['json']['summary'] ?? [];
                $errorCount = (int) ($summary['state']['error'] ?? 0);
                if ($errorCount > 0) {
                    blockOrchestrator(
                        state: $state,
                        statePath: $statePath,
                        phase: 'universe',
                        reason: 'universe_contains_errors',
                        details: [
                            'universe_run_id' => $universeRunId,
                            'error_count'     => $errorCount,
                        ]
                    );
                    throw new OrchestratorBlockedException('Universe contiene errores y requiere revisión manual.');
                }

                $state['active_cycle']['last_progress_at'] = date(DATE_ATOM);
                $state['updated_at']                       = date(DATE_ATOM);
                writeOrchestratorJson($statePath, $state);

                if (($batch['json']['complete'] ?? false) === true) {
                    break;
                }

                if ((int) ($batch['json']['processed_this_batch'] ?? 0) === 0) {
                    throw new RuntimeException('Universe no avanzó y todavía no está completo.');
                }
            }

            $createSend = callAutomationEndpoint(
                url: buildAutomationUrl($automationBaseUrl, $senderScript),
                query: [
                    'action'        => 'create_run',
                    'source_run_id' => $universeRunId,
                ],
                executionKey: $executionKey,
                timeoutSeconds: $endpointTimeoutSeconds
            );
            $metrics['sender_http_calls']++;

            requireSuccessfulEndpointResponse(
                $createSend,
                expectedMode: 'send_run_created',
                phase: 'create_send_run'
            );

            $sendJson = $createSend['json'];
            if (($sendJson['dry_run_only'] ?? true) !== false) {
                throw new RuntimeException('El send run creado no es live-capable (dry_run_only=true).');
            }
            if (($sendJson['live_enabled_at_creation'] ?? false) !== true) {
                throw new RuntimeException('El send run no congeló live_enabled_at_creation=true.');
            }
            if ((int) ($sendJson['live_max_batch_size'] ?? 0) !== 1) {
                throw new RuntimeException('El send run no congeló live_max_batch_size=1.');
            }
            if (($sendJson['live_require_send_item_id'] ?? false) !== true) {
                throw new RuntimeException('El send run no exige send_item_id explícito.');
            }

            $sendRunId = trim((string) ($sendJson['send_run_id'] ?? ''));
            if ($sendRunId === '') {
                throw new RuntimeException('Sender no devolvió send_run_id.');
            }

            $state['active_cycle']['phase']            = 'send';
            $state['active_cycle']['send_run_id']      = $sendRunId;
            $state['active_cycle']['last_progress_at'] = date(DATE_ATOM);
            $state['updated_at']                       = date(DATE_ATOM);
            writeOrchestratorJson($statePath, $state);
            orchestratorLog(
                $logPath,
                "UNIVERSE_COMPLETE universe_run_id={$universeRunId} send_run_id={$sendRunId}"
            );
        }

        // Fase Send: un item explícito por request. Conserva la policy live más restrictiva.
        if (($state['active_cycle']['phase'] ?? '') === 'send') {
            runSendPhase(
                state: $state,
                statePath: $statePath,
                lock: $lock,
                logPath: $logPath,
                sendStorageRoot: $sendStorageRoot,
                executionKey: $executionKey,
                automationBaseUrl: $automationBaseUrl,
                senderScript: $senderScript,
                endpointTimeoutSeconds: $endpointTimeoutSeconds,
                startedAt: $startedAt,
                maxRuntimeSeconds: $maxRuntimeSeconds,
                maxSendItemsPerInvocation: $maxSendItemsPerInvocation,
                runToCompletion: $runToCompletion,
                metrics: $metrics
            );
        }

        throw new RuntimeException('Fase de orquestación desconocida.');
    } catch (OrchestratorBlockedException $exception) {
        orchestratorLog($logPath, 'BLOCKED message=' . sanitizeOrchestratorLog($exception->getMessage()));
        releaseOrchestratorLock($lock);
        $state = loadOrchestratorState($statePath);
        orchestratorRespond([
            'ok'           => false,
            'mode'         => 'orchestrator_run',
            'status'       => 'review_required',
            'reason'       => $state['blocked']['reason'] ?? 'blocked',
            'blocked'      => $state['blocked'] ?? null,
            'active_cycle' => $state['active_cycle'] ?? null,
            'message'      => $exception->getMessage(),
        ], 409);
    } catch (Throwable $exception) {
        orchestratorLog($logPath, 'FATAL message=' . sanitizeOrchestratorLog($exception->getMessage()));
        releaseOrchestratorLock($lock);
        orchestratorRespond([
            'ok'      => false,
            'mode'    => 'orchestrator_run',
            'status'  => 'error',
            'error'   => $exception->getMessage(),
            'message' => 'No se crea un ciclo nuevo mientras exista active_cycle; la próxima ejecución reanudará el estado guardado.',
        ], 500);
    }
}

function runSendPhase(
    array &$state,
    string $statePath,
    $lock,
    string $logPath,
    string $sendStorageRoot,
    string $executionKey,
    string $automationBaseUrl,
    string $senderScript,
    int $endpointTimeoutSeconds,
    float $startedAt,
    int $maxRuntimeSeconds,
    int $maxSendItemsPerInvocation,
    bool $runToCompletion,
    string $responseMode,
    array &$metrics
): never {
    $sendRunId = trim((string) (
        $state['active_cycle']['send_run_id'] ?? ''
    ));

    if ($sendRunId === '') {
        throw new RuntimeException(
            'La fase send no contiene send_run_id.'
        );
    }

    $processedThisInvocation = 0;

    while (true) {
        if (
            ! $runToCompletion
            && (
                elapsedSeconds($startedAt) >= $maxRuntimeSeconds
                || $processedThisInvocation >= $maxSendItemsPerInvocation
            )
        ) {
            $state['active_cycle']['last_progress_at'] = date(DATE_ATOM);
            $state['updated_at']                       = date(DATE_ATOM);
            writeOrchestratorJson($statePath, $state);

            releaseOrchestratorLock($lock);

            orchestratorRespond([
                'ok'           => true,
                'mode'         => $responseMode,
                'status'       => 'partial_send_budget',
                'phase'        => 'send',
                'active_cycle' => $state['active_cycle'],
                'metrics'      => $metrics,
                'message'      => 'La siguiente ejecución reanudará el mismo send_run; no se crea otro ciclo.',
            ], 200);
        }

        $pendingItemId = firstPendingSendItemId(
            $sendStorageRoot,
            $sendRunId
        );

        if ($pendingItemId === null) {
            $status = callAutomationEndpoint(
                url: buildAutomationUrl(
                    $automationBaseUrl,
                    $senderScript
                ),
                query: [
                    'action'      => 'status',
                    'send_run_id' => $sendRunId,
                ],
                executionKey: $executionKey,
                timeoutSeconds: $endpointTimeoutSeconds
            );

            $metrics['sender_http_calls']++;

            requireSuccessfulEndpointResponse(
                $status,
                expectedMode: 'send_status',
                phase: 'send_status'
            );

            if (($status['json']['complete'] ?? false) !== true) {
                throw new RuntimeException(
                    'No hay pending items pero el sender no reporta complete=true.'
                );
            }

            $summary = $status['json']['summary'] ?? [];

            $state['last_completed_at']         = date(DATE_ATOM);
            $state['last_completed_local_date'] = date('Y-m-d');
            $state['last_summary']              = [
                'universe_run_id'         =>
                $state['active_cycle']['universe_run_id'] ?? null,
                'send_run_id'             => $sendRunId,
                'sender_summary'          => $summary,
                'metrics_this_invocation' => $metrics,
            ];

            $state['active_cycle'] = null;
            $state['blocked']      = null;
            $state['updated_at']   = date(DATE_ATOM);

            writeOrchestratorJson($statePath, $state);

            orchestratorLog(
                $logPath,
                "CYCLE_COMPLETE send_run_id={$sendRunId} messages_sent="
                . (int) ($summary['messages_sent'] ?? 0)
            );

            releaseOrchestratorLock($lock);

            orchestratorRespond([
                'ok'           => true,
                'mode'         => $responseMode,
                'status'       => 'complete',
                'side_effects' =>
                ((int) ($summary['messages_sent'] ?? 0)) > 0,
                'completed_at' => $state['last_completed_at'],
                'summary'      => $state['last_summary'],
            ], 200);
        }

        $send = callAutomationEndpoint(
            url: buildAutomationUrl(
                $automationBaseUrl,
                $senderScript
            ),
            query: [
                'action'       => 'process_batch',
                'send_run_id'  => $sendRunId,
                'batch_size'   => 1,
                'live'         => 1,
                'send_item_id' => $pendingItemId,
            ],
            executionKey: $executionKey,
            timeoutSeconds: $endpointTimeoutSeconds
        );

        $metrics['sender_http_calls']++;

        /*
         * Una falla de transporte en una llamada live es ambigua.
         * No se reintenta automáticamente.
         */
        if (($send['transport_ok'] ?? false) !== true) {
            blockOrchestrator(
                state: $state,
                statePath: $statePath,
                phase: 'send',
                reason: 'sender_transport_ambiguous_no_auto_retry',
                details: [
                    'send_run_id'  => $sendRunId,
                    'send_item_id' => $pendingItemId,
                    'curl_error'   => $send['curl_error'] ?? null,
                ]
            );

            throw new OrchestratorBlockedException(
                'La llamada live al sender fue ambigua. No se reintenta automáticamente.'
            );
        }

        if (($send['json']['ok'] ?? false) !== true) {
            blockOrchestrator(
                state: $state,
                statePath: $statePath,
                phase: 'send',
                reason: 'sender_returned_error_no_auto_retry',
                details: [
                    'send_run_id'     => $sendRunId,
                    'send_item_id'    => $pendingItemId,
                    'http_status'     => $send['http_status'] ?? null,
                    'response_reason' =>
                    $send['json']['reason'] ?? null,
                    'response_error'  =>
                    $send['json']['error'] ?? null,
                ]
            );

            throw new OrchestratorBlockedException(
                'Sender devolvió error durante una ejecución live. No se reintenta automáticamente.'
            );
        }

        if (
            ($send['json']['mode'] ?? '')
            !== 'process_send_batch'
        ) {
            throw new RuntimeException(
                'Respuesta inesperada del sender live.'
            );
        }

        $processed = (int) (
            $send['json']['processed_this_batch'] ?? 0
        );

        if ($processed !== 1) {
            throw new RuntimeException(
                'Sender live no procesó exactamente un item explícito.'
            );
        }

        $metrics['send_items_processed']++;

        $metrics['messages_sent'] += (int) (
            $send['json']['messages_sent'] ?? 0
        );

        $metrics['ledger_records_modified'] += (int) (
            $send['json']['ledger_records_modified'] ?? 0
        );

        $processedThisInvocation++;

        $state['active_cycle']['last_progress_at'] =
            date(DATE_ATOM);

        $state['active_cycle']['last_send_item_id'] =
            $pendingItemId;

        $state['updated_at'] = date(DATE_ATOM);

        writeOrchestratorJson($statePath, $state);
    }
}

function assertAdoptableSendRunStatus(array $statusJson): void
{
    if (($statusJson['live_capable'] ?? false) !== true) {
        throw new RuntimeException(
            'El send run no pertenece a un sender live-capable.'
        );
    }

    if (($statusJson['live_enabled_now'] ?? false) !== true) {
        throw new RuntimeException(
            'Live está deshabilitado actualmente.'
        );
    }

    if (($statusJson['run_dry_run_only'] ?? true) !== false) {
        throw new RuntimeException(
            'El send run fue creado como dry-run-only.'
        );
    }

    $livePolicy = is_array($statusJson['live_policy'] ?? null)
        ? $statusJson['live_policy']
        : [];

    if (($livePolicy['enabled_at_creation'] ?? false) !== true) {
        throw new RuntimeException(
            'El send run no fue creado con live habilitado.'
        );
    }

    if ((int) ($livePolicy['max_batch_size'] ?? 0) !== 1) {
        throw new RuntimeException(
            'El send run no congeló max_batch_size=1.'
        );
    }

    if (($livePolicy['require_send_item_id'] ?? false) !== true) {
        throw new RuntimeException(
            'El send run no exige send_item_id explícito.'
        );
    }

    if (
        ($livePolicy['post_retry_policy'] ?? '')
        !== 'never_automatic'
    ) {
        throw new RuntimeException(
            'El send run no conserva la política sin retry automático.'
        );
    }
}

function activeCycleMatchesSendRun(
    array $state,
    string $sendRunId
): bool {
    if (empty($state['active_cycle'])) {
        return false;
    }

    return
        ($state['active_cycle']['phase'] ?? '') === 'send'
        && ($state['active_cycle']['send_run_id'] ?? '') === $sendRunId;
}

function orchestratorSelfTest(): never
{
    $tests = [];
    $add   = static function (string $name, mixed $actual, mixed $expected) use (&$tests): void {
        $tests[] = [
            'name'     => $name,
            'pass'     => $actual === $expected,
            'expected' => $expected,
            'actual'   => $actual,
        ];
    };

    $add(
        'build_url_encodes_query',
        buildEndpointUrl('https://example.test/base/script.php', [
            'action'       => 'process_batch',
            'send_item_id' => 'send_abc',
            'live'         => 1,
        ]),
        'https://example.test/base/script.php?action=process_batch&send_item_id=send_abc&live=1'
    );

    $state = defaultOrchestratorState();
    $add('default_state_has_no_active_cycle', $state['active_cycle'], null);
    $add('default_state_has_no_block', $state['blocked'], null);

    $sample = [
        'items' => [
            'send_b' => ['status' => 'processed'],
            'send_c' => ['status' => 'pending'],
            'send_a' => ['status' => 'pending'],
        ],
    ];
    $add('first_pending_is_deterministic', firstPendingFromState($sample), 'send_a');

    $sample['items']['send_a']['status'] = 'processing';
    $add('processing_is_not_selected_as_pending', firstPendingFromState($sample), 'send_c');

    $sample['items']['send_c']['status'] = 'processed';
    $add('no_pending_returns_null', firstPendingFromState($sample), null);

    $add(
        'https_base_url_valid',
        validateAutomationBaseUrlValue('https://estrategiaurbana.info/sendpulse/automation'),
        true
    );
    $add(
        'http_base_url_rejected',
        validateAutomationBaseUrlValue('http://example.test/automation'),
        false
    );

    $validSendStatus = [
        'live_capable'     => true,
        'live_enabled_now' => true,
        'run_dry_run_only' => false,
        'live_policy'      => [
            'enabled_at_creation'  => true,
            'max_batch_size'       => 1,
            'require_send_item_id' => true,
            'post_retry_policy'    => 'never_automatic',
        ],
    ];

    $validStatusAccepted = true;

    try {
        assertAdoptableSendRunStatus($validSendStatus);
    } catch (Throwable) {
        $validStatusAccepted = false;
    }

    $add(
        'valid_live_send_run_is_adoptable',
        $validStatusAccepted,
        true
    );

    $dryStatus                     = $validSendStatus;
    $dryStatus['run_dry_run_only'] = true;

    $dryStatusRejected = false;

    try {
        assertAdoptableSendRunStatus($dryStatus);
    } catch (RuntimeException) {
        $dryStatusRejected = true;
    }

    $add(
        'dry_run_only_send_run_is_rejected',
        $dryStatusRejected,
        true
    );

    $retryPolicyStatus                                     = $validSendStatus;
    $retryPolicyStatus['live_policy']['post_retry_policy'] = 'automatic';

    $retryPolicyRejected = false;

    try {
        assertAdoptableSendRunStatus($retryPolicyStatus);
    } catch (RuntimeException) {
        $retryPolicyRejected = true;
    }

    $add(
        'automatic_post_retry_policy_is_rejected',
        $retryPolicyRejected,
        true
    );

    $resumeState = defaultOrchestratorState();

    $resumeState['active_cycle'] = [
        'phase'       => 'send',
        'send_run_id' => '20260916_144100_442a',
    ];

    $add(
        'same_active_send_run_can_resume',
        activeCycleMatchesSendRun(
            $resumeState,
            '20260916_144100_442a'
        ),
        true
    );

    $add(
        'different_active_send_run_is_rejected',
        activeCycleMatchesSendRun(
            $resumeState,
            '20260917_120000_abcd'
        ),
        false
    );

    $failed = count(array_filter($tests, static fn(array $t): bool => ! $t['pass']));

    orchestratorRespond([
        'ok'            => $failed === 0,
        'mode'          => 'selftest',
        'dry_run'       => true,
        'side_effects'  => false,
        'network_calls' => 0,
        'tests_total'   => count($tests),
        'passed'        => count($tests) - $failed,
        'failed'        => $failed,
        'tests'         => $tests,
    ], $failed === 0 ? 200 : 500);
}

function orchestratorStatus(
    string $statePath,
    bool $automationEnabled,
    bool $liveEnabled,
    string $automationBaseUrl,
    string $universeScript,
    string $senderScript
): never {
    orchestratorRespond([
        'ok'                 => true,
        'mode'               => 'orchestrator_status',
        'automation_enabled' => $automationEnabled,
        'live_enabled'       => $liveEnabled,
        'endpoints'          => [
            'base_url'        => $automationBaseUrl,
            'universe_script' => $universeScript,
            'sender_script'   => $senderScript,
            'universe_url'    => buildAutomationUrl($automationBaseUrl, $universeScript),
            'sender_url'      => buildAutomationUrl($automationBaseUrl, $senderScript),
        ],
        'qa_only'            => [
            'sender_selftest_script' => 'ghl_reopen_send_selftest.php',
        ],
        'legacy_not_used'    => [
            'ghl_reopen_send_v1.php',
        ],
        'state'              => loadOrchestratorState($statePath),
    ], 200);
}

function validateAutomationPreflight(
    string $executionKey,
    bool $automationEnabled,
    string $automationBaseUrl,
    string $universeScript,
    string $senderScript,
    bool $liveEnabled,
    int $liveMaxBatchSize,
    bool $liveRequireItemId
): void {
    if (! $automationEnabled) {
        orchestratorRespond([
            'ok'      => false,
            'mode'    => 'preflight_validation',
            'reason'  => 'reopen_automation_disabled',
            'message' => 'Activa runtime.reopen_automation_enabled sólo después de validar selftest/status.',
        ], 409);
    }
    if ($executionKey === '') {
        throw new RuntimeException('Falta ghl.execution_key.');
    }
    if (! validateAutomationBaseUrlValue($automationBaseUrl)) {
        throw new RuntimeException('reopen_automation_base_url debe ser una URL HTTPS válida.');
    }
    if ($universeScript === '' || str_contains($universeScript, '/')) {
        throw new RuntimeException('reopen_automation_universe_script inválido.');
    }
    if ($senderScript === '' || str_contains($senderScript, '/')) {
        throw new RuntimeException('reopen_automation_sender_script inválido.');
    }
    if (! $liveEnabled) {
        throw new RuntimeException('reopen_live_enabled debe estar en true para ejecutar el orquestador productivo.');
    }
    if ($liveMaxBatchSize !== 1) {
        throw new RuntimeException('V1 del orquestador exige reopen_live_max_batch_size=1.');
    }
    if (! $liveRequireItemId) {
        throw new RuntimeException('V1 del orquestador exige reopen_live_require_item_id=true.');
    }
}

function callAutomationEndpoint(
    string $url,
    array $query,
    string $executionKey,
    int $timeoutSeconds
): array {
    $requestUrl = buildEndpointUrl($url, $query);
    $ch         = curl_init($requestUrl);
    if ($ch === false) {
        return [
            'transport_ok' => false,
            'http_status'  => 0,
            'curl_error'   => 'curl_init_failed',
            'json'         => null,
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(15, $timeoutSeconds),
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'X-Reopen-Key: ' . $executionKey,
        ],
    ]);

    $body       = curl_exec($ch);
    $curlErrno  = curl_errno($ch);
    $curlError  = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $curlErrno !== 0) {
        return [
            'transport_ok' => false,
            'http_status'  => $httpStatus,
            'curl_errno'   => $curlErrno,
            'curl_error'   => $curlError !== '' ? $curlError : 'transport_error',
            'json'         => null,
        ];
    }

    $json = json_decode((string) $body, true);
    if (! is_array($json)) {
        return [
            'transport_ok' => true,
            'http_status'  => $httpStatus,
            'curl_error'   => null,
            'json'         => [
                'ok'     => false,
                'reason' => 'invalid_json_response',
            ],
        ];
    }

    return [
        'transport_ok' => true,
        'http_status'  => $httpStatus,
        'curl_error'   => null,
        'json'         => $json,
    ];
}

function requireSuccessfulEndpointResponse(
    array $response,
    string $expectedMode,
    string $phase
): void {
    if (($response['transport_ok'] ?? false) !== true) {
        throw new RuntimeException(
            "{$phase}: error de transporte: "
            . (string) ($response['curl_error'] ?? 'unknown')
        );
    }
    if (($response['json']['ok'] ?? false) !== true) {
        throw new RuntimeException(
            "{$phase}: endpoint devolvió error: "
            . (string) (
                $response['json']['reason'] ?? $response['json']['error'] ?? 'unknown'
            )
        );
    }
    if (($response['json']['mode'] ?? '') !== $expectedMode) {
        throw new RuntimeException(
            "{$phase}: mode inesperado: "
            . (string) ($response['json']['mode'] ?? 'missing')
        );
    }
}

function firstPendingSendItemId(string $sendStorageRoot, string $sendRunId): ?string
{
    if (! preg_match('/^\d{8}_\d{6}_[a-f0-9]{4}$/', $sendRunId)) {
        throw new RuntimeException('send_run_id inválido al leer state.json.');
    }
    $path = $sendStorageRoot . '/' . $sendRunId . '/state.json';
    if (! is_file($path)) {
        throw new RuntimeException('No existe state.json del send run activo.');
    }
    $state = readOrchestratorJson($path);
    return firstPendingFromState($state);
}

function firstPendingFromState(array $state): ?string
{
    $pending = [];
    foreach (($state['items'] ?? []) as $itemId => $itemState) {
        if (is_array($itemState) && ($itemState['status'] ?? '') === 'pending') {
            $pending[] = (string) $itemId;
        }
    }
    if ($pending === []) {
        return null;
    }
    sort($pending, SORT_STRING);
    return $pending[0];
}

function blockOrchestrator(
    array &$state,
    string $statePath,
    string $phase,
    string $reason,
    array $details
): void {
    $state['blocked'] = [
        'at'      => date(DATE_ATOM),
        'phase'   => $phase,
        'reason'  => $reason,
        'details' => $details,
    ];
    $state['updated_at'] = date(DATE_ATOM);
    writeOrchestratorJson($statePath, $state);
}

function defaultOrchestratorState(): array
{
    return [
        'schema_version'            => 1,
        'created_at'                => date(DATE_ATOM),
        'updated_at'                => date(DATE_ATOM),
        'active_cycle'              => null,
        'blocked'                   => null,
        'last_completed_at'         => null,
        'last_completed_local_date' => null,
        'last_summary'              => null,
    ];
}

function loadOrchestratorState(string $path): array
{
    if (! is_file($path)) {
        return defaultOrchestratorState();
    }
    $state = readOrchestratorJson($path);
    return array_merge(defaultOrchestratorState(), $state);
}

function readOrchestratorJson(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('No se pudo leer ' . basename($path));
    }
    $decoded = json_decode($raw, true);
    if (! is_array($decoded)) {
        throw new RuntimeException('JSON inválido en ' . basename($path));
    }
    return $decoded;
}

function writeOrchestratorJson(string $path, array $data): void
{
    $dir = dirname($path);
    ensureOrchestratorDirectory($dir);
    $tmp  = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($json === false) {
        throw new RuntimeException('No se pudo codificar JSON del orquestador.');
    }
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo escribir temporal de ' . basename($path));
    }
    if (! rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('No se pudo reemplazar atómicamente ' . basename($path));
    }
}

function ensureOrchestratorDirectory(string $dir): void
{
    if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
        throw new RuntimeException("No se pudo crear directorio: {$dir}");
    }

    $htaccess = $dir . '/.htaccess';
    if (! is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Require all denied\nDeny from all\n"
        );
    }
}

function acquireOrchestratorLock(string $path)
{
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('No se pudo abrir lock del orquestador.');
    }
    if (! flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }
    return $handle;
}

function releaseOrchestratorLock($handle): void
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

function buildAutomationUrl(string $baseUrl, string $script): string
{
    return rtrim($baseUrl, '/') . '/' . rawurlencode($script);
}

function buildEndpointUrl(string $url, array $query): string
{
    return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function validateAutomationBaseUrlValue(string $url): bool
{
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $parts = parse_url($url);
    return is_array($parts)
    && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
    && trim((string) ($parts['host'] ?? '')) !== '';
}

function elapsedSeconds(float $startedAt): float
{
    return microtime(true) - $startedAt;
}

function parseCliArguments(array $argv): void
{
    foreach (array_slice($argv, 1) as $arg) {
        if (! str_contains((string) $arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', (string) $arg, 2);
        $key           = trim($key);
        if ($key !== '') {
            $_GET[$key] = $value;
        }
    }
}

function authorizeOrchestratorHttp(string $configuredKey): void
{
    if ($configuredKey === '') {
        orchestratorRespond(['ok' => false, 'reason' => 'missing_execution_key'], 500);
    }

    $provided = '';
    if (isset($_SERVER['HTTP_X_REOPEN_KEY'])) {
        $provided = trim((string) $_SERVER['HTTP_X_REOPEN_KEY']);
    }
    if ($provided === '' && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'X-Reopen-Key') === 0) {
                $provided = trim((string) $value);
                break;
            }
        }
    }

    if ($provided === '' || ! hash_equals($configuredKey, $provided)) {
        orchestratorRespond(['ok' => false, 'reason' => 'unauthorized'], 401);
    }
}

function orchestratorLog(string $path, string $message): void
{
    $line = '[' . date(DATE_ATOM) . '] ' . $message . PHP_EOL;
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function sanitizeOrchestratorLog(string $value): string
{
    return preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;
}

function orchestratorRespond(array $payload, int $statusCode = 200): never
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($statusCode);
    }
    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit;
}

final class OrchestratorBlockedException extends RuntimeException
{
}
