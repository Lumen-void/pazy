#!/usr/bin/env php
<?php

declare(strict_types=1);

use Pazy\Integrations\Support\HttpClient;
use Pazy\Modules\Approvals\ApprovalEngine;
use Pazy\Modules\Expenses\ExpensePolicyEngine;
use Pazy\Modules\Integrations\IntegrationJobWorker;
use Pazy\Modules\Integrations\IntegrationRuntimeConfig;
use Pazy\Modules\Payments\PaymentEngine;
use Pazy\Modules\Procurement\MatchingEngine;
use Pazy\Modules\Tax\TaxReconciliationEngine;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run in CLI mode.\n");
    exit(1);
}

require_once __DIR__.'/../src/bootstrap.php';

$argv = $_SERVER['argv'] ?? [];
$command = $argv[1] ?? 'help';
$options = cli_parse_options(array_slice($argv, 2));

try {
    switch ($command) {
        case 'help':
        case '--help':
        case '-h':
            cli_help();
            exit(0);

        case 'infra:doctor':
            exit(cli_infra_doctor($config));

        case 'db:init':
            exit(cli_db_init($config, $options));

        case 'db:seed':
            exit(cli_db_seed($config));

        case 'worker:run':
            exit(cli_worker_run($config, $options));

        case 'worker:loop':
            exit(cli_worker_loop($config, $options));

        case 'integrations:preflight':
            exit(cli_integrations_preflight($config, $options));

        case 'backup:create':
            exit(cli_backup_create($config, $options));

        case 'backup:restore':
            exit(cli_backup_restore($config, $options));

        case 'qa:smoke':
            exit(cli_qa_smoke($config, $options));

        default:
            cli_out('Unknown command: '.$command);
            cli_help();
            exit(1);
    }
} catch (Throwable $e) {
    cli_err('Fatal: '.$e->getMessage());
    exit(1);
}

function cli_help(): void
{
    cli_out('Pazy PurePHP Console');
    cli_out('');
    cli_out('Usage: php bin/console.php <command> [--key=value]');
    cli_out('');
    cli_out('Commands:');
    cli_out('  infra:doctor                  Validate PHP, env, writable dirs, DB, and schema health');
    cli_out('  db:init [--seed=1]            Create database schema from database/schema.sql (and seed by default)');
    cli_out('  db:seed                       Apply database/seed.sql only');
    cli_out('  worker:run [--limit=20] [--company=all] [--actor=1]');
    cli_out('                                Run due integration jobs once');
    cli_out('  worker:loop [--interval=15] [--limit=50] [--company=all] [--actor=1] [--iterations=0]');
    cli_out('                                Continuous worker loop (iterations=0 means forever)');
    cli_out('  integrations:preflight [--probe=0] [--company=1]');
    cli_out('                                Show connector readiness and optional endpoint probes');
    cli_out('  backup:create [--out=/abs/path.sql]');
    cli_out('                                Create full SQL backup using mysqldump');
    cli_out('  backup:restore --file=/abs/path.sql');
    cli_out('                                Restore SQL backup using mysql client');
    cli_out('  qa:smoke [--company=1]        Run invoice/reimbursement/tax end-to-end smoke validations');
}

function cli_parse_options(array $args): array
{
    $options = [];
    foreach ($args as $arg) {
        $value = (string) $arg;
        if (! str_starts_with($value, '--')) {
            continue;
        }

        $pair = substr($value, 2);
        if ($pair === '') {
            continue;
        }

        if (str_contains($pair, '=')) {
            [$key, $raw] = explode('=', $pair, 2);
            $options[$key] = $raw;
        } else {
            $options[$pair] = '1';
        }
    }

    return $options;
}

function cli_opt_int(array $options, string $key, int $default): int
{
    if (! array_key_exists($key, $options)) {
        return $default;
    }

    return (int) $options[$key];
}

function cli_opt_bool(array $options, string $key, bool $default): bool
{
    if (! array_key_exists($key, $options)) {
        return $default;
    }

    return filter_var((string) $options[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
}

function cli_out(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}

function cli_err(string $message): void
{
    fwrite(STDERR, $message.PHP_EOL);
}

function cli_runtime_table_check(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*)
                           FROM information_schema.tables
                           WHERE table_schema = DATABASE()
                             AND table_name = :table_name');
    $stmt->execute(['table_name' => $tableName]);
    return ((int) ($stmt->fetchColumn() ?: 0)) > 0;
}

function cli_infra_doctor(array $config): int
{
    $checks = [];

    $requiredExtensions = [
        'pdo_mysql',
        'curl',
        'json',
        'mbstring',
        'openssl',
        'fileinfo',
        'session',
    ];

    $checks[] = ['PHP >= 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION];
    foreach ($requiredExtensions as $ext) {
        $checks[] = ['PHP extension: '.$ext, extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing'];
    }

    $objectRoot = object_storage_root($config);
    $checks[] = ['Object storage root exists', is_dir($objectRoot), $objectRoot];
    $checks[] = ['Object storage writable', is_writable($objectRoot), $objectRoot];

    $logDir = realpath(__DIR__.'/../logs') ?: (__DIR__.'/../logs');
    if (! is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $checks[] = ['Log directory exists', is_dir($logDir), $logDir];
    $checks[] = ['Log directory writable', is_writable($logDir), $logDir];

    $dbOk = false;
    $pdo = null;
    try {
        $pdo = Database::pdo($config);
        $dbOk = true;
    } catch (Throwable $e) {
        $checks[] = ['Database connectivity', false, $e->getMessage()];
    }

    if ($dbOk && $pdo instanceof PDO) {
        $checks[] = ['Database connectivity', true, 'connected'];
        ensure_runtime_support_tables($pdo);
        if (function_exists('web_ensure_optional_tables')) {
            web_ensure_optional_tables($pdo);
        }

        $requiredTables = [
            'organizations',
            'companies',
            'users',
            'vendors',
            'purchase_orders',
            'goods_receipts',
            'invoices',
            'approvals',
            'payments',
            'tax_reconciliations',
            'notifications',
            'integration_jobs',
            'idempotency_keys',
            'request_rate_limits',
            'audit_events',
        ];

        foreach ($requiredTables as $table) {
            $exists = cli_runtime_table_check($pdo, $table);
            $checks[] = ['Schema table: '.$table, $exists, $exists ? 'present' : 'missing'];
        }
    }

    cli_out('Infra Doctor Report');
    cli_out(str_repeat('-', 80));

    $failed = 0;
    foreach ($checks as $check) {
        $ok = (bool) $check[1];
        $label = (string) $check[0];
        $detail = (string) ($check[2] ?? '');
        $status = $ok ? 'PASS' : 'FAIL';
        if (! $ok) {
            $failed++;
        }

        cli_out(sprintf('[%s] %s%s', $status, $label, $detail !== '' ? ' :: '.$detail : ''));
    }

    cli_out(str_repeat('-', 80));
    cli_out('Result: '.($failed === 0 ? 'HEALTHY' : ('FAILED CHECKS: '.$failed)));

    return $failed === 0 ? 0 : 1;
}

function cli_server_pdo(array $config): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        $config['db']['host'],
        (int) $config['db']['port'],
        $config['db']['charset']
    );

    return new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function cli_split_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inSingle = false;
    $inDouble = false;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if ($ch === "'" && ! $inDouble && $prev !== '\\') {
            $inSingle = ! $inSingle;
        } elseif ($ch === '"' && ! $inSingle && $prev !== '\\') {
            $inDouble = ! $inDouble;
        }

        if ($ch === ';' && ! $inSingle && ! $inDouble) {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $ch;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function cli_sql_without_db_directives(string $sql): string
{
    $out = preg_replace('/^\s*CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+[^;]+;\s*$/mi', '', $sql);
    $out = preg_replace('/^\s*USE\s+[^;]+;\s*$/mi', '', (string) $out);

    return (string) $out;
}

function cli_exec_sql_file(PDO $pdo, string $file): int
{
    if (! is_file($file)) {
        throw new RuntimeException('SQL file not found: '.$file);
    }

    $raw = (string) file_get_contents($file);
    if ($raw === '') {
        return 0;
    }

    $clean = cli_sql_without_db_directives($raw);
    $statements = cli_split_sql_statements($clean);

    $count = 0;
    foreach ($statements as $statement) {
        $trim = trim($statement);
        if ($trim === '') {
            continue;
        }

        $pdo->exec($trim);
        $count++;
    }

    return $count;
}

function cli_db_init(array $config, array $options): int
{
    $seed = cli_opt_bool($options, 'seed', true);
    $schemaFile = realpath(__DIR__.'/../database/schema.sql');

    if ($schemaFile === false) {
        cli_err('Schema file missing.');
        return 1;
    }

    $dbName = (string) $config['db']['name'];
    if ($dbName === '') {
        cli_err('DB_NAME is empty.');
        return 1;
    }

    $serverPdo = cli_server_pdo($config);
    $serverPdo->exec('CREATE DATABASE IF NOT EXISTS `'.str_replace('`', '``', $dbName).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $pdo = Database::pdo($config);
    cli_out('Applying schema: '.$schemaFile);
    $schemaStatements = cli_exec_sql_file($pdo, $schemaFile);
    cli_out('Schema statements executed: '.$schemaStatements);

    if (function_exists('web_ensure_optional_tables')) {
        web_ensure_optional_tables($pdo);
    }
    ensure_runtime_support_tables($pdo);

    if ($seed) {
        return cli_db_seed($config);
    }

    cli_out('Database init completed without seeding.');
    return 0;
}

function cli_db_seed(array $config): int
{
    $seedFile = realpath(__DIR__.'/../database/seed.sql');
    if ($seedFile === false) {
        cli_err('Seed file missing.');
        return 1;
    }

    $pdo = Database::pdo($config);
    cli_out('Applying seed: '.$seedFile);
    $seedStatements = cli_exec_sql_file($pdo, $seedFile);
    cli_out('Seed statements executed: '.$seedStatements);

    if (function_exists('web_ensure_optional_tables')) {
        web_ensure_optional_tables($pdo);
    }
    ensure_runtime_support_tables($pdo);

    cli_out('Seed completed.');
    return 0;
}

function cli_worker_run(array $config, array $options): int
{
    $limit = max(1, min(200, cli_opt_int($options, 'limit', 20)));
    $actor = max(1, cli_opt_int($options, 'actor', 1));
    $companyOpt = strtolower(trim((string) ($options['company'] ?? 'all')));
    $companyId = $companyOpt === '' || $companyOpt === 'all' ? null : max(1, (int) $companyOpt);

    $pdo = Database::pdo($config);
    $summary = IntegrationJobWorker::runDueJobs($pdo, $config, $companyId, $actor, $limit);
    cli_out(json_encode(['company' => $companyId, 'limit' => $limit, 'summary' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return 0;
}

function cli_worker_loop(array $config, array $options): int
{
    $interval = max(2, cli_opt_int($options, 'interval', 15));
    $limit = max(1, min(200, cli_opt_int($options, 'limit', 50)));
    $actor = max(1, cli_opt_int($options, 'actor', 1));
    $iterations = max(0, cli_opt_int($options, 'iterations', 0));
    $companyOpt = strtolower(trim((string) ($options['company'] ?? 'all')));
    $companyId = $companyOpt === '' || $companyOpt === 'all' ? null : max(1, (int) $companyOpt);

    $pdo = Database::pdo($config);
    $i = 0;

    cli_out('Starting worker loop (interval='.$interval.'s, limit='.$limit.', company='.(string) ($companyId ?? 'all').').');
    while (true) {
        $started = microtime(true);
        $summary = IntegrationJobWorker::runDueJobs($pdo, $config, $companyId, $actor, $limit);
        $elapsed = round((microtime(true) - $started), 3);

        cli_out(sprintf(
            '[%s] picked=%d completed=%d retrying=%d dead_letter=%d errors=%d elapsed=%ss',
            gmdate('Y-m-d H:i:s'),
            (int) $summary['picked'],
            (int) $summary['completed'],
            (int) $summary['retrying'],
            (int) $summary['dead_letter'],
            (int) $summary['errors'],
            (string) $elapsed
        ));

        $i++;
        if ($iterations > 0 && $i >= $iterations) {
            break;
        }

        sleep($interval);
    }

    return 0;
}

function cli_integrations_preflight(array $config, array $options): int
{
    $probe = cli_opt_bool($options, 'probe', false);
    $companyOpt = strtolower(trim((string) ($options['company'] ?? '1')));
    $companyId = ($companyOpt === '' || $companyOpt === 'all')
        ? null
        : max(1, (int) $companyOpt);

    $appliedKeys = 0;
    $runtimeSource = '.env/process only';
    if ($companyId !== null) {
        $pdo = Database::pdo($config);
        if (function_exists('web_sync_company_integrations')) {
            web_sync_company_integrations($pdo, $companyId);
        }
        $appliedKeys = IntegrationRuntimeConfig::applyForCompany($pdo, $companyId, false, null);
        $runtimeSource = 'company #'.$companyId.' in-app vault + .env fallback';
    } else {
        IntegrationRuntimeConfig::resetToBaseline();
    }

    $matrix = [
        [
            'name' => 'Bank API',
            'required' => ['BANK_API_BASE_URL'],
            'optional' => ['BANK_API_TOKEN', 'BANK_API_KEY'],
            'probe_url' => 'BANK_API_BASE_URL',
            'probe_token' => 'BANK_API_TOKEN',
        ],
        [
            'name' => 'ERP Sync',
            'required' => [],
            'required_any' => ['ERP_SYNC_URL', 'ZOHO_BOOKS_SYNC_ENDPOINT', 'TALLY_SYNC_URL'],
            'optional' => ['ERP_SYNC_TOKEN'],
            'probe_url_candidates' => ['ERP_SYNC_URL', 'ZOHO_BOOKS_SYNC_ENDPOINT', 'TALLY_SYNC_URL'],
            'probe_token' => 'ERP_SYNC_TOKEN',
        ],
        [
            'name' => 'Slack Webhook',
            'required' => ['SLACK_WEBHOOK_URL'],
            'optional' => [],
        ],
        [
            'name' => 'WhatsApp Cloud',
            'required' => ['WHATSAPP_ACCESS_TOKEN', 'WHATSAPP_PHONE_NUMBER_ID'],
            'optional' => ['WHATSAPP_TEST_TO'],
        ],
        [
            'name' => 'Mail Inbound IMAP',
            'required' => ['MAIL_INBOUND_IMAP_HOST', 'MAIL_INBOUND_IMAP_USERNAME', 'MAIL_INBOUND_IMAP_PASSWORD'],
            'optional' => ['MAIL_INBOUND_IMAP_MOVE_TO'],
        ],
        [
            'name' => 'Tax Reconciliation',
            'required' => ['TAX_API_BASE_URL'],
            'optional' => ['TAX_API_TOKEN'],
            'probe_url' => 'TAX_API_BASE_URL',
            'probe_token' => 'TAX_API_TOKEN',
        ],
        [
            'name' => 'MCA Validation',
            'required' => ['MCA_VERIFICATION_URL'],
            'optional' => ['MCA_API_TOKEN'],
            'probe_url' => 'MCA_VERIFICATION_URL',
            'probe_token' => 'MCA_API_TOKEN',
        ],
        [
            'name' => 'Identity Sync (Google)',
            'required' => ['GOOGLE_WORKSPACE_SYNC_URL'],
            'optional' => ['IDENTITY_SYNC_TOKEN'],
            'probe_url' => 'GOOGLE_WORKSPACE_SYNC_URL',
            'probe_token' => 'IDENTITY_SYNC_TOKEN',
        ],
        [
            'name' => 'Identity Sync (Microsoft)',
            'required' => ['MICROSOFT_AD_SYNC_URL'],
            'optional' => ['IDENTITY_SYNC_TOKEN'],
            'probe_url' => 'MICROSOFT_AD_SYNC_URL',
            'probe_token' => 'IDENTITY_SYNC_TOKEN',
        ],
    ];

    cli_out('Integrations Preflight');
    cli_out(str_repeat('-', 80));
    cli_out('Runtime source: '.$runtimeSource);
    if ($companyId !== null) {
        cli_out('Applied in-app keys: '.$appliedKeys);
    }
    cli_out(str_repeat('-', 80));

    $failures = 0;

    foreach ($matrix as $item) {
        $name = (string) $item['name'];
        $required = is_array($item['required'] ?? null) ? $item['required'] : [];
        $requiredAny = is_array($item['required_any'] ?? null) ? $item['required_any'] : [];
        $missing = [];

        foreach ($required as $key) {
            if (trim((string) getenv($key)) === '') {
                $missing[] = $key;
            }
        }

        if ($requiredAny !== []) {
            $anySatisfied = false;
            foreach ($requiredAny as $key) {
                if (trim((string) getenv((string) $key)) !== '') {
                    $anySatisfied = true;
                    break;
                }
            }
            if (! $anySatisfied) {
                $missing[] = implode(' OR ', $requiredAny);
            }
        }

        if ($missing === []) {
            cli_out('[READY] '.$name);
        } else {
            cli_out('[MISSING] '.$name.' :: '.implode(', ', $missing));
            $failures++;
        }

        if ($probe && $missing === []) {
            $url = '';
            if (isset($item['probe_url'])) {
                $url = trim((string) getenv((string) $item['probe_url']));
            } elseif (isset($item['probe_url_candidates']) && is_array($item['probe_url_candidates'])) {
                foreach ($item['probe_url_candidates'] as $candidateKey) {
                    $candidateUrl = trim((string) getenv((string) $candidateKey));
                    if ($candidateUrl !== '') {
                        $url = $candidateUrl;
                        break;
                    }
                }
            }

            if ($url !== '' && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))) {
                $token = '';
                if (isset($item['probe_token'])) {
                    $token = trim((string) getenv((string) $item['probe_token']));
                }

                $headers = [];
                if ($token !== '') {
                    $headers['Authorization'] = 'Bearer '.$token;
                }

                try {
                    $result = HttpClient::request('GET', $url, $headers, null, 10);
                    $status = (int) $result['status_code'];
                    cli_out('  probe '.$url.' -> HTTP '.$status);
                } catch (Throwable $e) {
                    cli_out('  probe '.$url.' -> FAIL '.$e->getMessage());
                    $failures++;
                }
            }
        }
    }

    cli_out(str_repeat('-', 80));
    cli_out($failures === 0 ? 'Preflight completed: READY' : ('Preflight completed with '.$failures.' issue(s).'));

    return $failures === 0 ? 0 : 1;
}

function cli_find_binary(array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        if (str_contains($candidate, '/')) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
            continue;
        }

        $resolved = trim((string) shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));
        if ($resolved !== '' && is_executable($resolved)) {
            return $resolved;
        }
    }

    return null;
}

function cli_backup_create(array $config, array $options): int
{
    $dumpBinary = cli_find_binary([
        (string) getenv('MYSQLDUMP_BIN'),
        '/Applications/XAMPP/xamppfiles/bin/mysqldump',
        '/opt/homebrew/bin/mysqldump',
        '/usr/local/bin/mysqldump',
        'mysqldump',
    ]);

    if ($dumpBinary === null) {
        cli_err('mysqldump binary not found. Set MYSQLDUMP_BIN in environment.');
        return 1;
    }

    $defaultOut = realpath(__DIR__.'/../storage/backups') ?: (__DIR__.'/../storage/backups');
    if (! is_dir($defaultOut)) {
        @mkdir($defaultOut, 0775, true);
    }

    $outFile = trim((string) ($options['out'] ?? ''));
    if ($outFile === '') {
        $outFile = rtrim($defaultOut, '/').'/'.($config['db']['name'] ?? 'pazy_plain').'_'.gmdate('Ymd_His').'.sql';
    }

    $db = $config['db'];
    $command = [
        $dumpBinary,
        '--host='.(string) $db['host'],
        '--port='.(string) ((int) $db['port']),
        '--user='.(string) $db['user'],
        '--default-character-set='.(string) $db['charset'],
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        (string) $db['name'],
    ];

    if ((string) $db['pass'] !== '') {
        $command[] = '--password='.(string) $db['pass'];
    }

    $descriptors = [
        1 => ['file', $outFile, 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);
    if (! is_resource($process)) {
        cli_err('Unable to start mysqldump process.');
        return 1;
    }

    $stderr = is_resource($pipes[2] ?? null) ? stream_get_contents($pipes[2]) : '';
    if (is_resource($pipes[2] ?? null)) {
        fclose($pipes[2]);
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        cli_err('Backup failed: '.trim((string) $stderr));
        return 1;
    }

    cli_out('Backup created: '.$outFile);
    return 0;
}

function cli_backup_restore(array $config, array $options): int
{
    $file = trim((string) ($options['file'] ?? ''));
    if ($file === '' || ! is_file($file)) {
        cli_err('Provide a valid --file=/absolute/path.sql');
        return 1;
    }

    $mysqlBinary = cli_find_binary([
        (string) getenv('MYSQL_BIN'),
        '/Applications/XAMPP/xamppfiles/bin/mysql',
        '/opt/homebrew/bin/mysql',
        '/usr/local/bin/mysql',
        'mysql',
    ]);

    if ($mysqlBinary === null) {
        cli_err('mysql client binary not found. Set MYSQL_BIN in environment.');
        return 1;
    }

    $db = $config['db'];
    $command = [
        $mysqlBinary,
        '--host='.(string) $db['host'],
        '--port='.(string) ((int) $db['port']),
        '--user='.(string) $db['user'],
        '--default-character-set='.(string) $db['charset'],
        (string) $db['name'],
    ];

    if ((string) $db['pass'] !== '') {
        $command[] = '--password='.(string) $db['pass'];
    }

    $descriptors = [
        0 => ['file', $file, 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);
    if (! is_resource($process)) {
        cli_err('Unable to start mysql restore process.');
        return 1;
    }

    $stdout = is_resource($pipes[1] ?? null) ? stream_get_contents($pipes[1]) : '';
    $stderr = is_resource($pipes[2] ?? null) ? stream_get_contents($pipes[2]) : '';

    if (is_resource($pipes[1] ?? null)) {
        fclose($pipes[1]);
    }
    if (is_resource($pipes[2] ?? null)) {
        fclose($pipes[2]);
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        cli_err('Restore failed: '.trim($stderr !== '' ? $stderr : $stdout));
        return 1;
    }

    cli_out('Restore completed from: '.$file);
    return 0;
}

function cli_fetch_invoice_status(PDO $pdo, int $invoiceId): string
{
    $stmt = $pdo->prepare('SELECT status FROM invoices WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $invoiceId]);
    return (string) ($stmt->fetchColumn() ?: '');
}

function cli_fetch_payment_status(PDO $pdo, int $paymentId): string
{
    $stmt = $pdo->prepare('SELECT status FROM payments WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $paymentId]);
    return (string) ($stmt->fetchColumn() ?: '');
}

function cli_approve_all_pending(PDO $pdo, int $companyId, string $entityType, int $entityId): void
{
    while (true) {
        $pending = $pdo->prepare('SELECT id, approver_user_id
                                  FROM approvals
                                  WHERE company_id = :company_id
                                    AND entity_type = :entity_type
                                    AND entity_id = :entity_id
                                    AND status = "pending"
                                  ORDER BY level_order ASC, id ASC
                                  LIMIT 1');
        $pending->execute([
            'company_id' => $companyId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
        $row = $pending->fetch();

        if (! $row) {
            break;
        }

        $result = ApprovalEngine::decide(
            $pdo,
            $companyId,
            (int) $row['id'],
            (int) $row['approver_user_id'],
            'approved',
            'smoke auto-approval'
        );
        api_apply_final_decision_to_entity($pdo, $result);
    }
}

function cli_qa_smoke(array $config, array $options): int
{
    $companyId = max(1, cli_opt_int($options, 'company', 1));
    $makerUserId = 2;

    $pdo = Database::pdo($config);
    ensure_runtime_support_tables($pdo);
    if (function_exists('web_ensure_optional_tables')) {
        web_ensure_optional_tables($pdo);
    }

    $runKey = gmdate('YmdHis');
    $results = [];

    try {
        $pdo->beginTransaction();

        // 1) Invoice flow: capture -> match -> approval -> payment approval -> execute.
        $vendorStmt = $pdo->prepare('INSERT INTO vendors
            (company_id, name, email, phone, tax_id, bank_account_masked, compliance_score, status, created_at, updated_at)
            VALUES
            (:company_id, :name, :email, :phone, :tax_id, :bank_account_masked, :compliance_score, :status, :created_at, :updated_at)');
        $vendorStmt->execute([
            'company_id' => $companyId,
            'name' => 'Smoke Vendor '.$runKey,
            'email' => 'smoke+'.$runKey.'@example.local',
            'phone' => '+91-9000011111',
            'tax_id' => '29ABCDE1234F1Z5',
            'bank_account_masked' => 'XXXXXX8899',
            'compliance_score' => 90,
            'status' => 'active',
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        $vendorId = (int) $pdo->lastInsertId();

        $poStmt = $pdo->prepare('INSERT INTO purchase_orders
            (company_id, vendor_id, po_number, po_date, total_amount, status, requester_user_id, created_at, updated_at)
            VALUES
            (:company_id, :vendor_id, :po_number, :po_date, :total_amount, :status, :requester_user_id, :created_at, :updated_at)');
        $poAmount = 1225.50;
        $poStmt->execute([
            'company_id' => $companyId,
            'vendor_id' => $vendorId,
            'po_number' => 'SMK-PO-'.$runKey,
            'po_date' => today_utc(),
            'total_amount' => $poAmount,
            'status' => 'submitted',
            'requester_user_id' => $makerUserId,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        $poId = (int) $pdo->lastInsertId();

        $grnStmt = $pdo->prepare('INSERT INTO goods_receipts
            (company_id, po_id, grn_number, received_date, status, receiver_user_id, created_at, updated_at)
            VALUES
            (:company_id, :po_id, :grn_number, :received_date, :status, :receiver_user_id, :created_at, :updated_at)');
        $grnStmt->execute([
            'company_id' => $companyId,
            'po_id' => $poId,
            'grn_number' => 'SMK-GRN-'.$runKey,
            'received_date' => today_utc(),
            'status' => 'received',
            'receiver_user_id' => 3,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        $grnId = (int) $pdo->lastInsertId();

        $invoiceStmt = $pdo->prepare('INSERT INTO invoices
            (company_id, vendor_id, po_id, grn_id, invoice_number, invoice_date, due_date, subtotal_amount, tax_amount, total_amount, source_channel, extracted_data_json, status, created_by, created_at, updated_at)
            VALUES
            (:company_id, :vendor_id, :po_id, :grn_id, :invoice_number, :invoice_date, :due_date, :subtotal_amount, :tax_amount, :total_amount, :source_channel, :extracted_data_json, :status, :created_by, :created_at, :updated_at)');
        $invoiceStmt->execute([
            'company_id' => $companyId,
            'vendor_id' => $vendorId,
            'po_id' => $poId,
            'grn_id' => $grnId,
            'invoice_number' => 'SMK-INV-'.$runKey,
            'invoice_date' => today_utc(),
            'due_date' => today_utc(),
            'subtotal_amount' => 1038.56,
            'tax_amount' => 186.94,
            'total_amount' => $poAmount,
            'source_channel' => 'web',
            'extracted_data_json' => json_encode(['provider' => 'smoke'], JSON_THROW_ON_ERROR),
            'status' => 'captured',
            'created_by' => $makerUserId,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $match = MatchingEngine::evaluateInvoice($pdo, $config, $companyId, $invoiceId, $makerUserId);
        if (($match['status'] ?? '') !== 'matched') {
            throw new RuntimeException('Invoice matching failed in smoke test.');
        }

        $pdo->prepare('UPDATE invoices SET status = "pending_approval", updated_at = :updated_at WHERE id = :id')
            ->execute(['updated_at' => now_utc(), 'id' => $invoiceId]);

        ApprovalEngine::createFlow($pdo, $companyId, 'invoice', $invoiceId, $poAmount, $makerUserId, null, $vendorId);
        cli_approve_all_pending($pdo, $companyId, 'invoice', $invoiceId);

        if (cli_fetch_invoice_status($pdo, $invoiceId) !== 'approved') {
            throw new RuntimeException('Invoice did not reach approved state.');
        }

        $paymentIdempotency = 'smk-pay-'.$runKey;
        $payStmt = $pdo->prepare('INSERT INTO payments
            (company_id, source_type, source_id, payee_type, payee_id, amount, currency_code, payment_mode, status, idempotency_key, maker_user_id, scheduled_for, created_at, updated_at)
            VALUES
            (:company_id, :source_type, :source_id, :payee_type, :payee_id, :amount, :currency_code, :payment_mode, :status, :idempotency_key, :maker_user_id, :scheduled_for, :created_at, :updated_at)');
        $payStmt->execute([
            'company_id' => $companyId,
            'source_type' => 'invoice',
            'source_id' => $invoiceId,
            'payee_type' => 'vendor',
            'payee_id' => $vendorId,
            'amount' => $poAmount,
            'currency_code' => 'INR',
            'payment_mode' => 'NEFT',
            'status' => 'pending_approval',
            'idempotency_key' => $paymentIdempotency,
            'maker_user_id' => $makerUserId,
            'scheduled_for' => now_utc(),
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        $paymentId = (int) $pdo->lastInsertId();

        ApprovalEngine::createFlow($pdo, $companyId, 'payment', $paymentId, $poAmount, $makerUserId, null, null);
        cli_approve_all_pending($pdo, $companyId, 'payment', $paymentId);

        if (cli_fetch_payment_status($pdo, $paymentId) !== 'approved') {
            throw new RuntimeException('Payment did not reach approved state.');
        }

        $paymentResult = PaymentEngine::execute($pdo, $companyId, $paymentId, 3);
        if (($paymentResult['status'] ?? '') !== 'completed') {
            throw new RuntimeException('Payment execution did not complete.');
        }

        // 2) Reimbursement: out-of-policy flag and in-policy approval.
        $expenseStmt = $pdo->prepare('INSERT INTO expense_claims
            (company_id, user_id, category, department_code, source_channel, description, expense_date, start_location, end_location, distance_km, mileage_rate, mileage_amount, amount, currency_code, proof_count, status, created_at, updated_at)
            VALUES
            (:company_id, :user_id, :category, :department_code, :source_channel, :description, :expense_date, :start_location, :end_location, :distance_km, :mileage_rate, :mileage_amount, :amount, :currency_code, :proof_count, :status, :created_at, :updated_at)');

        $expenseStmt->execute([
            'company_id' => $companyId,
            'user_id' => 4,
            'category' => 'Meals',
            'department_code' => 'OPS',
            'source_channel' => 'web',
            'description' => 'Smoke out-of-policy',
            'expense_date' => today_utc(),
            'start_location' => null,
            'end_location' => null,
            'distance_km' => null,
            'mileage_rate' => null,
            'mileage_amount' => null,
            'amount' => 99000.00,
            'currency_code' => 'INR',
            'proof_count' => 0,
            'status' => 'submitted',
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        $expenseFlaggedId = (int) $pdo->lastInsertId();

        $flagResult = ExpensePolicyEngine::evaluate($pdo, $companyId, $expenseFlaggedId);
        if (($flagResult['status'] ?? '') !== 'policy_flagged') {
            throw new RuntimeException('Expense policy flag test failed.');
        }

        $expenseStmt->execute([
            'company_id' => $companyId,
            'user_id' => 4,
            'category' => 'Travel',
            'department_code' => 'OPS',
            'source_channel' => 'web',
            'description' => 'Smoke in-policy',
            'expense_date' => today_utc(),
            'start_location' => 'A',
            'end_location' => 'B',
            'distance_km' => 6,
            'mileage_rate' => 20,
            'mileage_amount' => 120,
            'amount' => 2200.00,
            'currency_code' => 'INR',
            'proof_count' => 1,
            'status' => 'submitted',
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        $expenseId = (int) $pdo->lastInsertId();

        $expenseEval = ExpensePolicyEngine::evaluate($pdo, $companyId, $expenseId);
        if (($expenseEval['status'] ?? '') !== 'pending_approval') {
            throw new RuntimeException('In-policy expense did not move to pending approval.');
        }
        ApprovalEngine::createFlow($pdo, $companyId, 'expense', $expenseId, 2200.00, 4, 'OPS', null);
        cli_approve_all_pending($pdo, $companyId, 'expense', $expenseId);

        $expenseStatusStmt = $pdo->prepare('SELECT status FROM expense_claims WHERE id = :id LIMIT 1');
        $expenseStatusStmt->execute(['id' => $expenseId]);
        $expenseStatus = (string) ($expenseStatusStmt->fetchColumn() ?: '');
        if ($expenseStatus !== 'approved') {
            throw new RuntimeException('Expense approval flow failed.');
        }

        // 3) Tax hold: approved invoice with missing vendor tax id should be held.
        $vendorStmt->execute([
            'company_id' => $companyId,
            'name' => 'Smoke NonTax Vendor '.$runKey,
            'email' => 'nontax+'.$runKey.'@example.local',
            'phone' => '+91-9000022222',
            'tax_id' => '',
            'bank_account_masked' => 'XXXXXX2233',
            'compliance_score' => 50,
            'status' => 'active',
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        $vendorNoTaxId = (int) $pdo->lastInsertId();

        $poStmt->execute([
            'company_id' => $companyId,
            'vendor_id' => $vendorNoTaxId,
            'po_number' => 'SMK-PO-NOTAX-'.$runKey,
            'po_date' => today_utc(),
            'total_amount' => 1000.00,
            'status' => 'submitted',
            'requester_user_id' => $makerUserId,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        $poNoTaxId = (int) $pdo->lastInsertId();

        $grnStmt->execute([
            'company_id' => $companyId,
            'po_id' => $poNoTaxId,
            'grn_number' => 'SMK-GRN-NOTAX-'.$runKey,
            'received_date' => today_utc(),
            'status' => 'received',
            'receiver_user_id' => 3,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        $grnNoTaxId = (int) $pdo->lastInsertId();

        $invoiceStmt->execute([
            'company_id' => $companyId,
            'vendor_id' => $vendorNoTaxId,
            'po_id' => $poNoTaxId,
            'grn_id' => $grnNoTaxId,
            'invoice_number' => 'SMK-INV-NOTAX-'.$runKey,
            'invoice_date' => today_utc(),
            'due_date' => today_utc(),
            'subtotal_amount' => 1000.00,
            'tax_amount' => 0,
            'total_amount' => 1000.00,
            'source_channel' => 'web',
            'extracted_data_json' => json_encode(['provider' => 'smoke'], JSON_THROW_ON_ERROR),
            'status' => 'captured',
            'created_by' => $makerUserId,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        $invoiceNoTaxId = (int) $pdo->lastInsertId();

        $matchNoTax = MatchingEngine::evaluateInvoice($pdo, $config, $companyId, $invoiceNoTaxId, $makerUserId);
        if (($matchNoTax['status'] ?? '') !== 'matched') {
            throw new RuntimeException('No-tax invoice matching failed.');
        }

        $pdo->prepare('UPDATE invoices SET status = "pending_approval", updated_at = :updated_at WHERE id = :id')
            ->execute(['updated_at' => now_utc(), 'id' => $invoiceNoTaxId]);
        ApprovalEngine::createFlow($pdo, $companyId, 'invoice', $invoiceNoTaxId, 1000.00, $makerUserId, null, $vendorNoTaxId);
        cli_approve_all_pending($pdo, $companyId, 'invoice', $invoiceNoTaxId);

        $taxProcessed = TaxReconciliationEngine::run($pdo, $companyId, $makerUserId);
        if ($taxProcessed <= 0) {
            throw new RuntimeException('Tax reconciliation did not process invoices.');
        }

        $taxCheck = $pdo->prepare('SELECT recommendation
                                   FROM tax_reconciliations
                                   WHERE company_id = :company_id AND invoice_id = :invoice_id
                                   ORDER BY id DESC
                                   LIMIT 1');
        $taxCheck->execute([
            'company_id' => $companyId,
            'invoice_id' => $invoiceNoTaxId,
        ]);
        $recommendation = (string) ($taxCheck->fetchColumn() ?: '');
        if ($recommendation !== 'hold') {
            throw new RuntimeException('Tax hold rule failed for missing vendor tax id invoice.');
        }

        $results = [
            'invoice_flow' => [
                'invoice_id' => $invoiceId,
                'payment_id' => $paymentId,
                'payment_utr' => (string) ($paymentResult['utr_reference'] ?? ''),
            ],
            'reimbursement_flow' => [
                'flagged_claim_id' => $expenseFlaggedId,
                'approved_claim_id' => $expenseId,
            ],
            'tax_flow' => [
                'invoice_id' => $invoiceNoTaxId,
                'recommendation' => $recommendation,
            ],
        ];

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        cli_err('Smoke test failed: '.$e->getMessage());
        return 1;
    }

    cli_out('Smoke QA passed');
    cli_out(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return 0;
}
