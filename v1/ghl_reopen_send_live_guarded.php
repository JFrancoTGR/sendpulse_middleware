<?php
declare(strict_types=1);

/**
 * GHL Reopen Send V1.2 — LIVE GUARDED.
 *
 * Fuente:
 * - run completo de ghl_reopen_universe_query_v1.php
 * - exclusivamente contactos tipo A: open + Enfriado + nl_decision=Interesado
 *
 * Secuencia:
 * - se lee y congela desde runtime.reopen_sequence en config.php
 * - cada step se calcula contra los días desde la entrada a Enfriado
 * - el sender nunca salta steps: un backlog histórico comienza en reopen_1
 * - máximo un template Reopen por ciclo y día calendario
 *
 * Salidas detectables en V1:
 * - stage_changed: deja Enfriado / deja de estar open / cambia de pipeline
 * - not_interested: nl_decision cambia a "No interesado"
 * - sequence_complete: los cuatro steps ya fueron enviados
 *
 * IMPORTANTE:
 * - Dry run sigue siendo el modo por defecto.
 * - Live exige runtime.reopen_live_enabled=true Y ?live=1.
 * - En live controlado puede exigir send_item_id y limitar batch_size.
 * - El POST de template se ejecuta UNA sola vez; no hay retry automático.
 * - Sólo HTTP 2xx + success===true marca el step como sent.
 * - Respuestas ambiguas o fallos explícitos bloquean el ciclo como review_required.
 * - GHL nunca se modifica desde este sender.
 *
 * Acciones HTTP:
 * - action=create_run&source_run_id=RUN_ID
 * - action=process_batch&send_run_id=RUN_ID&batch_size=10
 * - action=status&send_run_id=RUN_ID
 * - action=selftest  (sin red, sin writes)
 * - live: agregar ?live=1; requiere feature flag habilitado en config.
 */

const CONFIG_PATH = __DIR__ . '/config.php';
const SOURCE_STORAGE_RELATIVE = '/storage/reopen_runs';
const SEND_STORAGE_RELATIVE = '/storage/reopen_send_runs';
const LEDGER_STORAGE_RELATIVE = '/storage/reopen_ledger';
const LEDGER_FILENAME = 'ledger.json';
const TEMPLATE_LANGUAGE = 'es';
const TEMPLATE_PARAMETER_NAME = 'nombre_proyecto';
const LIVE_CAPABLE = true;

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    ignore_user_abort(true);
    set_time_limit(0);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

if (!is_file(CONFIG_PATH)) {
    respondAndExit([
        'ok' => false,
        'reason' => 'config_not_found',
        'path' => basename(CONFIG_PATH),
    ], 500);
}

try {
    $config = require CONFIG_PATH;
} catch (Throwable $exception) {
    respondAndExit([
        'ok' => false,
        'mode' => 'configuration_load',
        'dry_run' => true,
        'reason' => 'configuration_load_failed',
        'message' => $exception->getMessage(),
    ], 500);
}

if (!is_array($config)) {
    respondAndExit([
        'ok' => false,
        'reason' => 'invalid_config',
        'message' => 'config.php debe devolver un arreglo.',
    ], 500);
}

$ghl = is_array($config['ghl'] ?? null) ? $config['ghl'] : [];
$sendpulse = is_array($config['sendpulse'] ?? null) ? $config['sendpulse'] : [];
$runtime = is_array($config['runtime'] ?? null) ? $config['runtime'] : [];
$projects = is_array($config['projects'] ?? null) ? $config['projects'] : [];

$ghlToken = trim((string) ($ghl['token'] ?? ''));
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
$spFlowInterestedId = trim((string) (
    $sendpulse['flow_interesado_id']
    ?? $sendpulse['flow_interested_id']
    ?? ''
));
$spFlowNotInterestedId = trim((string) (
    $sendpulse['flow_no_interesado_id']
    ?? $sendpulse['flow_not_interested_id']
    ?? ''
));
$spRequestDelayMs = max(0, (int) ($sendpulse['request_delay_ms'] ?? 125));

$timezone = trim((string) ($runtime['timezone'] ?? 'America/Mexico_City'));
$httpTimeout = max(1, (int) ($runtime['http_timeout_seconds'] ?? 35));
$httpConnectTimeout = max(1, (int) ($runtime['http_connect_timeout_seconds'] ?? 10));
$httpMaxAttempts = max(1, (int) ($runtime['http_max_attempts'] ?? 3));
$defaultBatchSize = max(1, (int) ($runtime['reopen_send_default_batch_size'] ?? 10));
$maxBatchSize = max($defaultBatchSize, (int) ($runtime['reopen_send_max_batch_size'] ?? 25));
$processingStaleMinutes = max(1, (int) ($runtime['reopen_send_processing_stale_minutes'] ?? 30));
$reopenMinDaysInStage = max(0, (int) ($runtime['reopen_min_days_in_stage'] ?? 0));
$reopenLiveEnabled = filter_var(
    $runtime['reopen_live_enabled'] ?? false,
    FILTER_VALIDATE_BOOLEAN
);
$reopenLiveMaxBatchSize = max(1, (int) ($runtime['reopen_live_max_batch_size'] ?? 1));
$reopenLiveRequireItemId = filter_var(
    $runtime['reopen_live_require_item_id'] ?? true,
    FILTER_VALIDATE_BOOLEAN
);

try {
    $reopenSequence = normalizeReopenSequenceConfig(
        is_array($runtime['reopen_sequence'] ?? null)
            ? $runtime['reopen_sequence']
            : []
    );
} catch (Throwable $exception) {
    respondAndExit([
        'ok' => false,
        'mode' => 'preflight_validation',
        'dry_run' => true,
        'reason' => 'invalid_reopen_sequence',
        'error' => $exception->getMessage(),
    ], 500);
}

if ($timezone === '') {
    $timezone = 'America/Mexico_City';
}
date_default_timezone_set($timezone);

if (!$isCli) {
    authorizeHttpExecution($executionKey);
}

try {
    validateConfig(
        ghlToken: $ghlToken,
        ghlBaseUrl: $ghlBaseUrl,
        projects: $projects,
        spBaseUrl: $spBaseUrl,
        spAuthMode: $spAuthMode,
        spClientId: $spClientId,
        spClientSecret: $spClientSecret,
        spApiKey: $spApiKey,
        spBotId: $spBotId,
        spDecisionVariable: $spDecisionVariable,
        spFlowInterestedId: $spFlowInterestedId,
        spFlowNotInterestedId: $spFlowNotInterestedId,
        reopenMinDaysInStage: $reopenMinDaysInStage,
        reopenSequence: $reopenSequence
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

$sourceStorageRoot = __DIR__ . SOURCE_STORAGE_RELATIVE;
$sendStorageRoot = __DIR__ . SEND_STORAGE_RELATIVE;
$ledgerStorageRoot = __DIR__ . LEDGER_STORAGE_RELATIVE;
$ledgerPath = $ledgerStorageRoot . '/' . LEDGER_FILENAME;
$logDir = __DIR__ . '/logs';
$globalLogPath = $logDir . '/ghl_reopen_send_v1.log';

ensureProtectedDirectory($sourceStorageRoot);
ensureProtectedDirectory($sendStorageRoot);
ensureProtectedDirectory($ledgerStorageRoot);
ensureProtectedDirectory($logDir);

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$sourceRunId = trim((string) ($_GET['source_run_id'] ?? ''));
$sendRunId = trim((string) ($_GET['send_run_id'] ?? ''));
$retryErrors = queryBoolean('retry_errors');
$debug = queryBoolean('debug');
$liveRequested = queryBoolean('live');
$selectedSendItemId = trim((string) ($_GET['send_item_id'] ?? ''));

$batchSizeRequested = isset($_GET['batch_size'])
    ? (int) $_GET['batch_size']
    : $defaultBatchSize;
$batchSize = min($maxBatchSize, max(1, $batchSizeRequested));

switch ($action) {
    case 'selftest':
        reopenSelfTestAction($reopenSequence);
        break;

    case 'create_run':
        createSendRunAction(
            sourceStorageRoot: $sourceStorageRoot,
            sendStorageRoot: $sendStorageRoot,
            globalLogPath: $globalLogPath,
            sourceRunId: $sourceRunId,
            projects: $projects,
            reopenMinDaysInStage: $reopenMinDaysInStage,
            reopenSequence: $reopenSequence,
            reopenLiveEnabled: $reopenLiveEnabled,
            reopenLiveMaxBatchSize: $reopenLiveMaxBatchSize,
            reopenLiveRequireItemId: $reopenLiveRequireItemId
        );
        break;

    case 'process_batch':
        processSendBatchAction(
            sendStorageRoot: $sendStorageRoot,
            ledgerPath: $ledgerPath,
            globalLogPath: $globalLogPath,
            sendRunId: $sendRunId,
            batchSize: $batchSize,
            retryErrors: $retryErrors,
            debug: $debug,
            liveRequested: $liveRequested,
            selectedSendItemId: $selectedSendItemId,
            reopenLiveEnabled: $reopenLiveEnabled,
            reopenLiveMaxBatchSize: $reopenLiveMaxBatchSize,
            reopenLiveRequireItemId: $reopenLiveRequireItemId,
            processingStaleMinutes: $processingStaleMinutes,
            ghlToken: $ghlToken,
            ghlBaseUrl: $ghlBaseUrl,
            ghlApiVersion: $ghlApiVersion,
            projects: $projects,
            spBaseUrl: $spBaseUrl,
            spAuthMode: $spAuthMode,
            spClientId: $spClientId,
            spClientSecret: $spClientSecret,
            spApiKey: $spApiKey,
            spBotId: $spBotId,
            spDecisionVariable: $spDecisionVariable,
            spFlowInterestedId: $spFlowInterestedId,
            spFlowNotInterestedId: $spFlowNotInterestedId,
            spRequestDelayMs: $spRequestDelayMs,
            httpTimeout: $httpTimeout,
            httpConnectTimeout: $httpConnectTimeout,
            httpMaxAttempts: $httpMaxAttempts
        );
        break;

    case 'status':
        sendStatusAction(
            sendStorageRoot: $sendStorageRoot,
            sendRunId: $sendRunId,
            reopenLiveEnabled: $reopenLiveEnabled
        );
        break;

    default:
        respondAndExit([
            'ok' => false,
            'reason' => 'invalid_action',
            'live_capable' => LIVE_CAPABLE,
            'live_enabled' => $reopenLiveEnabled,
            'allowed_actions' => ['create_run', 'process_batch', 'status', 'selftest'],
            'examples' => [
                '?action=create_run&source_run_id=RUN_ID',
                '?action=process_batch&send_run_id=RUN_ID&batch_size=10',
                '?action=status&send_run_id=RUN_ID',
                '?action=selftest',
            ],
        ], 400);
}

/*
|--------------------------------------------------------------------------
| Self-test de máquina de estados (sin red / sin writes)
|--------------------------------------------------------------------------
*/

function reopenSelfTestAction(array $sequence): never
{
    $referenceAt = '2026-08-20T14:00:00-06:00';
    $previousDay = '2026-08-19T14:00:00-06:00';
    $sameDay = '2026-08-20T09:00:00-06:00';

    $sent = static function (array $steps, string $lastSentAt = ''): array {
        $entry = ['status' => 'active', 'steps' => []];
        foreach ($steps as $step) {
            $entry['steps'][(string) $step] = [
                'status' => 'sent',
                'template' => 'reopen_' . $step,
                'sent_at' => '2026-08-19T10:00:00-06:00',
            ];
        }
        if ($lastSentAt !== '') {
            $entry['last_sent_at'] = $lastSentAt;
        }
        return $entry;
    };

    $tests = [];
    $add = static function (string $name, mixed $actual, mixed $expected) use (&$tests): void {
        $tests[] = [
            'name' => $name,
            'pass' => $actual === $expected,
            'expected' => $expected,
            'actual' => $actual,
        ];
    };

    $r = determineNextReopenStep($sequence, [], 1.99, $referenceAt);
    $add('before_day_2_waits', [$r['status'] ?? null, $r['step'] ?? null], ['waiting', 1]);

    $r = determineNextReopenStep($sequence, [], 2.00, $referenceAt);
    $add('day_2_starts_reopen_1', [$r['status'] ?? null, $r['step'] ?? null, $r['template'] ?? null], ['ready', 1, 'reopen_1']);

    $r = determineNextReopenStep($sequence, [], 92.10, $referenceAt);
    $add('historical_backlog_never_skips_step_1', [$r['status'] ?? null, $r['step'] ?? null], ['ready', 1]);

    $r = determineNextReopenStep($sequence, $sent([1], $previousDay), 3.99, $referenceAt);
    $add('step_1_waits_until_day_4', [$r['status'] ?? null, $r['step'] ?? null], ['waiting', 2]);

    $r = determineNextReopenStep($sequence, $sent([1], $previousDay), 4.00, $referenceAt);
    $add('day_4_advances_to_reopen_2', [$r['status'] ?? null, $r['step'] ?? null, $r['template'] ?? null], ['ready', 2, 'reopen_2']);

    $r = determineNextReopenStep($sequence, $sent([1], $sameDay), 4.00, $referenceAt);
    $add('same_calendar_day_blocks_second_reopen', [$r['status'] ?? null, $r['reason'] ?? null], ['waiting', 'one_reopen_per_calendar_day']);

    $r = determineNextReopenStep($sequence, $sent([1, 2], $previousDay), 5.99, $referenceAt);
    $add('steps_1_2_wait_until_day_6', [$r['status'] ?? null, $r['step'] ?? null], ['waiting', 3]);

    $r = determineNextReopenStep($sequence, $sent([1, 2], $previousDay), 6.00, $referenceAt);
    $add('day_6_advances_to_reopen_3', [$r['status'] ?? null, $r['step'] ?? null, $r['template'] ?? null], ['ready', 3, 'reopen_3']);

    $r = determineNextReopenStep($sequence, $sent([1, 2, 3], $previousDay), 7.00, $referenceAt);
    $add('day_7_advances_to_reopen_4', [$r['status'] ?? null, $r['step'] ?? null, $r['template'] ?? null], ['ready', 4, 'reopen_4']);

    $r = determineNextReopenStep($sequence, $sent([1, 2, 3, 4], $previousDay), 30.00, $referenceAt);
    $add('all_four_steps_are_sequence_complete', [$r['status'] ?? null, $r['terminal_status'] ?? null], ['terminal', 'sequence_complete']);

    foreach (['stage_changed', 'not_interested', 'sequence_complete'] as $terminal) {
        $r = determineNextReopenStep($sequence, ['status' => $terminal, 'steps' => []], 30.00, $referenceAt);
        $add('ledger_terminal_' . $terminal, [$r['status'] ?? null, $r['terminal_status'] ?? null], ['terminal', $terminal]);
    }

    $r = determineNextReopenStep($sequence, $sent([1, 3], $previousDay), 30.00, $referenceAt);
    $add('non_contiguous_ledger_requires_review', [$r['status'] ?? null, $r['reason'] ?? null], ['review_required', 'ledger_non_contiguous_steps']);

    $r = determineNextReopenStep(
        $sequence,
        $sent([1], '2026-08-19T23:55:00-06:00'),
        4.00,
        '2026-08-20T00:05:00-06:00'
    );
    $add('next_calendar_day_allows_next_step', [$r['status'] ?? null, $r['step'] ?? null], ['ready', 2]);

    $preview = buildLedgerWritePreview(
        currentEntry: $sent([1, 2, 3], $previousDay),
        cycleId: 'cycle_selftest',
        opportunityId: 'opp_selftest',
        projectKey: 'juarez',
        lastStageChangeAt: '2026-08-13T14:00:00-06:00',
        stepNumber: 4,
        templateName: 'reopen_4',
        sendRunId: 'run_selftest',
        sendItemId: 'item_selftest',
        finalStepNumber: 4
    );
    $wouldWrite = is_array($preview['would_write'] ?? null) ? $preview['would_write'] : [];
    $add('step_4_preview_marks_sequence_complete', [$wouldWrite['status'] ?? null, $wouldWrite['last_step_sent'] ?? null], ['sequence_complete', 4]);

    $cycleA = buildReopenCycleId('opp_a', '2026-08-10T10:00:00Z');
    $cycleA2 = buildReopenCycleId('opp_a', '2026-08-10T10:00:00Z');
    $cycleB = buildReopenCycleId('opp_a', '2026-08-20T10:00:00Z');
    $add('cycle_id_is_deterministic', $cycleA === $cycleA2, true);
    $add('reentry_creates_new_cycle_id', $cycleA !== $cycleB, true);

    $projectFixture = [
        'pipeline_id' => 'pipeline_expected',
        'stages' => [
            'enfriado' => ['ghl_stage_id' => 'stage_expected'],
        ],
    ];

    $ghlStageChanged = evaluateCurrentGhlEligibility(
        lookup: [
            'status' => 'found',
            'http_status' => 200,
            'opportunity' => [
                'pipelineId' => 'pipeline_expected',
                'pipelineStageId' => 'stage_other',
                'status' => 'open',
                'lastStageChangeAt' => date(DATE_ATOM, time() - 5 * 86400),
            ],
        ],
        projectKey: 'selftest',
        projectConfig: $projectFixture,
        minDaysInStage: 2
    );
    $add('ghl_stage_change_is_terminal', [$ghlStageChanged['status'] ?? null, $ghlStageChanged['terminal_status'] ?? null], ['terminal', 'stage_changed']);

    $ghlClosed = evaluateCurrentGhlEligibility(
        lookup: [
            'status' => 'found',
            'http_status' => 200,
            'opportunity' => [
                'pipelineId' => 'pipeline_expected',
                'pipelineStageId' => 'stage_expected',
                'status' => 'won',
                'lastStageChangeAt' => date(DATE_ATOM, time() - 5 * 86400),
            ],
        ],
        projectKey: 'selftest',
        projectConfig: $projectFixture,
        minDaysInStage: 2
    );
    $add('ghl_not_open_is_terminal', [$ghlClosed['status'] ?? null, $ghlClosed['terminal_status'] ?? null], ['terminal', 'stage_changed']);

    $spNo = evaluateCurrentSendPulseEligibility(
        lookup: [
            'status' => 'found',
            'http_status' => 200,
            'contact' => [
                'id' => 'sp_selftest',
                'variables' => ['nl_decision' => 'No interesado'],
                'tags' => [],
            ],
        ],
        decisionVariable: 'nl_decision'
    );
    $add('sendpulse_no_interesado_is_terminal', [$spNo['status'] ?? null, $spNo['terminal_status'] ?? null], ['terminal', 'not_interested']);

    $spYes = evaluateCurrentSendPulseEligibility(
        lookup: [
            'status' => 'found',
            'http_status' => 200,
            'contact' => [
                'id' => 'sp_selftest',
                'variables' => ['nl_decision' => 'Interesado'],
                'tags' => [],
            ],
        ],
        decisionVariable: 'nl_decision'
    );
    $add('sendpulse_interesado_remains_eligible', $spYes['status'] ?? null, 'eligible');

    $r = determineNextReopenStep(
        $sequence,
        ['status' => 'review_required', 'review_reason' => 'sendpulse_ambiguous_no_auto_retry', 'steps' => []],
        30.00,
        $referenceAt
    );
    $add('ledger_review_required_blocks_automatic_retry', [$r['status'] ?? null, $r['reason'] ?? null], ['review_required', 'sendpulse_ambiguous_no_auto_retry']);

    $confirmed = classifySendPulseTemplateResponse([
        'status' => 200,
        'decoded' => ['success' => true, 'message' => 'ok'],
        'error' => '',
        'duration_ms' => 10,
    ]);
    $add('send_response_requires_success_true', [$confirmed['classification'] ?? null, $confirmed['confirmed_sent'] ?? null], ['confirmed_success', true]);

    $explicit = classifySendPulseTemplateResponse([
        'status' => 400,
        'decoded' => ['success' => false, 'message' => 'invalid'],
        'error' => '',
        'duration_ms' => 10,
    ]);
    $add('send_response_success_false_is_explicit_failure', [$explicit['classification'] ?? null, $explicit['review_required'] ?? null], ['explicit_failure', true]);

    $ambiguous2xx = classifySendPulseTemplateResponse([
        'status' => 200,
        'decoded' => ['message' => 'accepted'],
        'error' => '',
        'duration_ms' => 10,
    ]);
    $add('send_response_2xx_without_success_is_ambiguous', [$ambiguous2xx['classification'] ?? null, $ambiguous2xx['review_required'] ?? null], ['ambiguous', true]);

    $ambiguousTimeout = classifySendPulseTemplateResponse([
        'status' => 0,
        'decoded' => null,
        'error' => 'Operation timed out',
        'duration_ms' => 35000,
    ]);
    $add('send_response_transport_error_is_ambiguous', [$ambiguousTimeout['classification'] ?? null, $ambiguousTimeout['review_required'] ?? null], ['ambiguous', true]);

    $reservation = buildLedgerSendReservationEntry(
        currentEntry: [],
        cycleId: 'cycle_live_selftest',
        opportunityId: 'opp_live_selftest',
        projectKey: 'juarez',
        lastStageChangeAt: '2026-08-15T10:00:00-06:00',
        stepNumber: 1,
        templateName: 'reopen_1',
        sendRunId: 'run_live_selftest',
        sendItemId: 'item_live_selftest',
        reservedAt: $referenceAt
    );
    $add('live_reservation_blocks_before_post', [$reservation['status'] ?? null, $reservation['steps']['1']['status'] ?? null], ['review_required', 'sending']);

    $sentEntry = buildLedgerSentEntry(
        currentEntry: $reservation,
        cycleId: 'cycle_live_selftest',
        opportunityId: 'opp_live_selftest',
        projectKey: 'juarez',
        lastStageChangeAt: '2026-08-15T10:00:00-06:00',
        stepNumber: 1,
        templateName: 'reopen_1',
        sendRunId: 'run_live_selftest',
        sendItemId: 'item_live_selftest',
        finalStepNumber: 4,
        sentAt: $referenceAt
    );
    $add('confirmed_send_releases_cycle_to_active', [$sentEntry['status'] ?? null, $sentEntry['steps']['1']['status'] ?? null], ['active', 'sent']);

    $blockedEntry = buildLedgerBlockedAttemptEntry(
        currentEntry: $reservation,
        cycleId: 'cycle_live_selftest',
        opportunityId: 'opp_live_selftest',
        projectKey: 'juarez',
        lastStageChangeAt: '2026-08-15T10:00:00-06:00',
        stepNumber: 1,
        templateName: 'reopen_1',
        sendRunId: 'run_live_selftest',
        sendItemId: 'item_live_selftest',
        attemptStatus: 'ambiguous',
        reviewReason: 'sendpulse_ambiguous_no_auto_retry',
        attemptedAt: $referenceAt,
        sendResult: $ambiguousTimeout
    );
    $add('ambiguous_send_remains_blocked', [$blockedEntry['status'] ?? null, $blockedEntry['steps']['1']['status'] ?? null], ['review_required', 'ambiguous']);

    $payloadFixture = buildReopenTemplatePayload(
        templateName: 'reopen_1',
        botId: 'bot_selftest',
        phone: '525512345678',
        projectName: 'SENNSE Juárez',
        flowInterestedId: 'flow_yes',
        flowNotInterestedId: 'flow_no'
    );
    $storedPayload = sanitizeTemplatePayloadForStorage($payloadFixture);
    $add('stored_template_payload_masks_phone', $storedPayload['phone'] ?? null, '********5678');

    $passed = count(array_filter($tests, static fn(array $t): bool => $t['pass'] === true));
    $failed = count($tests) - $passed;

    respondAndExit([
        'ok' => $failed === 0,
        'mode' => 'selftest',
        'dry_run' => true,
        'dry_run_only' => true,
        'side_effects' => false,
        'network_calls' => 0,
        'tests_total' => count($tests),
        'passed' => $passed,
        'failed' => $failed,
        'reopen_sequence' => array_values($sequence),
        'tests' => $tests,
    ], $failed === 0 ? 200 : 500);
}

/*
|--------------------------------------------------------------------------
| Acciones
|--------------------------------------------------------------------------
*/

function createSendRunAction(
    string $sourceStorageRoot,
    string $sendStorageRoot,
    string $globalLogPath,
    string $sourceRunId,
    array $projects,
    int $reopenMinDaysInStage,
    array $reopenSequence,
    bool $reopenLiveEnabled,
    int $reopenLiveMaxBatchSize,
    bool $reopenLiveRequireItemId
): never {
    validateRunIdOrExit($sourceRunId, 'source_run_id');

    $sourcePaths = sourceRunPaths($sourceStorageRoot, $sourceRunId);
    assertFilesExist($sourcePaths, ['snapshot', 'state', 'results']);

    $sourceSnapshot = loadJsonFile($sourcePaths['snapshot']);
    $sourceState = loadJsonFile($sourcePaths['state']);
    $sourceResults = loadJsonFile($sourcePaths['results']);
    $sourceSummary = summarizeState($sourceState);

    if (
        $sourceSummary['pending'] > 0
        || $sourceSummary['processing'] > 0
        || $sourceSummary['error'] > 0
    ) {
        respondAndExit([
            'ok' => false,
            'reason' => 'source_run_not_complete',
            'source_run_id' => $sourceRunId,
            'source_state' => $sourceSummary,
        ], 409);
    }

    $sourceStageKeys = $sourceSnapshot['filters']['stage_keys'] ?? [];
    if ($sourceStageKeys !== ['enfriado']) {
        respondAndExit([
            'ok' => false,
            'reason' => 'source_run_not_enfriado_only',
            'source_run_id' => $sourceRunId,
            'stage_keys' => $sourceStageKeys,
        ], 409);
    }

    $sourceMinDays = max(0, (int) (
        $sourceSnapshot['filters']['reopen_min_days_in_stage'] ?? 0
    ));

    if ($sourceMinDays !== $reopenMinDaysInStage) {
        respondAndExit([
            'ok' => false,
            'reason' => 'source_run_reopen_min_days_mismatch',
            'source_run_id' => $sourceRunId,
            'source_reopen_min_days_in_stage' => $sourceMinDays,
            'config_reopen_min_days_in_stage' => $reopenMinDaysInStage,
            'message' => 'Crea un snapshot nuevo con el config actual antes de crear el send run.',
        ], 409);
    }

    $firstStep = reset($reopenSequence);
    $firstDay = (int) ($firstStep['day_in_enfriado'] ?? -1);
    if ($sourceMinDays !== $firstDay) {
        respondAndExit([
            'ok' => false,
            'reason' => 'source_run_first_step_day_mismatch',
            'source_reopen_min_days_in_stage' => $sourceMinDays,
            'first_sequence_day' => $firstDay,
        ], 409);
    }

    $eligibleItems = [];
    $projectCounts = [];

    foreach (($sourceResults['items'] ?? []) as $sourceItemId => $resultItem) {
        if (!is_array($resultItem)) {
            continue;
        }

        if (($resultItem['evaluation']['status'] ?? '') !== 'eligible') {
            continue;
        }

        if (($resultItem['evaluation']['reopen_segment'] ?? '') !== 'A_interested_confirmed') {
            continue;
        }

        $opportunities = $resultItem['ghl_opportunities'] ?? [];
        if (!is_array($opportunities) || count($opportunities) !== 1) {
            continue;
        }

        $opportunity = $opportunities[0];
        if (!is_array($opportunity)) {
            continue;
        }

        $projectKey = trim((string) ($opportunity['project_key'] ?? ''));
        $projectConfig = $projects[$projectKey] ?? null;
        if (!is_array($projectConfig)) {
            throw new RuntimeException(
                "El proyecto {$projectKey} del source run no existe en config.php."
            );
        }

        $phone = normalizePhone((string) ($resultItem['phone_normalized'] ?? ''));
        $opportunityId = trim((string) ($opportunity['opportunity_id'] ?? ''));
        $lastStageChangeAt = trim((string) ($opportunity['last_stage_change_at'] ?? ''));

        if ($phone === '' || $opportunityId === '' || $lastStageChangeAt === '') {
            continue;
        }

        $sendItemId = 'send_' . hash('sha256', $sourceRunId . '|' . $sourceItemId);
        $cycleIdSnapshot = buildReopenCycleId($opportunityId, $lastStageChangeAt);

        $eligibleItems[$sendItemId] = [
            'send_item_id' => $sendItemId,
            'source_item_id' => (string) $sourceItemId,
            'source_run_id' => $sourceRunId,
            'source_processed_at' => $resultItem['processed_at'] ?? null,
            'phone_normalized' => $phone,
            'sendpulse_contact_id_snapshot' => $resultItem['sendpulse']['contact_id'] ?? '',
            'source_decision' => $resultItem['sendpulse']['nl_decision_normalized'] ?? '',
            'source_reopen_segment' => $resultItem['evaluation']['reopen_segment'] ?? '',
            'opportunity' => $opportunity,
            'cycle_id_snapshot' => $cycleIdSnapshot,
            'template_project_name' => resolveProjectTemplateName(
                projectKey: $projectKey,
                projectConfig: $projectConfig
            ),
        ];

        incrementCounter($projectCounts, $projectKey);
    }

    if ($eligibleItems === []) {
        respondAndExit([
            'ok' => false,
            'reason' => 'source_run_has_no_type_a_eligible_items',
            'source_run_id' => $sourceRunId,
        ], 409);
    }

    ksort($eligibleItems, SORT_STRING);
    ksort($projectCounts, SORT_STRING);

    $createLock = acquireExclusiveLock($sendStorageRoot . '/create_send_run.lock');
    if ($createLock === null) {
        respondAndExit([
            'ok' => false,
            'reason' => 'send_run_creation_already_running',
        ], 409);
    }

    $sendRunId = date('Ymd_His') . '_' . bin2hex(random_bytes(2));
    $paths = sendRunPaths($sendStorageRoot, $sendRunId);

    try {
        ensureProtectedDirectory($paths['dir']);
        $createdAt = date(DATE_ATOM);
        $stateItems = [];

        foreach ($eligibleItems as $sendItemId => $_item) {
            $stateItems[$sendItemId] = [
                'status' => 'pending',
                'attempts' => 0,
                'started_at' => null,
                'finished_at' => null,
                'last_error' => null,
            ];
        }

        $manifest = [
            'schema_version' => 2,
            'send_run_id' => $sendRunId,
            'created_at' => $createdAt,
            'dry_run_only' => !$reopenLiveEnabled,
            'live_capable' => LIVE_CAPABLE,
            'live_policy' => [
                'enabled_at_creation' => $reopenLiveEnabled,
                'max_batch_size' => $reopenLiveMaxBatchSize,
                'require_send_item_id' => $reopenLiveRequireItemId,
                'post_retry_policy' => 'never_automatic',
                'success_contract' => 'http_2xx_and_success_true',
                'ambiguous_contract' => 'block_cycle_review_required',
            ],
            'source_run_id' => $sourceRunId,
            'source_snapshot_created_at' => $sourceSnapshot['created_at'] ?? null,
            'source_reopen_min_days_in_stage' => $sourceMinDays,
            'reopen_sequence' => array_values($reopenSequence),
            'sequence_policy' => [
                'anchor' => 'last_stage_change_at_enfriado',
                'sequential_steps' => true,
                'max_one_send_per_calendar_day' => true,
                'positive_click_detection' => 'not_distinguished_in_v1',
                'negative_exit' => 'nl_decision_no_interesado',
                'stage_exit' => 'ghl_leaves_enfriado',
                'completion' => 'all_steps_sent',
            ],
            'template' => [
                'language' => TEMPLATE_LANGUAGE,
                'body_variables' => [TEMPLATE_PARAMETER_NAME => 'project_name'],
                'buttons' => [0 => 'Interesado', 1 => 'No interesado'],
            ],
            'cohort' => [
                'segment' => 'A_interested_confirmed',
                'total' => count($eligibleItems),
                'by_project' => $projectCounts,
            ],
            'items' => $eligibleItems,
        ];

        $state = [
            'schema_version' => 2,
            'send_run_id' => $sendRunId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'items' => $stateItems,
        ];

        $results = [
            'schema_version' => 2,
            'send_run_id' => $sendRunId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'items' => [],
        ];

        writeJsonAtomically($paths['manifest'], $manifest);
        writeJsonAtomically($paths['state'], $state);
        writeJsonAtomically($paths['results'], $results);

        logLine(
            $paths['log'],
            "CREATE send_run_id={$sendRunId} source_run_id={$sourceRunId} "
            . 'cohort=' . count($eligibleItems)
            . ' min_days=' . $sourceMinDays
            . ' sequence=' . compactSequenceForLog($reopenSequence)
            . ' dry_run_only=' . ($reopenLiveEnabled ? '0' : '1')
        );
        logLine(
            $globalLogPath,
            "CREATE send_run_id={$sendRunId} source_run_id={$sourceRunId} cohort="
            . count($eligibleItems)
        );

        releaseLock($createLock);

        respondAndExit([
            'ok' => true,
            'mode' => 'send_run_created',
            'dry_run' => true,
            'dry_run_only' => !$reopenLiveEnabled,
            'live_capable' => LIVE_CAPABLE,
            'live_enabled_at_creation' => $reopenLiveEnabled,
            'live_max_batch_size' => $reopenLiveMaxBatchSize,
            'live_require_send_item_id' => $reopenLiveRequireItemId,
            'side_effects' => false,
            'source_run_id' => $sourceRunId,
            'send_run_id' => $sendRunId,
            'reopen_min_days_in_stage' => $sourceMinDays,
            'reopen_sequence' => array_values($reopenSequence),
            'cohort' => [
                'segment' => 'A_interested_confirmed',
                'total' => count($eligibleItems),
                'by_project' => $projectCounts,
            ],
            'files' => [
                'manifest' => 'manifest.json',
                'state' => 'state.json',
                'results' => 'results.json',
                'log' => 'run.log',
            ],
            'next_command' => (
                '?action=process_batch&send_run_id='
                . rawurlencode($sendRunId)
                . '&batch_size=10'
            ),
        ], 200);
    } catch (Throwable $exception) {
        releaseLock($createLock);
        logLine(
            $globalLogPath,
            "CREATE_FATAL send_run_id={$sendRunId} message="
            . sanitizeLogValue($exception->getMessage())
        );
        respondAndExit([
            'ok' => false,
            'mode' => 'create_send_run',
            'dry_run' => true,
            'send_run_id' => $sendRunId,
            'error' => $exception->getMessage(),
        ], 500);
    }
}

function processSendBatchAction(
    string $sendStorageRoot,
    string $ledgerPath,
    string $globalLogPath,
    string $sendRunId,
    int $batchSize,
    bool $retryErrors,
    bool $debug,
    bool $liveRequested,
    string $selectedSendItemId,
    bool $reopenLiveEnabled,
    int $reopenLiveMaxBatchSize,
    bool $reopenLiveRequireItemId,
    int $processingStaleMinutes,
    string $ghlToken,
    string $ghlBaseUrl,
    string $ghlApiVersion,
    array $projects,
    string $spBaseUrl,
    string $spAuthMode,
    string $spClientId,
    string $spClientSecret,
    string $spApiKey,
    string $spBotId,
    string $spDecisionVariable,
    string $spFlowInterestedId,
    string $spFlowNotInterestedId,
    int $spRequestDelayMs,
    int $httpTimeout,
    int $httpConnectTimeout,
    int $httpMaxAttempts
): never {
    validateRunIdOrExit($sendRunId, 'send_run_id');

    if ($selectedSendItemId !== '' && !preg_match('/^send_[a-f0-9]{64}$/', $selectedSendItemId)) {
        respondAndExit([
            'ok' => false,
            'reason' => 'invalid_send_item_id',
        ], 400);
    }

    if ($liveRequested) {
        if (!$reopenLiveEnabled) {
            respondAndExit([
                'ok' => false,
                'mode' => 'live_guard',
                'reason' => 'live_not_enabled_in_config',
                'message' => 'Activa runtime.reopen_live_enabled antes de solicitar live=1.',
            ], 403);
        }
        if ($retryErrors) {
            respondAndExit([
                'ok' => false,
                'mode' => 'live_guard',
                'reason' => 'retry_errors_forbidden_in_live',
            ], 400);
        }
        if ($debug) {
            respondAndExit([
                'ok' => false,
                'mode' => 'live_guard',
                'reason' => 'debug_forbidden_in_live',
            ], 400);
        }
        if ($batchSize > $reopenLiveMaxBatchSize) {
            respondAndExit([
                'ok' => false,
                'mode' => 'live_guard',
                'reason' => 'live_batch_size_exceeds_limit',
                'requested_batch_size' => $batchSize,
                'live_max_batch_size' => $reopenLiveMaxBatchSize,
            ], 400);
        }
        if ($reopenLiveRequireItemId && $selectedSendItemId === '') {
            respondAndExit([
                'ok' => false,
                'mode' => 'live_guard',
                'reason' => 'live_send_item_id_required',
            ], 400);
        }
    }

    $dryRun = !$liveRequested;
    $paths = sendRunPaths($sendStorageRoot, $sendRunId);
    assertFilesExist($paths, ['manifest', 'state', 'results']);

    $runLock = acquireExclusiveLock($paths['lock']);
    if ($runLock === null) {
        respondAndExit([
            'ok' => false,
            'reason' => 'send_run_already_processing',
            'send_run_id' => $sendRunId,
        ], 409);
    }

    $startedAt = microtime(true);

    try {
        $manifest = loadJsonFile($paths['manifest']);
        $state = loadJsonFile($paths['state']);
        $results = loadJsonFile($paths['results']);
        $ledger = loadReopenLedger($ledgerPath);

        if ($liveRequested && (($manifest['dry_run_only'] ?? true) === true)) {
            throw new RuntimeException(
                'Este send run fue creado con live deshabilitado. Crea un send run nuevo después de habilitar el feature flag.'
            );
        }

        $manifestLivePolicy = is_array($manifest['live_policy'] ?? null)
            ? $manifest['live_policy']
            : [];
        if ($liveRequested && (($manifestLivePolicy['enabled_at_creation'] ?? false) !== true)) {
            throw new RuntimeException('El manifest no fue creado con live habilitado.');
        }

        $minDaysInStage = max(0, (int) (
            $manifest['source_reopen_min_days_in_stage'] ?? 0
        ));
        $sequence = normalizeReopenSequenceConfig(
            is_array($manifest['reopen_sequence'] ?? null)
                ? $manifest['reopen_sequence']
                : []
        );
        $finalStepNumber = max(array_keys($sequence));

        $recovered = recoverStaleProcessingItems(
            state: $state,
            staleMinutes: $processingStaleMinutes
        );
        if ($recovered > 0) {
            $state['updated_at'] = date(DATE_ATOM);
            writeJsonAtomically($paths['state'], $state);
            logLine($paths['log'], "RECOVER_STALE count={$recovered}");
        }

        $candidateIds = selectBatchItemIds(
            state: $state,
            batchSize: $batchSize,
            retryErrors: $retryErrors,
            selectedItemId: $selectedSendItemId
        );

        if ($selectedSendItemId !== '' && $candidateIds === []) {
            $selectedState = $state['items'][$selectedSendItemId]['status'] ?? null;
            releaseLock($runLock);
            respondAndExit([
                'ok' => false,
                'mode' => 'process_send_batch',
                'dry_run' => $dryRun,
                'reason' => 'selected_send_item_not_processable',
                'send_item_id' => $selectedSendItemId,
                'current_state' => $selectedState,
            ], 409);
        }

        if ($candidateIds === []) {
            $summary = buildSendSummary($manifest, $state, $results);
            releaseLock($runLock);
            respondAndExit([
                'ok' => true,
                'mode' => 'process_send_batch',
                'dry_run' => $dryRun,
                'live' => $liveRequested,
                'side_effects' => false,
                'send_run_id' => $sendRunId,
                'processed_this_batch' => 0,
                'summary' => $summary,
                'complete' => isCompleteState($summary['state']),
            ], 200);
        }

        $manifestItems = is_array($manifest['items'] ?? null)
            ? $manifest['items']
            : [];

        $bearerToken = '';
        $sendPulseAuthCalls = 0;
        $ghlCalls = 0;
        $spLookupCalls = 0;
        $spSendCalls = 0;
        $processedThisBatch = 0;
        $messagesSent = 0;
        $ledgerWrites = 0;
        $ledgerCyclesModified = [];
        $lastSpLookupWasMade = false;

        logLine(
            $paths['log'],
            "BATCH_START send_run_id={$sendRunId} selected="
            . count($candidateIds)
            . " batch_size={$batchSize} mode=" . ($liveRequested ? 'LIVE' : 'DRY_RUN')
            . " retry_errors=" . ($retryErrors ? '1' : '0')
            . ' selected_item=' . sanitizeLogValue($selectedSendItemId)
        );

        foreach ($candidateIds as $sendItemId) {
            $item = $manifestItems[$sendItemId] ?? null;

            if (!is_array($item)) {
                markStateError(
                    state: $state,
                    itemId: $sendItemId,
                    error: 'manifest_item_not_found'
                );
                writeJsonAtomically($paths['state'], $state);
                continue;
            }

            markStateProcessing($state, $sendItemId);
            writeJsonAtomically($paths['state'], $state);

            $phone = normalizePhone((string) ($item['phone_normalized'] ?? ''));
            $opportunitySnapshot = is_array($item['opportunity'] ?? null)
                ? $item['opportunity']
                : [];
            $opportunityId = trim((string) ($opportunitySnapshot['opportunity_id'] ?? ''));
            $projectKey = trim((string) ($opportunitySnapshot['project_key'] ?? ''));
            $snapshotLastStageChangeAt = trim((string) ($opportunitySnapshot['last_stage_change_at'] ?? ''));
            $cycleIdSnapshot = trim((string) ($item['cycle_id_snapshot'] ?? ''));
            $spLookup = [];
            $postAttempted = false;

            $resultItem = [
                'send_item_id' => $sendItemId,
                'source_run_id' => $item['source_run_id'] ?? null,
                'source_item_id' => $item['source_item_id'] ?? null,
                'processed_at' => date(DATE_ATOM),
                'dry_run' => $dryRun,
                'live' => $liveRequested,
                'side_effects' => false,
                'candidate' => [
                    'opportunity_id' => $opportunityId,
                    'contact_id' => $opportunitySnapshot['contact_id'] ?? '',
                    'contact_name' => $opportunitySnapshot['contact_name'] ?? '',
                    'project_key' => $projectKey,
                    'project_name' => $item['template_project_name'] ?? '',
                    'phone_masked' => maskPhone($phone),
                    'template_name' => null,
                    'step' => null,
                ],
                'ghl_revalidation' => null,
                'sendpulse_revalidation' => null,
                'sequence_evaluation' => null,
                'ledger_preview' => null,
                'template_preview' => null,
                'sendpulse_send' => null,
                'decision' => [
                    'status' => 'error',
                    'reason' => 'not_evaluated',
                    'would_send' => false,
                    'sent' => false,
                ],
            ];

            try {
                if ($phone === '' || $opportunityId === '' || $projectKey === '') {
                    throw new RuntimeException('invalid_manifest_candidate');
                }

                $projectConfig = $projects[$projectKey] ?? null;
                if (!is_array($projectConfig)) {
                    throw new RuntimeException('project_not_found_in_config');
                }

                $ghlLookup = getGhlOpportunityById(
                    token: $ghlToken,
                    baseUrl: $ghlBaseUrl,
                    apiVersion: $ghlApiVersion,
                    opportunityId: $opportunityId,
                    httpTimeout: $httpTimeout,
                    httpConnectTimeout: $httpConnectTimeout,
                    httpMaxAttempts: $httpMaxAttempts,
                    logPath: $paths['log']
                );
                $ghlCalls++;

                $ghlEvaluation = evaluateCurrentGhlEligibility(
                    lookup: $ghlLookup,
                    projectKey: $projectKey,
                    projectConfig: $projectConfig,
                    minDaysInStage: $minDaysInStage
                );
                $resultItem['ghl_revalidation'] = $ghlEvaluation;

                if (($ghlEvaluation['status'] ?? '') !== 'eligible') {
                    $resultItem['decision'] = decisionFromGate($ghlEvaluation, 'ghl_not_eligible');

                    if (
                        $liveRequested
                        && ($ghlEvaluation['terminal_status'] ?? '') === 'stage_changed'
                        && $cycleIdSnapshot !== ''
                        && $snapshotLastStageChangeAt !== ''
                    ) {
                        $ledgerLock = acquireExclusiveLock($ledgerPath . '.lock');
                        if ($ledgerLock === null) {
                            throw new RuntimeException('ledger_lock_busy');
                        }
                        try {
                            $ledger = loadReopenLedger($ledgerPath);
                            $existing = is_array($ledger['cycles'][$cycleIdSnapshot] ?? null)
                                ? $ledger['cycles'][$cycleIdSnapshot]
                                : [];
                            $entry = buildLedgerTerminalEntry(
                                currentEntry: $existing,
                                cycleId: $cycleIdSnapshot,
                                opportunityId: $opportunityId,
                                projectKey: $projectKey,
                                lastStageChangeAt: $snapshotLastStageChangeAt,
                                terminalStatus: 'stage_changed',
                                reason: (string) ($ghlEvaluation['reason'] ?? 'ghl_stage_changed'),
                                detectedAt: date(DATE_ATOM)
                            );
                            persistLedgerCycle($ledgerPath, $ledger, $cycleIdSnapshot, $entry);
                            $ledgerWrites++;
                            $ledgerCyclesModified[$cycleIdSnapshot] = true;
                            $resultItem['side_effects'] = true;
                        } finally {
                            releaseLock($ledgerLock);
                        }
                    }
                } else {
                    $currentLastStageChangeAt = trim((string) (
                        $ghlEvaluation['last_stage_change_at'] ?? ''
                    ));
                    $cycleId = buildReopenCycleId(
                        $opportunityId,
                        $currentLastStageChangeAt
                    );
                    $cycleEntry = is_array($ledger['cycles'][$cycleId] ?? null)
                        ? $ledger['cycles'][$cycleId]
                        : [];

                    $sequenceEvaluation = determineNextReopenStep(
                        sequence: $sequence,
                        cycleEntry: $cycleEntry,
                        ageDays: (float) ($ghlEvaluation['age_days'] ?? 0.0),
                        referenceAt: date(DATE_ATOM)
                    );
                    $sequenceEvaluation['cycle_id'] = $cycleId;
                    $sequenceEvaluation['cycle_id_snapshot'] = $cycleIdSnapshot;
                    $resultItem['sequence_evaluation'] = $sequenceEvaluation;

                    if (($sequenceEvaluation['status'] ?? '') !== 'ready') {
                        $resultItem['decision'] = decisionFromGate(
                            $sequenceEvaluation,
                            'sequence_not_ready'
                        );
                    } else {
                        if ($bearerToken === '') {
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
                            $sendPulseAuthCalls += $spAuthMode === 'oauth' ? 1 : 0;
                        }

                        if ($lastSpLookupWasMade && $spRequestDelayMs > 0) {
                            usleep($spRequestDelayMs * 1000);
                        }

                        $spLookup = getSendPulseContactByPhone(
                            bearerToken: $bearerToken,
                            baseUrl: $spBaseUrl,
                            botId: $spBotId,
                            phone: $phone,
                            httpTimeout: $httpTimeout,
                            httpConnectTimeout: $httpConnectTimeout,
                            httpMaxAttempts: $httpMaxAttempts,
                            logPath: $paths['log']
                        );
                        $lastSpLookupWasMade = true;
                        $spLookupCalls++;

                        $spEvaluation = evaluateCurrentSendPulseEligibility(
                            lookup: $spLookup,
                            decisionVariable: $spDecisionVariable
                        );
                        $resultItem['sendpulse_revalidation'] = $spEvaluation;

                        if (($spEvaluation['status'] ?? '') !== 'eligible') {
                            $resultItem['decision'] = decisionFromGate(
                                $spEvaluation,
                                'sendpulse_not_eligible'
                            );

                            if (
                                $liveRequested
                                && ($spEvaluation['terminal_status'] ?? '') === 'not_interested'
                            ) {
                                $ledgerLock = acquireExclusiveLock($ledgerPath . '.lock');
                                if ($ledgerLock === null) {
                                    throw new RuntimeException('ledger_lock_busy');
                                }
                                try {
                                    $ledger = loadReopenLedger($ledgerPath);
                                    $existing = is_array($ledger['cycles'][$cycleId] ?? null)
                                        ? $ledger['cycles'][$cycleId]
                                        : [];
                                    $entry = buildLedgerTerminalEntry(
                                        currentEntry: $existing,
                                        cycleId: $cycleId,
                                        opportunityId: $opportunityId,
                                        projectKey: $projectKey,
                                        lastStageChangeAt: $currentLastStageChangeAt,
                                        terminalStatus: 'not_interested',
                                        reason: (string) ($spEvaluation['reason'] ?? 'sendpulse_not_interested'),
                                        detectedAt: date(DATE_ATOM)
                                    );
                                    persistLedgerCycle($ledgerPath, $ledger, $cycleId, $entry);
                                    $ledgerWrites++;
                                    $ledgerCyclesModified[$cycleId] = true;
                                    $resultItem['side_effects'] = true;
                                } finally {
                                    releaseLock($ledgerLock);
                                }
                            }
                        } else {
                            $projectName = trim((string) ($item['template_project_name'] ?? ''));
                            if ($projectName === '') {
                                $resultItem['decision'] = [
                                    'status' => 'review_required',
                                    'reason' => 'project_template_name_empty',
                                    'would_send' => false,
                                    'sent' => false,
                                ];
                            } else {
                                $stepNumber = (int) ($sequenceEvaluation['step'] ?? 0);
                                $templateName = trim((string) ($sequenceEvaluation['template'] ?? ''));
                                $resultItem['candidate']['template_name'] = $templateName;
                                $resultItem['candidate']['step'] = $stepNumber;

                                $templatePayload = buildReopenTemplatePayload(
                                    templateName: $templateName,
                                    botId: $spBotId,
                                    phone: $phone,
                                    projectName: $projectName,
                                    flowInterestedId: $spFlowInterestedId,
                                    flowNotInterestedId: $spFlowNotInterestedId
                                );

                                $resultItem['template_preview'] = [
                                    'endpoint' => $spBaseUrl . '/whatsapp/contacts/sendTemplateByPhone',
                                    'payload' => sanitizeTemplatePayloadForStorage($templatePayload),
                                ];

                                if (!$liveRequested) {
                                    $resultItem['ledger_preview'] = buildLedgerWritePreview(
                                        currentEntry: $cycleEntry,
                                        cycleId: $cycleId,
                                        opportunityId: $opportunityId,
                                        projectKey: $projectKey,
                                        lastStageChangeAt: $currentLastStageChangeAt,
                                        stepNumber: $stepNumber,
                                        templateName: $templateName,
                                        sendRunId: $sendRunId,
                                        sendItemId: $sendItemId,
                                        finalStepNumber: $finalStepNumber
                                    );
                                    $resultItem['decision'] = [
                                        'status' => 'ready',
                                        'reason' => 'dry_run_ready_to_send_' . $templateName,
                                        'would_send' => true,
                                        'sent' => false,
                                        'step' => $stepNumber,
                                        'template_name' => $templateName,
                                    ];
                                } else {
                                    $ledgerLock = acquireExclusiveLock($ledgerPath . '.lock');
                                    if ($ledgerLock === null) {
                                        throw new RuntimeException('ledger_lock_busy');
                                    }

                                    try {
                                        $ledger = loadReopenLedger($ledgerPath);
                                        $freshCycleEntry = is_array($ledger['cycles'][$cycleId] ?? null)
                                            ? $ledger['cycles'][$cycleId]
                                            : [];
                                        $freshSequence = determineNextReopenStep(
                                            sequence: $sequence,
                                            cycleEntry: $freshCycleEntry,
                                            ageDays: (float) ($ghlEvaluation['age_days'] ?? 0.0),
                                            referenceAt: date(DATE_ATOM)
                                        );
                                        $resultItem['sequence_evaluation']['live_recheck'] = $freshSequence;

                                        if (($freshSequence['status'] ?? '') !== 'ready') {
                                            $resultItem['decision'] = decisionFromGate(
                                                $freshSequence,
                                                'live_ledger_recheck_not_ready'
                                            );
                                        } else {
                                            $freshStep = (int) ($freshSequence['step'] ?? 0);
                                            $freshTemplate = trim((string) ($freshSequence['template'] ?? ''));
                                            if ($freshStep !== $stepNumber || $freshTemplate !== $templateName) {
                                                $resultItem['decision'] = [
                                                    'status' => 'review_required',
                                                    'reason' => 'live_sequence_changed_during_recheck',
                                                    'would_send' => false,
                                                    'sent' => false,
                                                ];
                                            } else {
                                                $reservedAt = date(DATE_ATOM);
                                                $reservation = buildLedgerSendReservationEntry(
                                                    currentEntry: $freshCycleEntry,
                                                    cycleId: $cycleId,
                                                    opportunityId: $opportunityId,
                                                    projectKey: $projectKey,
                                                    lastStageChangeAt: $currentLastStageChangeAt,
                                                    stepNumber: $stepNumber,
                                                    templateName: $templateName,
                                                    sendRunId: $sendRunId,
                                                    sendItemId: $sendItemId,
                                                    reservedAt: $reservedAt
                                                );
                                                persistLedgerCycle($ledgerPath, $ledger, $cycleId, $reservation);
                                                $ledgerWrites++;
                                                $ledgerCyclesModified[$cycleId] = true;
                                                $resultItem['side_effects'] = true;

                                                $postAttempted = true;
                                                $sendResult = sendSendPulseTemplateByPhoneOnce(
                                                    bearerToken: $bearerToken,
                                                    baseUrl: $spBaseUrl,
                                                    payload: $templatePayload,
                                                    httpTimeout: $httpTimeout,
                                                    httpConnectTimeout: $httpConnectTimeout,
                                                    logPath: $paths['log']
                                                );
                                                $spSendCalls++;
                                                $resultItem['sendpulse_send'] = $sendResult;

                                                if (($sendResult['confirmed_sent'] ?? false) === true) {
                                                    $sentAt = date(DATE_ATOM);
                                                    $sentEntry = buildLedgerSentEntry(
                                                        currentEntry: $reservation,
                                                        cycleId: $cycleId,
                                                        opportunityId: $opportunityId,
                                                        projectKey: $projectKey,
                                                        lastStageChangeAt: $currentLastStageChangeAt,
                                                        stepNumber: $stepNumber,
                                                        templateName: $templateName,
                                                        sendRunId: $sendRunId,
                                                        sendItemId: $sendItemId,
                                                        finalStepNumber: $finalStepNumber,
                                                        sentAt: $sentAt
                                                    );
                                                    persistLedgerCycle($ledgerPath, $ledger, $cycleId, $sentEntry);
                                                    $ledgerWrites++;
                                                    $messagesSent++;
                                                    $resultItem['decision'] = [
                                                        'status' => 'sent',
                                                        'reason' => 'live_sent_' . $templateName,
                                                        'would_send' => false,
                                                        'sent' => true,
                                                        'step' => $stepNumber,
                                                        'template_name' => $templateName,
                                                        'terminal_status' => $sentEntry['status'] === 'sequence_complete'
                                                            ? 'sequence_complete'
                                                            : null,
                                                    ];
                                                } else {
                                                    $classification = (string) ($sendResult['classification'] ?? 'ambiguous');
                                                    $attemptStatus = $classification === 'explicit_failure'
                                                        ? 'failed_explicit'
                                                        : 'ambiguous';
                                                    $reviewReason = $classification === 'explicit_failure'
                                                        ? 'sendpulse_explicit_failure_no_auto_retry'
                                                        : 'sendpulse_ambiguous_no_auto_retry';
                                                    $blocked = buildLedgerBlockedAttemptEntry(
                                                        currentEntry: $reservation,
                                                        cycleId: $cycleId,
                                                        opportunityId: $opportunityId,
                                                        projectKey: $projectKey,
                                                        lastStageChangeAt: $currentLastStageChangeAt,
                                                        stepNumber: $stepNumber,
                                                        templateName: $templateName,
                                                        sendRunId: $sendRunId,
                                                        sendItemId: $sendItemId,
                                                        attemptStatus: $attemptStatus,
                                                        reviewReason: $reviewReason,
                                                        attemptedAt: date(DATE_ATOM),
                                                        sendResult: $sendResult
                                                    );
                                                    persistLedgerCycle($ledgerPath, $ledger, $cycleId, $blocked);
                                                    $ledgerWrites++;
                                                    $resultItem['decision'] = [
                                                        'status' => 'review_required',
                                                        'reason' => $reviewReason,
                                                        'would_send' => false,
                                                        'sent' => false,
                                                        'step' => $stepNumber,
                                                        'template_name' => $templateName,
                                                    ];
                                                }
                                            }
                                        }
                                    } finally {
                                        releaseLock($ledgerLock);
                                    }
                                }
                            }
                        }
                    }
                }

                if ($debug && !$liveRequested) {
                    $resultItem['debug'] = [
                        'ghl_raw_response' => $ghlLookup['raw_response'] ?? null,
                        'sendpulse_raw_response' => $spLookup['raw_response'] ?? null,
                    ];
                }

                $results['items'][$sendItemId] = $resultItem;
                $results['updated_at'] = date(DATE_ATOM);
                markStateProcessed($state, $sendItemId);

                logLine(
                    $paths['log'],
                    'ITEM item_id=' . $sendItemId
                    . ' opportunity_id=' . sanitizeLogValue($opportunityId)
                    . ' phone=' . maskPhone($phone)
                    . ' project=' . sanitizeLogValue($projectKey)
                    . ' step=' . sanitizeLogValue((string) ($resultItem['decision']['step'] ?? ''))
                    . ' template=' . sanitizeLogValue((string) ($resultItem['decision']['template_name'] ?? ''))
                    . ' status=' . sanitizeLogValue((string) ($resultItem['decision']['status'] ?? ''))
                    . ' reason=' . sanitizeLogValue((string) ($resultItem['decision']['reason'] ?? ''))
                    . ' sent=' . (($resultItem['decision']['sent'] ?? false) ? '1' : '0')
                );
            } catch (Throwable $itemException) {
                $resultItem['decision'] = [
                    'status' => $postAttempted ? 'review_required' : 'error',
                    'reason' => $postAttempted
                        ? 'exception_after_send_attempt_no_auto_retry'
                        : 'processing_exception',
                    'would_send' => false,
                    'sent' => false,
                    'message' => $itemException->getMessage(),
                ];
                $results['items'][$sendItemId] = $resultItem;
                $results['updated_at'] = date(DATE_ATOM);

                if ($postAttempted) {
                    markStateProcessed($state, $sendItemId);
                } else {
                    markStateError(
                        state: $state,
                        itemId: $sendItemId,
                        error: $itemException->getMessage()
                    );
                }
                logLine(
                    $paths['log'],
                    'ITEM_ERROR item_id=' . $sendItemId
                    . ' opportunity_id=' . sanitizeLogValue($opportunityId)
                    . ' phone=' . maskPhone($phone)
                    . ' post_attempted=' . ($postAttempted ? '1' : '0')
                    . ' message=' . sanitizeLogValue($itemException->getMessage())
                );
            }

            $state['updated_at'] = date(DATE_ATOM);
            writeJsonAtomically($paths['results'], $results);
            writeJsonAtomically($paths['state'], $state);
            $processedThisBatch++;
        }

        $summary = buildSendSummary($manifest, $state, $results);
        $complete = isCompleteState($summary['state']);
        $elapsedMs = elapsedMs($startedAt);

        logLine(
            $paths['log'],
            "BATCH_END send_run_id={$sendRunId} elapsed_ms={$elapsedMs} processed={$processedThisBatch} "
            . 'mode=' . ($liveRequested ? 'LIVE' : 'DRY_RUN')
            . ' pending=' . $summary['state']['pending']
            . ' sent=' . $messagesSent
            . ' errors=' . $summary['state']['error']
        );
        logLine(
            $globalLogPath,
            "BATCH_END send_run_id={$sendRunId} elapsed_ms={$elapsedMs} processed={$processedThisBatch} "
            . 'mode=' . ($liveRequested ? 'LIVE' : 'DRY_RUN')
            . ' sent=' . $messagesSent
        );

        releaseLock($runLock);

        respondAndExit([
            'ok' => true,
            'mode' => 'process_send_batch',
            'dry_run' => $dryRun,
            'live' => $liveRequested,
            'live_capable' => LIVE_CAPABLE,
            'side_effects' => $liveRequested && ($messagesSent > 0 || $ledgerWrites > 0),
            'messages_sent' => $messagesSent,
            'ghl_records_modified' => 0,
            'sendpulse_records_modified' => 0,
            'ledger_records_modified' => count($ledgerCyclesModified),
            'ledger_writes' => $ledgerWrites,
            'send_run_id' => $sendRunId,
            'elapsed_ms' => $elapsedMs,
            'batch_size' => $batchSize,
            'processed_this_batch' => $processedThisBatch,
            'recovered_stale_items' => $recovered,
            'api_calls' => [
                'ghl_get_opportunity' => $ghlCalls,
                'sendpulse_auth' => $sendPulseAuthCalls,
                'sendpulse_contact_lookup' => $spLookupCalls,
                'sendpulse_template_send' => $spSendCalls,
            ],
            'summary' => $summary,
            'complete' => $complete,
            'next_command' => $complete
                ? '?action=status&send_run_id=' . rawurlencode($sendRunId)
                : '?action=process_batch&send_run_id=' . rawurlencode($sendRunId) . '&batch_size=' . $batchSize,
        ], 200);
    } catch (Throwable $exception) {
        releaseLock($runLock);
        logLine(
            $globalLogPath,
            "BATCH_FATAL send_run_id={$sendRunId} message="
            . sanitizeLogValue($exception->getMessage())
        );
        respondAndExit([
            'ok' => false,
            'mode' => 'process_send_batch',
            'dry_run' => !$liveRequested,
            'live' => $liveRequested,
            'send_run_id' => $sendRunId,
            'error' => $exception->getMessage(),
        ], 500);
    }
}

function sendStatusAction(
    string $sendStorageRoot,
    string $sendRunId,
    bool $reopenLiveEnabled
): never {
    validateRunIdOrExit($sendRunId, 'send_run_id');
    $paths = sendRunPaths($sendStorageRoot, $sendRunId);
    assertFilesExist($paths, ['manifest', 'state', 'results']);

    $manifest = loadJsonFile($paths['manifest']);
    $state = loadJsonFile($paths['state']);
    $results = loadJsonFile($paths['results']);
    $summary = buildSendSummary($manifest, $state, $results);
    $complete = isCompleteState($summary['state']);

    respondAndExit([
        'ok' => true,
        'mode' => 'send_status',
        'live_capable' => LIVE_CAPABLE,
        'live_enabled_now' => $reopenLiveEnabled,
        'run_dry_run_only' => ($manifest['dry_run_only'] ?? true) === true,
        'side_effects' => ($summary['messages_sent'] ?? 0) > 0
            || ($summary['ledger_records_modified'] ?? 0) > 0,
        'messages_sent' => $summary['messages_sent'] ?? 0,
        'ledger_records_modified' => $summary['ledger_records_modified'] ?? 0,
        'send_run_id' => $sendRunId,
        'source_run_id' => $manifest['source_run_id'] ?? null,
        'reopen_sequence' => $manifest['reopen_sequence'] ?? [],
        'live_policy' => $manifest['live_policy'] ?? null,
        'summary' => $summary,
        'complete' => $complete,
        'files' => [
            'manifest' => 'manifest.json',
            'state' => 'state.json',
            'results' => 'results.json',
            'log' => 'run.log',
        ],
        'next_command' => $complete
            ? null
            : '?action=process_batch&send_run_id=' . rawurlencode($sendRunId) . '&batch_size=10',
    ], 200);
}

/*
|--------------------------------------------------------------------------
| Elegibilidad / secuencia
|--------------------------------------------------------------------------
*/

function evaluateCurrentGhlEligibility(
    array $lookup,
    string $projectKey,
    array $projectConfig,
    int $minDaysInStage
): array {
    if (($lookup['status'] ?? '') === 'error') {
        return [
            'status' => 'error',
            'reason' => 'ghl_lookup_error',
            'http_status' => $lookup['http_status'] ?? null,
            'error' => $lookup['error'] ?? '',
        ];
    }

    if (($lookup['status'] ?? '') === 'not_found') {
        return [
            'status' => 'terminal',
            'reason' => 'ghl_opportunity_not_found',
            'terminal_status' => 'stage_changed',
            'http_status' => $lookup['http_status'] ?? null,
        ];
    }

    $opportunity = is_array($lookup['opportunity'] ?? null)
        ? $lookup['opportunity']
        : [];

    $expectedPipelineId = trim((string) ($projectConfig['pipeline_id'] ?? ''));
    $expectedStageId = trim((string) (
        $projectConfig['stages']['enfriado']['ghl_stage_id'] ?? ''
    ));
    $actualPipelineId = trim((string) (
        $opportunity['pipelineId'] ?? $opportunity['pipeline_id'] ?? ''
    ));
    $actualStageId = trim((string) (
        $opportunity['pipelineStageId'] ?? $opportunity['pipeline_stage_id'] ?? ''
    ));
    $actualStatus = strtolower(trim((string) ($opportunity['status'] ?? '')));
    $lastStageChangeAt = firstNonEmpty([
        $opportunity['lastStageChangeAt'] ?? null,
        $opportunity['last_stage_change_at'] ?? null,
    ]);

    $base = [
        'project_key' => $projectKey,
        'expected_pipeline_id' => $expectedPipelineId,
        'actual_pipeline_id' => $actualPipelineId,
        'expected_stage_id' => $expectedStageId,
        'actual_stage_id' => $actualStageId,
        'actual_status' => $actualStatus,
        'last_stage_change_at' => $lastStageChangeAt,
        'min_days_in_stage' => $minDaysInStage,
        'http_status' => $lookup['http_status'] ?? null,
    ];

    if ($actualStatus !== 'open') {
        return array_merge($base, [
            'status' => 'terminal',
            'reason' => 'ghl_opportunity_not_open',
            'terminal_status' => 'stage_changed',
        ]);
    }

    if ($actualPipelineId !== $expectedPipelineId) {
        return array_merge($base, [
            'status' => 'terminal',
            'reason' => 'ghl_pipeline_changed',
            'terminal_status' => 'stage_changed',
        ]);
    }

    if ($actualStageId !== $expectedStageId) {
        return array_merge($base, [
            'status' => 'terminal',
            'reason' => 'ghl_stage_changed_from_enfriado',
            'terminal_status' => 'stage_changed',
        ]);
    }

    $age = calculateStageAge($lastStageChangeAt, date(DATE_ATOM));
    if (($age['status'] ?? '') !== 'valid') {
        return array_merge($base, $age, [
            'status' => 'review_required',
            'reason' => 'ghl_last_stage_change_missing_or_invalid',
        ]);
    }

    if (($age['age_seconds'] ?? 0) < ($minDaysInStage * 86400)) {
        return array_merge($base, $age, [
            'status' => 'waiting',
            'reason' => 'ghl_enfriado_wait_not_elapsed_at_send_time',
        ]);
    }

    return array_merge($base, $age, [
        'status' => 'eligible',
        'reason' => 'ghl_open_enfriado_wait_elapsed',
    ]);
}

function evaluateCurrentSendPulseEligibility(
    array $lookup,
    string $decisionVariable
): array {
    $lookupStatus = (string) ($lookup['status'] ?? 'error');

    if ($lookupStatus === 'error') {
        return [
            'status' => 'error',
            'reason' => 'sendpulse_lookup_error',
            'http_status' => $lookup['http_status'] ?? null,
            'error' => $lookup['error'] ?? '',
        ];
    }

    if ($lookupStatus === 'not_found') {
        return [
            'status' => 'not_eligible',
            'reason' => 'sendpulse_contact_not_found_at_send_time',
            'http_status' => $lookup['http_status'] ?? null,
        ];
    }

    $contact = is_array($lookup['contact'] ?? null) ? $lookup['contact'] : [];
    $variables = extractSendPulseVariables($contact);
    $tags = extractSendPulseTags($contact);
    $decisionRaw = scalarToString($variables[$decisionVariable] ?? '');
    $decisionNormalized = normalizeDecision($decisionRaw);
    $contactId = firstNonEmpty([
        $contact['id'] ?? null,
        $contact['_id'] ?? null,
        $contact['contact_id'] ?? null,
    ]);
    $contactName = firstNonEmpty([
        $contact['channel_data']['name'] ?? null,
        $contact['name'] ?? null,
    ]);

    $base = [
        'http_status' => $lookup['http_status'] ?? null,
        'contact_id' => $contactId,
        'contact_name' => $contactName,
        'nl_decision_raw' => $decisionRaw,
        'nl_decision_normalized' => $decisionNormalized,
        'tags' => $tags,
    ];

    if ($decisionNormalized === 'Interesado') {
        return array_merge($base, [
            'status' => 'eligible',
            'reason' => 'sendpulse_contact_interested',
        ]);
    }

    if ($decisionNormalized === 'No interesado') {
        return array_merge($base, [
            'status' => 'terminal',
            'reason' => 'sendpulse_contact_not_interested_at_send_time',
            'terminal_status' => 'not_interested',
        ]);
    }

    if ($decisionRaw === '') {
        return array_merge($base, [
            'status' => 'review_required',
            'reason' => 'sendpulse_nl_decision_empty_at_send_time',
        ]);
    }

    return array_merge($base, [
        'status' => 'review_required',
        'reason' => 'sendpulse_nl_decision_unknown_at_send_time',
    ]);
}

function determineNextReopenStep(
    array $sequence,
    array $cycleEntry,
    float $ageDays,
    string $referenceAt
): array {
    $terminalStatuses = ['stage_changed', 'not_interested', 'sequence_complete'];
    $ledgerStatus = trim((string) ($cycleEntry['status'] ?? 'active'));

    if ($ledgerStatus === 'review_required') {
        return [
            'status' => 'review_required',
            'reason' => (string) ($cycleEntry['review_reason'] ?? 'ledger_cycle_review_required'),
            'age_days' => round($ageDays, 2),
        ];
    }

    if (in_array($ledgerStatus, $terminalStatuses, true)) {
        return [
            'status' => 'terminal',
            'reason' => 'ledger_cycle_' . $ledgerStatus,
            'terminal_status' => $ledgerStatus,
            'age_days' => round($ageDays, 2),
        ];
    }

    $steps = is_array($cycleEntry['steps'] ?? null) ? $cycleEntry['steps'] : [];
    $sentSteps = [];

    foreach ($steps as $stepKey => $stepData) {
        if (!is_array($stepData)) {
            continue;
        }
        if (($stepData['status'] ?? '') !== 'sent') {
            continue;
        }
        $n = (int) $stepKey;
        if ($n > 0) {
            $sentSteps[$n] = true;
        }
    }

    if ($sentSteps !== []) {
        ksort($sentSteps, SORT_NUMERIC);
        $maxSent = max(array_keys($sentSteps));
        for ($i = 1; $i <= $maxSent; $i++) {
            if (!isset($sentSteps[$i])) {
                return [
                    'status' => 'review_required',
                    'reason' => 'ledger_non_contiguous_steps',
                    'sent_steps' => array_keys($sentSteps),
                    'age_days' => round($ageDays, 2),
                ];
            }
        }
    }

    $nextStep = null;
    foreach ($sequence as $stepNumber => $definition) {
        if (!isset($sentSteps[$stepNumber])) {
            $nextStep = $definition;
            break;
        }
    }

    if ($nextStep === null) {
        return [
            'status' => 'terminal',
            'reason' => 'all_reopen_steps_already_sent',
            'terminal_status' => 'sequence_complete',
            'sent_steps' => array_keys($sentSteps),
            'age_days' => round($ageDays, 2),
        ];
    }

    $stepNumber = (int) $nextStep['step'];
    $thresholdDay = (int) $nextStep['day_in_enfriado'];
    $templateName = (string) $nextStep['template'];

    if ($ageDays < $thresholdDay) {
        return [
            'status' => 'waiting',
            'reason' => 'next_reopen_step_day_not_reached',
            'step' => $stepNumber,
            'template' => $templateName,
            'day_in_enfriado' => $thresholdDay,
            'age_days' => round($ageDays, 2),
            'sent_steps' => array_keys($sentSteps),
        ];
    }

    $lastSentAt = trim((string) ($cycleEntry['last_sent_at'] ?? ''));
    if ($lastSentAt !== '' && isSameLocalCalendarDay($lastSentAt, $referenceAt)) {
        return [
            'status' => 'waiting',
            'reason' => 'one_reopen_per_calendar_day',
            'step' => $stepNumber,
            'template' => $templateName,
            'day_in_enfriado' => $thresholdDay,
            'age_days' => round($ageDays, 2),
            'last_sent_at' => $lastSentAt,
            'sent_steps' => array_keys($sentSteps),
        ];
    }

    return [
        'status' => 'ready',
        'reason' => 'next_reopen_step_ready',
        'step' => $stepNumber,
        'template' => $templateName,
        'day_in_enfriado' => $thresholdDay,
        'age_days' => round($ageDays, 2),
        'sent_steps' => array_keys($sentSteps),
    ];
}

function calculateStageAge(string $lastStageChangeAt, string $referenceAt): array
{
    $lastTimestamp = strtotime($lastStageChangeAt);
    $referenceTimestamp = strtotime($referenceAt);

    if (
        $lastStageChangeAt === ''
        || $lastTimestamp === false
        || $referenceTimestamp === false
        || $lastTimestamp > $referenceTimestamp
    ) {
        return [
            'status' => 'invalid',
            'age_seconds' => null,
            'age_days' => null,
            'reference_at' => $referenceAt,
        ];
    }

    $ageSeconds = $referenceTimestamp - $lastTimestamp;
    return [
        'status' => 'valid',
        'age_seconds' => $ageSeconds,
        'age_days' => round($ageSeconds / 86400, 2),
        'reference_at' => $referenceAt,
    ];
}

function buildReopenTemplatePayload(
    string $templateName,
    string $botId,
    string $phone,
    string $projectName,
    string $flowInterestedId,
    string $flowNotInterestedId
): array {
    if (!preg_match('/^reopen_[1-9][0-9]*$/', $templateName)) {
        throw new InvalidArgumentException(
            "Plantilla Reopen inválida: {$templateName}."
        );
    }

    return [
        'bot_id' => $botId,
        'phone' => $phone,
        'template' => [
            'name' => $templateName,
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        [
                            'type' => 'text',
                            'text' => $projectName,
                            'parameter_name' => TEMPLATE_PARAMETER_NAME,
                        ],
                    ],
                ],
                [
                    'type' => 'button',
                    'sub_type' => 'quick_reply',
                    'index' => 0,
                    'parameters' => [[
                        'type' => 'payload',
                        'payload' => ['to_chain_id' => $flowInterestedId],
                    ]],
                ],
                [
                    'type' => 'button',
                    'sub_type' => 'quick_reply',
                    'index' => 1,
                    'parameters' => [[
                        'type' => 'payload',
                        'payload' => ['to_chain_id' => $flowNotInterestedId],
                    ]],
                ],
            ],
            'language' => [
                'policy' => 'deterministic',
                'code' => TEMPLATE_LANGUAGE,
            ],
        ],
    ];
}

function resolveProjectTemplateName(string $projectKey, array $projectConfig): string
{
    $projectName = trim((string) ($projectConfig['template_project_name'] ?? ''));
    if ($projectName !== '') {
        return $projectName;
    }
    $fallback = trim((string) ($projectConfig['label'] ?? ''));
    if ($fallback === '') {
        throw new RuntimeException(
            "Falta template_project_name para el proyecto {$projectKey}."
        );
    }
    return $fallback;
}

function getGhlOpportunityById(
    string $token,
    string $baseUrl,
    string $apiVersion,
    string $opportunityId,
    int $httpTimeout,
    int $httpConnectTimeout,
    int $httpMaxAttempts,
    string $logPath
): array {
    $url = rtrim($baseUrl, '/')
        . '/opportunities/'
        . rawurlencode($opportunityId);

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
        logLabel: 'GHL_GET_OPPORTUNITY'
    );

    $decoded = is_array($response['decoded'])
        ? $response['decoded']
        : [];

    if ($response['ok']) {
        $opportunity = null;

        foreach ([
            $decoded['opportunity'] ?? null,
            $decoded['data']['opportunity'] ?? null,
            $decoded['data'] ?? null,
            $decoded,
        ] as $candidate) {
            if (
                is_array($candidate)
                && trim((string) ($candidate['id'] ?? '')) !== ''
            ) {
                $opportunity = $candidate;
                break;
            }
        }

        if (is_array($opportunity)) {
            return [
                'status' => 'found',
                'http_status' => $response['status'],
                'opportunity' => $opportunity,
                'error' => '',
                'raw_response' => $decoded,
            ];
        }
    }

    if ($response['status'] === 404) {
        return [
            'status' => 'not_found',
            'http_status' => 404,
            'opportunity' => null,
            'error' => '',
            'raw_response' => $decoded,
        ];
    }

    return [
        'status' => 'error',
        'http_status' => $response['status'],
        'opportunity' => null,
        'error' => firstNonEmpty([
            $response['error'] ?? null,
            $decoded['message'] ?? null,
            safeSubstr((string) ($response['body'] ?? ''), 0, 500),
        ]),
        'raw_response' => $decoded,
    ];
}

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
            'No fue posible autenticar con SendPulse. HTTP '
            . $response['status']
        );
    }

    $token = trim((string) (
        $response['decoded']['access_token'] ?? ''
    ));

    if ($token === '') {
        throw new RuntimeException('SendPulse no devolvió access_token.');
    }

    return $token;
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

    if ($response['ok'] && is_array($data) && $data !== []) {
        return [
            'status' => 'found',
            'http_status' => $response['status'],
            'contact' => $data,
            'error' => '',
            'raw_response' => $decoded,
        ];
    }

    $bodyLower = safeLower((string) $response['body']);
    $looksNotFound = (
        $response['status'] === 404
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
            $response['error'] ?? null,
            $decoded['message'] ?? null,
            safeSubstr((string) $response['body'], 0, 500),
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
            $name = trim((string) $name);

            if ($name !== '') {
                $map[$name] = $value;
            }
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

    $unique = [];

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
            $unique[$name] = true;
        }
    }

    $names = array_keys($unique);
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

/*
|--------------------------------------------------------------------------
| Resúmenes y estado
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| Ledger / resumen
|--------------------------------------------------------------------------
*/

function loadReopenLedger(string $path): array
{
    if (!is_file($path)) {
        return [
            'schema_version' => 1,
            'updated_at' => null,
            'cycles' => [],
        ];
    }

    $ledger = loadJsonFile($path);
    if (!is_array($ledger['cycles'] ?? null)) {
        $ledger['cycles'] = [];
    }
    return $ledger;
}

function buildReopenCycleId(string $opportunityId, string $lastStageChangeAt): string
{
    if ($opportunityId === '' || $lastStageChangeAt === '') {
        throw new InvalidArgumentException('No se puede construir cycle_id sin opportunity_id y last_stage_change_at.');
    }
    return 'cycle_' . hash('sha256', $opportunityId . '|' . $lastStageChangeAt);
}

function buildLedgerWritePreview(
    array $currentEntry,
    string $cycleId,
    string $opportunityId,
    string $projectKey,
    string $lastStageChangeAt,
    int $stepNumber,
    string $templateName,
    string $sendRunId,
    string $sendItemId,
    int $finalStepNumber
): array {
    $sentAt = date(DATE_ATOM);
    return [
        'mode' => 'dry_run_preview_only',
        'would_write' => buildLedgerSentEntry(
            currentEntry: $currentEntry,
            cycleId: $cycleId,
            opportunityId: $opportunityId,
            projectKey: $projectKey,
            lastStageChangeAt: $lastStageChangeAt,
            stepNumber: $stepNumber,
            templateName: $templateName,
            sendRunId: $sendRunId,
            sendItemId: $sendItemId,
            finalStepNumber: $finalStepNumber,
            sentAt: $sentAt
        ),
    ];
}

function buildLedgerSendReservationEntry(
    array $currentEntry,
    string $cycleId,
    string $opportunityId,
    string $projectKey,
    string $lastStageChangeAt,
    int $stepNumber,
    string $templateName,
    string $sendRunId,
    string $sendItemId,
    string $reservedAt
): array {
    $entry = normalizeLedgerCycleBase(
        currentEntry: $currentEntry,
        cycleId: $cycleId,
        opportunityId: $opportunityId,
        projectKey: $projectKey,
        lastStageChangeAt: $lastStageChangeAt
    );
    $entry['status'] = 'review_required';
    $entry['review_reason'] = 'send_attempt_in_progress';
    $entry['steps'][(string) $stepNumber] = [
        'status' => 'sending',
        'template' => $templateName,
        'reserved_at' => $reservedAt,
        'send_run_id' => $sendRunId,
        'send_item_id' => $sendItemId,
    ];
    $entry['last_attempt_at'] = $reservedAt;
    return $entry;
}

function buildLedgerSentEntry(
    array $currentEntry,
    string $cycleId,
    string $opportunityId,
    string $projectKey,
    string $lastStageChangeAt,
    int $stepNumber,
    string $templateName,
    string $sendRunId,
    string $sendItemId,
    int $finalStepNumber,
    string $sentAt
): array {
    $entry = normalizeLedgerCycleBase(
        currentEntry: $currentEntry,
        cycleId: $cycleId,
        opportunityId: $opportunityId,
        projectKey: $projectKey,
        lastStageChangeAt: $lastStageChangeAt
    );
    $entry['steps'][(string) $stepNumber] = [
        'status' => 'sent',
        'template' => $templateName,
        'sent_at' => $sentAt,
        'send_run_id' => $sendRunId,
        'send_item_id' => $sendItemId,
    ];
    $entry['last_sent_at'] = $sentAt;
    $entry['last_step_sent'] = $stepNumber;
    unset($entry['review_reason']);
    if ($stepNumber >= $finalStepNumber) {
        $entry['status'] = 'sequence_complete';
        $entry['completed_at'] = $sentAt;
    } else {
        $entry['status'] = 'active';
        unset($entry['completed_at']);
    }
    return $entry;
}

function buildLedgerBlockedAttemptEntry(
    array $currentEntry,
    string $cycleId,
    string $opportunityId,
    string $projectKey,
    string $lastStageChangeAt,
    int $stepNumber,
    string $templateName,
    string $sendRunId,
    string $sendItemId,
    string $attemptStatus,
    string $reviewReason,
    string $attemptedAt,
    array $sendResult
): array {
    $entry = normalizeLedgerCycleBase(
        currentEntry: $currentEntry,
        cycleId: $cycleId,
        opportunityId: $opportunityId,
        projectKey: $projectKey,
        lastStageChangeAt: $lastStageChangeAt
    );
    $entry['status'] = 'review_required';
    $entry['review_reason'] = $reviewReason;
    $entry['steps'][(string) $stepNumber] = [
        'status' => $attemptStatus,
        'template' => $templateName,
        'attempted_at' => $attemptedAt,
        'send_run_id' => $sendRunId,
        'send_item_id' => $sendItemId,
        'http_status' => (int) ($sendResult['http_status'] ?? 0),
        'api_success' => $sendResult['api_success'] ?? null,
        'message' => safeSubstr((string) ($sendResult['message'] ?? ''), 0, 300),
    ];
    $entry['last_attempt_at'] = $attemptedAt;
    return $entry;
}

function buildLedgerTerminalEntry(
    array $currentEntry,
    string $cycleId,
    string $opportunityId,
    string $projectKey,
    string $lastStageChangeAt,
    string $terminalStatus,
    string $reason,
    string $detectedAt
): array {
    if (!in_array($terminalStatus, ['stage_changed', 'not_interested', 'sequence_complete'], true)) {
        throw new InvalidArgumentException('Estado terminal Reopen inválido.');
    }
    $entry = normalizeLedgerCycleBase(
        currentEntry: $currentEntry,
        cycleId: $cycleId,
        opportunityId: $opportunityId,
        projectKey: $projectKey,
        lastStageChangeAt: $lastStageChangeAt
    );
    $entry['status'] = $terminalStatus;
    $entry['terminal_reason'] = $reason;
    $entry['terminal_at'] = $detectedAt;
    return $entry;
}

function normalizeLedgerCycleBase(
    array $currentEntry,
    string $cycleId,
    string $opportunityId,
    string $projectKey,
    string $lastStageChangeAt
): array {
    $entry = $currentEntry;
    $entry['cycle_id'] = $cycleId;
    $entry['opportunity_id'] = $opportunityId;
    $entry['project_key'] = $projectKey;
    $entry['enfriado_entered_at'] = $lastStageChangeAt;
    $entry['steps'] = is_array($entry['steps'] ?? null) ? $entry['steps'] : [];
    $entry['status'] = trim((string) ($entry['status'] ?? 'active')) ?: 'active';
    return $entry;
}

function persistLedgerCycle(string $ledgerPath, array &$ledger, string $cycleId, array $entry): void
{
    $ledger['schema_version'] = 2;
    $ledger['updated_at'] = date(DATE_ATOM);
    $ledger['cycles'] = is_array($ledger['cycles'] ?? null) ? $ledger['cycles'] : [];
    $ledger['cycles'][$cycleId] = $entry;
    writeJsonAtomically($ledgerPath, $ledger);
}

function sanitizeTemplatePayloadForStorage(array $payload): array
{
    $copy = $payload;
    $phone = normalizePhone((string) ($copy['phone'] ?? ''));
    $copy['phone'] = maskPhone($phone);
    return $copy;
}

function classifySendPulseTemplateResponse(array $response): array
{
    $status = (int) ($response['status'] ?? 0);
    $decoded = is_array($response['decoded'] ?? null) ? $response['decoded'] : [];
    $apiSuccess = array_key_exists('success', $decoded) ? $decoded['success'] : null;
    $message = firstNonEmpty([
        $decoded['message'] ?? null,
        $decoded['error']['message'] ?? null,
        $decoded['error'] ?? null,
        $response['error'] ?? null,
    ]);

    if ($status >= 200 && $status < 300 && $apiSuccess === true) {
        return [
            'classification' => 'confirmed_success',
            'confirmed_sent' => true,
            'review_required' => false,
            'http_status' => $status,
            'api_success' => true,
            'message' => $message,
            'duration_ms' => (int) ($response['duration_ms'] ?? 0),
        ];
    }

    if ($apiSuccess === false) {
        return [
            'classification' => 'explicit_failure',
            'confirmed_sent' => false,
            'review_required' => true,
            'http_status' => $status,
            'api_success' => false,
            'message' => $message,
            'duration_ms' => (int) ($response['duration_ms'] ?? 0),
        ];
    }

    return [
        'classification' => 'ambiguous',
        'confirmed_sent' => false,
        'review_required' => true,
        'http_status' => $status,
        'api_success' => $apiSuccess,
        'message' => $message,
        'duration_ms' => (int) ($response['duration_ms'] ?? 0),
    ];
}

function sendSendPulseTemplateByPhoneOnce(
    string $bearerToken,
    string $baseUrl,
    array $payload,
    int $httpTimeout,
    int $httpConnectTimeout,
    string $logPath
): array {
    $response = httpJsonRequest(
        method: 'POST',
        url: rtrim($baseUrl, '/') . '/whatsapp/contacts/sendTemplateByPhone',
        headers: [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $bearerToken,
        ],
        body: $payload,
        httpTimeout: $httpTimeout,
        httpConnectTimeout: $httpConnectTimeout,
        httpMaxAttempts: 1,
        logPath: $logPath,
        logLabel: 'SENDPULSE_TEMPLATE_SEND_ONCE'
    );
    return classifySendPulseTemplateResponse($response);
}

function normalizeReopenSequenceConfig(array $rawSequence): array
{
    if ($rawSequence === []) {
        throw new RuntimeException('Falta runtime.reopen_sequence.');
    }

    $normalized = [];
    foreach ($rawSequence as $key => $definition) {
        if (!is_array($definition)) {
            throw new RuntimeException('Cada step de reopen_sequence debe ser un arreglo.');
        }

        $step = isset($definition['step'])
            ? (int) $definition['step']
            : (int) $key;
        $template = trim((string) ($definition['template'] ?? ''));
        $day = (int) ($definition['day_in_enfriado'] ?? -1);

        if ($step < 1 || $template === '' || $day < 0) {
            throw new RuntimeException('Step inválido en runtime.reopen_sequence.');
        }
        if (!preg_match('/^reopen_[1-9][0-9]*$/', $template)) {
            throw new RuntimeException("Template inválido en reopen_sequence: {$template}.");
        }
        if (isset($normalized[$step])) {
            throw new RuntimeException("Step duplicado en reopen_sequence: {$step}.");
        }

        $normalized[$step] = [
            'step' => $step,
            'template' => $template,
            'day_in_enfriado' => $day,
        ];
    }

    ksort($normalized, SORT_NUMERIC);
    $expectedStep = 1;
    $previousDay = -1;
    foreach ($normalized as $step => $definition) {
        if ($step !== $expectedStep) {
            throw new RuntimeException('reopen_sequence debe usar steps consecutivos desde 1.');
        }
        if ($definition['day_in_enfriado'] <= $previousDay) {
            throw new RuntimeException('Los day_in_enfriado deben ser estrictamente crecientes.');
        }
        $previousDay = $definition['day_in_enfriado'];
        $expectedStep++;
    }

    return $normalized;
}

function compactSequenceForLog(array $sequence): string
{
    $parts = [];
    foreach ($sequence as $definition) {
        $parts[] = (string) $definition['step'] . ':'
            . $definition['template'] . '@d' . $definition['day_in_enfriado'];
    }
    return implode(',', $parts);
}

function isSameLocalCalendarDay(string $left, string $right): bool
{
    $leftTs = strtotime($left);
    $rightTs = strtotime($right);
    if ($leftTs === false || $rightTs === false) {
        return false;
    }
    return date('Y-m-d', $leftTs) === date('Y-m-d', $rightTs);
}

function decisionFromGate(array $gate, string $fallbackReason): array
{
    $status = (string) ($gate['status'] ?? 'not_eligible');
    return [
        'status' => $status,
        'reason' => (string) ($gate['reason'] ?? $fallbackReason),
        'terminal_status' => $gate['terminal_status'] ?? null,
        'would_send' => false,
        'step' => $gate['step'] ?? null,
        'template_name' => $gate['template'] ?? null,
    ];
}

function buildSendSummary(array $manifest, array $state, array $results): array
{
    $stateCounts = summarizeState($state);
    $evaluation = [
        'evaluated' => 0,
        'ready_to_send' => 0,
        'sent' => 0,
        'waiting' => 0,
        'terminal' => 0,
        'not_eligible' => 0,
        'review_required' => 0,
        'error' => 0,
        'would_send_by_project' => [],
        'would_send_by_step' => [],
        'sent_by_project' => [],
        'sent_by_step' => [],
        'terminal_by_status' => [],
        'reason_counts' => [],
    ];
    $ledgerTouched = [];

    foreach (($results['items'] ?? []) as $resultItem) {
        if (!is_array($resultItem)) {
            continue;
        }
        $decision = is_array($resultItem['decision'] ?? null)
            ? $resultItem['decision']
            : [];
        $status = (string) ($decision['status'] ?? '');
        $reason = (string) ($decision['reason'] ?? '');
        $wouldSend = ($decision['would_send'] ?? false) === true;
        $sent = ($decision['sent'] ?? false) === true || $status === 'sent';
        $projectKey = (string) ($resultItem['candidate']['project_key'] ?? '');
        $step = (int) ($decision['step'] ?? 0);
        $terminalStatus = (string) ($decision['terminal_status'] ?? '');

        $evaluation['evaluated']++;
        if ($sent) {
            $evaluation['sent']++;
            if ($projectKey !== '') {
                incrementCounter($evaluation['sent_by_project'], $projectKey);
            }
            if ($step > 0) {
                incrementCounter($evaluation['sent_by_step'], (string) $step);
            }
        } elseif ($wouldSend) {
            $evaluation['ready_to_send']++;
            if ($projectKey !== '') {
                incrementCounter($evaluation['would_send_by_project'], $projectKey);
            }
            if ($step > 0) {
                incrementCounter($evaluation['would_send_by_step'], (string) $step);
            }
        } elseif ($status === 'waiting') {
            $evaluation['waiting']++;
        } elseif ($status === 'terminal') {
            $evaluation['terminal']++;
            if ($terminalStatus !== '') {
                incrementCounter($evaluation['terminal_by_status'], $terminalStatus);
            }
        } elseif ($status === 'review_required') {
            $evaluation['review_required']++;
        } elseif ($status === 'error') {
            $evaluation['error']++;
        } else {
            $evaluation['not_eligible']++;
        }

        if (($resultItem['side_effects'] ?? false) === true) {
            $cycleId = (string) (
                $resultItem['sequence_evaluation']['cycle_id']
                ?? $resultItem['sequence_evaluation']['cycle_id_snapshot']
                ?? $resultItem['send_item_id']
                ?? ''
            );
            if ($cycleId !== '') {
                $ledgerTouched[$cycleId] = true;
            }
        }

        if ($reason !== '') {
            incrementCounter($evaluation['reason_counts'], $reason);
        }
    }

    foreach ([
        'would_send_by_project', 'would_send_by_step',
        'sent_by_project', 'sent_by_step',
        'terminal_by_status', 'reason_counts'
    ] as $key) {
        ksort($evaluation[$key], SORT_NATURAL);
    }

    return [
        'cohort' => [
            'source_run_id' => $manifest['source_run_id'] ?? null,
            'segment' => $manifest['cohort']['segment'] ?? null,
            'min_days_in_stage' => $manifest['source_reopen_min_days_in_stage'] ?? null,
            'reopen_sequence' => $manifest['reopen_sequence'] ?? [],
            'total' => $manifest['cohort']['total'] ?? null,
            'by_project' => $manifest['cohort']['by_project'] ?? [],
        ],
        'state' => $stateCounts,
        'evaluation' => $evaluation,
        'messages_sent' => $evaluation['sent'],
        'ledger_records_modified' => count($ledgerTouched),
    ];
}

function summarizeState(array $state): array
{
    $counts = [
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
        $counts['total']++;
        $status = (string) ($itemState['status'] ?? 'unknown');
        if (!array_key_exists($status, $counts)) {
            $status = 'unknown';
        }
        $counts[$status]++;
    }
    return $counts;
}

function selectBatchItemIds(
    array $state,
    int $batchSize,
    bool $retryErrors,
    string $selectedItemId = ''
): array {
    if ($selectedItemId !== '') {
        $itemState = $state['items'][$selectedItemId] ?? null;
        if (!is_array($itemState)) {
            return [];
        }
        $status = (string) ($itemState['status'] ?? '');
        $processable = $status === 'pending'
            || ($retryErrors && $status === 'error');
        return $processable ? [$selectedItemId] : [];
    }

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

function markStateProcessing(array &$state, string $itemId): void
{
    $state['items'][$itemId]['status'] = 'processing';
    $state['items'][$itemId]['attempts'] = (
        (int) ($state['items'][$itemId]['attempts'] ?? 0)
    ) + 1;
    $state['items'][$itemId]['started_at'] = date(DATE_ATOM);
    $state['items'][$itemId]['finished_at'] = null;
    $state['items'][$itemId]['last_error'] = null;
    $state['updated_at'] = date(DATE_ATOM);
}

function markStateProcessed(array &$state, string $itemId): void
{
    $state['items'][$itemId]['status'] = 'processed';
    $state['items'][$itemId]['finished_at'] = date(DATE_ATOM);
    $state['items'][$itemId]['last_error'] = null;
    $state['updated_at'] = date(DATE_ATOM);
}

function markStateError(
    array &$state,
    string $itemId,
    string $error
): void {
    $state['items'][$itemId]['status'] = 'error';
    $state['items'][$itemId]['finished_at'] = date(DATE_ATOM);
    $state['items'][$itemId]['last_error'] = $error;
    $state['updated_at'] = date(DATE_ATOM);
}

function isCompleteState(array $stateSummary): bool
{
    return (
        (int) ($stateSummary['pending'] ?? 0) === 0
        && (int) ($stateSummary['processing'] ?? 0) === 0
    );
}

/*
|--------------------------------------------------------------------------
| Archivos, HTTP y utilidades
|--------------------------------------------------------------------------
*/

function sourceRunPaths(string $storageRoot, string $runId): array
{
    $dir = $storageRoot . '/' . $runId;

    return [
        'dir' => $dir,
        'snapshot' => $dir . '/snapshot.json',
        'state' => $dir . '/state.json',
        'results' => $dir . '/results.json',
    ];
}

function sendRunPaths(string $storageRoot, string $runId): array
{
    $dir = $storageRoot . '/' . $runId;

    return [
        'dir' => $dir,
        'manifest' => $dir . '/manifest.json',
        'state' => $dir . '/state.json',
        'results' => $dir . '/results.json',
        'log' => $dir . '/run.log',
        'lock' => $dir . '/process.lock',
    ];
}

function validateRunIdOrExit(string $runId, string $field): void
{
    if (
        $runId === ''
        || !preg_match('/^[0-9]{8}_[0-9]{6}_[a-f0-9]{4}$/', $runId)
    ) {
        respondAndExit([
            'ok' => false,
            'reason' => 'invalid_run_id',
            'field' => $field,
            'run_id' => $runId,
        ], 400);
    }
}

function assertFilesExist(array $paths, array $keys): void
{
    foreach ($keys as $key) {
        $path = (string) ($paths[$key] ?? '');

        if ($path === '' || !is_file($path)) {
            respondAndExit([
                'ok' => false,
                'reason' => 'run_file_not_found',
                'file' => basename($path),
            ], 404);
        }
    }
}

function loadJsonFile(string $path): array
{
    $content = file_get_contents($path);

    if ($content === false) {
        throw new RuntimeException("No fue posible leer {$path}.");
    }

    $decoded = json_decode($content, true);

    if (!is_array($decoded)) {
        throw new RuntimeException("El JSON {$path} es inválido.");
    }

    return $decoded;
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
        throw new RuntimeException('No fue posible codificar JSON.');
    }

    if (file_put_contents($tempPath, $encoded) === false) {
        throw new RuntimeException("No fue posible escribir {$tempPath}.");
    }

    if (!rename($tempPath, $path)) {
        @unlink($tempPath);
        throw new RuntimeException("No fue posible mover {$tempPath}.");
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

function releaseLock($lockHandle): void
{
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
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
                throw new RuntimeException('No fue posible codificar body HTTP.');
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
            . 'url=' . sanitizeLoggedUrl($url)
        );

        if ($lastResult['ok']) {
            return $lastResult;
        }

        $temporary = (
            $curlError !== ''
            || $status === 408
            || $status === 429
            || $status >= 500
        );

        if (!$temporary || $attempt === $httpMaxAttempts) {
            return $lastResult;
        }

        usleep(500000 * $attempt);
    }

    return $lastResult;
}


function validateConfig(
    string $ghlToken,
    string $ghlBaseUrl,
    array $projects,
    string $spBaseUrl,
    string $spAuthMode,
    string $spClientId,
    string $spClientSecret,
    string $spApiKey,
    string $spBotId,
    string $spDecisionVariable,
    string $spFlowInterestedId,
    string $spFlowNotInterestedId,
    int $reopenMinDaysInStage,
    array $reopenSequence
): void {
    if ($ghlToken === '' || !str_starts_with($ghlToken, 'pit-')) {
        throw new RuntimeException('Configura un PIT válido en ghl.token.');
    }
    if ($ghlBaseUrl === '') {
        throw new RuntimeException('Falta ghl.base_url.');
    }
    if ($projects === []) {
        throw new RuntimeException('No hay proyectos configurados.');
    }

    foreach ($projects as $projectKey => $project) {
        if (!is_array($project)) {
            throw new RuntimeException("Proyecto inválido: {$projectKey}.");
        }
        if (trim((string) ($project['pipeline_id'] ?? '')) === '') {
            throw new RuntimeException("Falta pipeline_id para {$projectKey}.");
        }
        if (trim((string) ($project['stages']['enfriado']['ghl_stage_id'] ?? '')) === '') {
            throw new RuntimeException("Falta enfriado.ghl_stage_id para {$projectKey}.");
        }
        if (trim((string) ($project['template_project_name'] ?? '')) === '') {
            throw new RuntimeException("Falta template_project_name para {$projectKey}.");
        }
    }

    if ($spBaseUrl === '') {
        throw new RuntimeException('Falta sendpulse.base_url.');
    }
    if (!in_array($spAuthMode, ['oauth', 'api_key'], true)) {
        throw new RuntimeException('sendpulse.auth_mode debe ser oauth o api_key.');
    }
    if ($spAuthMode === 'oauth' && ($spClientId === '' || $spClientSecret === '')) {
        throw new RuntimeException('Configura sendpulse.client_id y sendpulse.client_secret.');
    }
    if ($spAuthMode === 'api_key' && $spApiKey === '') {
        throw new RuntimeException('Configura sendpulse.api_key.');
    }
    if ($spBotId === '') {
        throw new RuntimeException('Falta sendpulse.bot_id.');
    }
    if ($spDecisionVariable === '') {
        throw new RuntimeException('Falta sendpulse.decision_variable.');
    }
    if ($spFlowInterestedId === '' || $spFlowNotInterestedId === '') {
        throw new RuntimeException('Faltan los flow IDs de quick replies en SendPulse.');
    }

    if ($reopenSequence === []) {
        throw new RuntimeException('Falta runtime.reopen_sequence.');
    }
    $firstStep = reset($reopenSequence);
    $firstDay = (int) ($firstStep['day_in_enfriado'] ?? -1);
    if ($reopenMinDaysInStage !== $firstDay) {
        throw new RuntimeException(
            'runtime.reopen_min_days_in_stage debe coincidir con el día del primer step Reopen.'
        );
    }
}

function authorizeHttpExecution(string $configuredKey): void
{
    if ($configuredKey === '') {
        respondAndExit([
            'ok' => false,
            'reason' => 'missing_execution_key',
        ], 500);
    }

    // Opción 1: header propio histórico.
    $providedKey = getRequestHeader('X-Reopen-Key');

    // Opción 2: estándar Authorization: Bearer <key>.
    // Es útil en hosts/proxies que no reenvían headers X-* personalizados.
    if ($providedKey === '') {
        $providedKey = getBearerToken();
    }

    if ($providedKey === '' || !hash_equals($configuredKey, $providedKey)) {
        respondAndExit([
            'ok' => false,
            'reason' => 'unauthorized',
            'auth_transport_detected' => detectAuthTransport(),
        ], 401);
    }
}

function getRequestHeader(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    $envValue = getenv($serverKey);
    if ($envValue !== false && trim((string) $envValue) !== '') {
        return trim((string) $envValue);
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

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();

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

function getBearerToken(): string
{
    $authorization = getRequestHeader('Authorization');

    // Algunos Apache/FastCGI lo exponen con estas claves en vez de HTTP_AUTHORIZATION.
    if ($authorization === '') {
        foreach (['REDIRECT_HTTP_AUTHORIZATION', 'AUTHORIZATION'] as $serverKey) {
            $value = $_SERVER[$serverKey] ?? getenv($serverKey);
            if ($value !== false && trim((string) $value) !== '') {
                $authorization = trim((string) $value);
                break;
            }
        }
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
        return '';
    }

    return trim((string) $matches[1]);
}

function detectAuthTransport(): array
{
    $xReopenKeyPresent = getRequestHeader('X-Reopen-Key') !== '';
    $authorizationPresent = getRequestHeader('Authorization') !== ''
        || trim((string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')) !== ''
        || trim((string) ($_SERVER['AUTHORIZATION'] ?? '')) !== '';

    return [
        'x_reopen_key_present' => $xReopenKeyPresent,
        'authorization_present' => $authorizationPresent,
    ];
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

function buildUrl(string $baseUrl, string $path, array $query): string
{
    return rtrim($baseUrl, '/')
        . '/'
        . ltrim($path, '/')
        . '?'
        . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
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

function normalizePhone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function maskPhone(string $phone): string
{
    $phone = normalizePhone($phone);

    if (strlen($phone) <= 4) {
        return str_repeat('*', strlen($phone));
    }

    return str_repeat('*', strlen($phone) - 4) . substr($phone, -4);
}


function safeLower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

function safeSubstr(string $value, int $start, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, $start, $length, 'UTF-8');
    }

    return substr($value, $start, $length);
}

function normalizeComparableText(string $value): string
{
    $value = trim(safeLower($value));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

    if ($ascii !== false) {
        $value = strtolower($ascii);
    }

    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
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

function logLine(string $path, string $message): void
{
    file_put_contents(
        $path,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

function sanitizeLogValue(string $value): string
{
    $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;
    return safeSubstr(trim($value), 0, 500);
}

function elapsedMs(float $startedAt): int
{
    return (int) round((microtime(true) - $startedAt) * 1000);
}