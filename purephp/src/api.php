<?php

declare(strict_types=1);

use Pazy\Integrations\ProviderRegistry;
use Pazy\Modules\Approvals\ApprovalEngine;
use Pazy\Modules\Audit\AuditService;
use Pazy\Modules\Expenses\ExpensePolicyEngine;
use Pazy\Modules\Integrations\IntegrationJobWorker;
use Pazy\Modules\Integrations\IntegrationRuntimeConfig;
use Pazy\Modules\Payments\PaymentEngine;
use Pazy\Modules\Payments\PaymentBatchEngine;
use Pazy\Modules\Procurement\MatchingEngine;
use Pazy\Modules\Tax\TaxReconciliationEngine;

function handle_api_request(PDO $pdo, array $config, string $path): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $jsonBody = request_json();
    ensure_runtime_support_tables($pdo);

    $ip = request_client_ip();
    $rateCfg = $config['security']['rate_limits'] ?? [];
    $authPerMinute = max(1, (int) ($rateCfg['auth_per_minute'] ?? 15));
    $apiPerMinute = max(1, (int) ($rateCfg['api_per_minute'] ?? 300));
    $webhookPerMinute = max(1, (int) ($rateCfg['webhook_per_minute'] ?? 180));

    $limiterKey = 'api:global:'.$ip;
    $limitValue = $apiPerMinute;
    if ($path === '/api/v1/auth/login') {
        $email = strtolower(trim((string) ($jsonBody['email'] ?? '')));
        $limiterKey = 'api:auth:'.$ip.':'.$email;
        $limitValue = $authPerMinute;
    } elseif (preg_match('#^/api/v1/webhooks/([a-zA-Z0-9_-]+)/([a-zA-Z0-9_-]+)$#', $path, $m) === 1) {
        $provider = strtolower((string) ($m[1] ?? 'unknown'));
        $limiterKey = 'api:webhook:'.$provider.':'.$ip;
        $limitValue = $webhookPerMinute;
    }

    $rate = rate_limit_allow($pdo, $limiterKey, $limitValue, 60);
    header('X-RateLimit-Limit: '.(string) ($rate['limit'] ?? $limitValue));
    header('X-RateLimit-Remaining: '.(string) ($rate['remaining'] ?? 0));
    if (($rate['allowed'] ?? true) !== true) {
        $retryAfter = max(1, (int) ($rate['retry_after'] ?? 60));
        header('Retry-After: '.(string) $retryAfter);
        json_response([
            'error' => 'rate_limited',
            'retry_after_seconds' => $retryAfter,
        ], 429);
    }

    if ($path === '/api/v1/health' && $method === 'GET') {
        json_response([
            'status' => 'ok',
            'app' => $config['app']['name'],
            'time_utc' => now_utc(),
            'version' => 'v1',
            'modules' => [
                'iam',
                'vendors',
                'procurement',
                'accounts_payable',
                'reimbursements',
                'payments',
                'tax',
                'notifications',
                'integrations',
                'reporting',
            ],
        ]);
    }

    if ($path === '/api/v1/auth/login' && $method === 'POST') {
        $email = trim((string) ($jsonBody['email'] ?? ''));
        $password = (string) ($jsonBody['password'] ?? '');
        $requestedCompanyId = (int) ($jsonBody['company_id'] ?? 0);
        $tokenName = trim((string) ($jsonBody['token_name'] ?? 'api'));

        if ($email === '' || $password === '') {
            json_response(['error' => 'email and password are required'], 422);
        }

        $userStmt = $pdo->prepare('SELECT id, name, email, password_hash, status
                                   FROM users
                                   WHERE email = :email
                                   LIMIT 1');
        $userStmt->execute(['email' => $email]);
        $baseUser = $userStmt->fetch();

        if (! $baseUser || $baseUser['status'] !== 'active' || ! password_verify($password, (string) $baseUser['password_hash'])) {
            json_response(['error' => 'invalid_credentials'], 401);
        }

        $membershipStmt = $pdo->prepare('SELECT cu.company_id, c.name AS company_name, c.organization_id,
                                                r.name AS role_name, r.permissions_json
                                         FROM company_user cu
                                         JOIN companies c ON c.id = cu.company_id
                                         JOIN roles r ON r.id = cu.role_id
                                         WHERE cu.user_id = :user_id
                                           AND cu.status = "active"
                                           AND c.status = "active"
                                         ORDER BY cu.company_id ASC');
        $membershipStmt->execute(['user_id' => (int) $baseUser['id']]);
        $memberships = $membershipStmt->fetchAll();

        if ($memberships === []) {
            json_response(['error' => 'no_active_membership'], 403);
        }

        $activeMembership = $memberships[0];
        if ($requestedCompanyId > 0) {
            foreach ($memberships as $membership) {
                if ((int) $membership['company_id'] === $requestedCompanyId) {
                    $activeMembership = $membership;
                    break;
                }
            }
        }

        $sessionUser = [
            'id' => (int) $baseUser['id'],
            'name' => (string) $baseUser['name'],
            'email' => (string) $baseUser['email'],
            'company_id' => (int) $activeMembership['company_id'],
            'role_name' => (string) $activeMembership['role_name'],
            'permissions_json' => (string) ($activeMembership['permissions_json'] ?? '[]'),
        ];

        Auth::login($sessionUser, $memberships);

        $token = Auth::issueApiToken($pdo, [
            'id' => (int) $baseUser['id'],
            'company_id' => (int) $activeMembership['company_id'],
        ], $config, $tokenName);

        AuditService::log(
            $pdo,
            (int) $activeMembership['company_id'],
            (int) $baseUser['id'],
            'api.auth.login',
            'user',
            (int) $baseUser['id'],
            ['token_name' => $tokenName]
        );

        json_response([
            'token' => $token,
            'token_type' => 'Bearer',
            'company_id' => (int) $activeMembership['company_id'],
            'user' => [
                'id' => (int) $baseUser['id'],
                'name' => (string) $baseUser['name'],
                'email' => (string) $baseUser['email'],
            ],
        ]);
    }

    if (preg_match('#^/api/v1/webhooks/([a-zA-Z0-9_-]+)/([a-zA-Z0-9_-]+)$#', $path, $matches) === 1 && $method === 'POST') {
        api_handle_webhook($pdo, $config, $matches[1], $matches[2]);
    }

    $actor = api_authenticated_actor($pdo);
    if (! $actor) {
        json_response(['error' => 'unauthenticated'], 401);
    }

    $companyId = (int) $actor['company_id'];
    $userId = (int) $actor['id'];

    if (function_exists('web_sync_company_integrations')) {
        web_sync_company_integrations($pdo, $companyId);
    }
    IntegrationRuntimeConfig::applyForCompany($pdo, $companyId, true, null);

    if ($path === '/api/v1/documents/upload' && $method === 'POST') {
        $entityType = trim((string) ($_POST['entity_type'] ?? 'unlinked'));
        if ($entityType === '') {
            $entityType = 'unlinked';
        }
        $entityId = max(0, (int) ($_POST['entity_id'] ?? 0));

        if (! isset($_FILES['file'])) {
            json_response(['error' => 'file is required'], 422);
        }

        try {
            $stored = store_uploaded_file($config, (array) $_FILES['file'], $companyId, $entityType);
            $documentId = persist_document_metadata($pdo, $companyId, $entityType, $entityId, $stored, $userId);

            if ($entityType === 'expense' && $entityId > 0) {
                $pdo->prepare('INSERT INTO expense_attachments (claim_id, document_id, created_at)
                               VALUES (:claim_id, :document_id, :created_at)')
                    ->execute([
                        'claim_id' => $entityId,
                        'document_id' => $documentId,
                        'created_at' => now_utc(),
                    ]);
            }

            AuditService::log($pdo, $companyId, $userId, 'document.uploaded', 'document', $documentId, [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'object_key' => $stored['object_key'] ?? '',
            ]);

            json_response([
                'id' => $documentId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'object_key' => $stored['object_key'] ?? null,
                'mime_type' => $stored['mime_type'] ?? null,
                'size' => $stored['file_size_bytes'] ?? null,
            ], 201);
        } catch (Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
    }

    if ($path === '/api/v1/documents' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT id, entity_type, entity_id, object_key, mime_type, file_size_bytes, created_at
                               FROM documents
                               WHERE company_id = :company_id
                               ORDER BY id DESC
                               LIMIT 200');
        $stmt->execute(['company_id' => $companyId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/workers/integration-jobs/run' && $method === 'POST') {
        $limit = (int) ($jsonBody['limit'] ?? ($_POST['limit'] ?? 20));
        $summary = IntegrationJobWorker::runDueJobs($pdo, $config, $companyId, $userId, $limit);
        AuditService::log($pdo, $companyId, $userId, 'integration.worker.run', 'integration_job', null, $summary);
        json_response(['data' => $summary]);
    }

    if ($path === '/api/v1/organizations' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT DISTINCT o.id, o.name, o.slug, o.status
                               FROM organizations o
                               JOIN companies c ON c.organization_id = o.id
                               JOIN company_user cu ON cu.company_id = c.id
                               WHERE cu.user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/companies' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT c.id, c.name, c.code, c.base_currency, c.status, r.name AS role_name
                               FROM company_user cu
                               JOIN companies c ON c.id = cu.company_id
                               JOIN roles r ON r.id = cu.role_id
                               WHERE cu.user_id = :user_id
                                 AND cu.status = "active"
                               ORDER BY c.id ASC');
        $stmt->execute(['user_id' => $userId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/users' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT u.id, u.name, u.email, u.status, r.name AS role_name
                               FROM company_user cu
                               JOIN users u ON u.id = cu.user_id
                               JOIN roles r ON r.id = cu.role_id
                               WHERE cu.company_id = :company_id
                               ORDER BY u.id ASC');
        $stmt->execute(['company_id' => $companyId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/vendors' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT id, name, email, phone, tax_id, compliance_score, status, created_at
                               FROM vendors
                               WHERE company_id = :company_id
                               ORDER BY id DESC
                               LIMIT 200');
        $stmt->execute(['company_id' => $companyId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/vendors' && $method === 'POST') {
        $name = trim((string) ($jsonBody['name'] ?? ''));
        if ($name === '') {
            json_response(['error' => 'name required'], 422);
        }

        $verify = ProviderRegistry::identity()->verifyTaxIdentity(trim((string) ($jsonBody['tax_id'] ?? '')));

        $stmt = $pdo->prepare('INSERT INTO vendors
            (company_id, name, email, phone, tax_id, bank_account_masked, compliance_score, status, created_at, updated_at)
            VALUES
            (:company_id, :name, :email, :phone, :tax_id, :bank_account_masked, :compliance_score, :status, :created_at, :updated_at)');
        $stmt->execute([
            'company_id' => $companyId,
            'name' => $name,
            'email' => trim((string) ($jsonBody['email'] ?? '')),
            'phone' => trim((string) ($jsonBody['phone'] ?? '')),
            'tax_id' => trim((string) ($jsonBody['tax_id'] ?? '')),
            'bank_account_masked' => trim((string) ($jsonBody['bank_account_masked'] ?? '')),
            'compliance_score' => (int) ($verify['score'] ?? 50),
            'status' => 'active',
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);

        $id = (int) $pdo->lastInsertId();
        AuditService::log($pdo, $companyId, $userId, 'vendor.created', 'vendor', $id);

        json_response(['id' => $id, 'message' => 'vendor_created'], 201);
    }

    if ($path === '/api/v1/purchase-orders' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT id, po_number, po_date, vendor_id, total_amount, status
                               FROM purchase_orders
                               WHERE company_id = :company_id
                               ORDER BY id DESC
                               LIMIT 200');
        $stmt->execute(['company_id' => $companyId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/purchase-orders' && $method === 'POST') {
        $vendorId = (int) ($jsonBody['vendor_id'] ?? 0);
        $poNumber = trim((string) ($jsonBody['po_number'] ?? ''));
        $total = (float) ($jsonBody['total_amount'] ?? 0);

        if ($vendorId <= 0 || $poNumber === '' || $total <= 0) {
            json_response(['error' => 'vendor_id, po_number and total_amount are required'], 422);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO purchase_orders
                (company_id, vendor_id, po_number, po_date, total_amount, status, requester_user_id, created_at, updated_at)
                VALUES
                (:company_id, :vendor_id, :po_number, :po_date, :total_amount, :status, :requester_user_id, :created_at, :updated_at)');
            $stmt->execute([
                'company_id' => $companyId,
                'vendor_id' => $vendorId,
                'po_number' => $poNumber,
                'po_date' => (string) ($jsonBody['po_date'] ?? today_utc()),
                'total_amount' => $total,
                'status' => 'submitted',
                'requester_user_id' => $userId,
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);

            $poId = (int) $pdo->lastInsertId();
            ApprovalEngine::createFlow($pdo, $companyId, 'po', $poId, $total, $userId, (string) ($jsonBody['department_code'] ?? null), $vendorId);

            $pdo->commit();
            AuditService::log($pdo, $companyId, $userId, 'po.created', 'po', $poId);
            json_response(['id' => $poId, 'message' => 'po_submitted'], 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['error' => $e->getMessage()], 422);
        }
    }

    if ($path === '/api/v1/grns' && $method === 'POST') {
        $poId = (int) ($jsonBody['po_id'] ?? 0);
        $grnNumber = trim((string) ($jsonBody['grn_number'] ?? ''));

        if ($poId <= 0 || $grnNumber === '') {
            json_response(['error' => 'po_id and grn_number required'], 422);
        }

        $stmt = $pdo->prepare('INSERT INTO goods_receipts
            (company_id, po_id, grn_number, received_date, status, receiver_user_id, created_at, updated_at)
            VALUES
            (:company_id, :po_id, :grn_number, :received_date, :status, :receiver_user_id, :created_at, :updated_at)');
        $stmt->execute([
            'company_id' => $companyId,
            'po_id' => $poId,
            'grn_number' => $grnNumber,
            'received_date' => (string) ($jsonBody['received_date'] ?? today_utc()),
            'status' => 'received',
            'receiver_user_id' => $userId,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);

        $grnId = (int) $pdo->lastInsertId();
        AuditService::log($pdo, $companyId, $userId, 'grn.created', 'grn', $grnId);

        json_response(['id' => $grnId, 'message' => 'grn_created'], 201);
    }

    if ($path === '/api/v1/grns' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT g.id, g.grn_number, g.received_date, g.status, g.po_id
                               FROM goods_receipts g
                               WHERE g.company_id = :company_id
                               ORDER BY g.id DESC
                               LIMIT 200');
        $stmt->execute(['company_id' => $companyId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/invoices' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.source_channel, i.status, i.exception_reason, v.name AS vendor_name
                               FROM invoices i
                               JOIN vendors v ON v.id = i.vendor_id
                               WHERE i.company_id = :company_id
                               ORDER BY i.id DESC
                               LIMIT 200');
        $stmt->execute(['company_id' => $companyId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/invoices' && $method === 'POST') {
        $vendorId = (int) ($jsonBody['vendor_id'] ?? 0);
        $invoiceNumber = trim((string) ($jsonBody['invoice_number'] ?? ''));
        $totalAmount = (float) ($jsonBody['total_amount'] ?? 0);
        $sourceChannel = strtolower(trim((string) ($jsonBody['source_channel'] ?? 'web')));
        $sourceRef = trim((string) ($jsonBody['source_ref'] ?? ''));

        if ($vendorId <= 0 || $invoiceNumber === '' || $totalAmount <= 0) {
            json_response(['error' => 'vendor_id, invoice_number, total_amount are required'], 422);
        }

        $pdo->beginTransaction();
        try {
            $ocr = ProviderRegistry::ocr()->extractInvoice((string) ($jsonBody['document_path'] ?? ''));

            $insert = $pdo->prepare('INSERT INTO invoices
                (company_id, vendor_id, po_id, grn_id, invoice_number, invoice_date, due_date, subtotal_amount, tax_amount, total_amount, source_channel, extracted_data_json, status, created_by, created_at, updated_at)
                VALUES
                (:company_id, :vendor_id, :po_id, :grn_id, :invoice_number, :invoice_date, :due_date, :subtotal_amount, :tax_amount, :total_amount, :source_channel, :extracted_data_json, :status, :created_by, :created_at, :updated_at)');
            $insert->execute([
                'company_id' => $companyId,
                'vendor_id' => $vendorId,
                'po_id' => (int) ($jsonBody['po_id'] ?? 0) ?: null,
                'grn_id' => (int) ($jsonBody['grn_id'] ?? 0) ?: null,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => (string) ($jsonBody['invoice_date'] ?? today_utc()),
                'due_date' => (string) ($jsonBody['due_date'] ?? today_utc()),
                'subtotal_amount' => (float) ($jsonBody['subtotal_amount'] ?? $totalAmount),
                'tax_amount' => (float) ($jsonBody['tax_amount'] ?? 0),
                'total_amount' => $totalAmount,
                'source_channel' => $sourceChannel,
                'extracted_data_json' => json_encode($ocr, JSON_THROW_ON_ERROR),
                'status' => 'captured',
                'created_by' => $userId,
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);

            $invoiceId = (int) $pdo->lastInsertId();
            log_capture_event($pdo, $companyId, 'invoice', $invoiceId, $sourceChannel, $sourceRef !== '' ? $sourceRef : null, [
                'invoice_number' => $invoiceNumber,
            ], $userId);
            $match = MatchingEngine::evaluateInvoice($pdo, $config, $companyId, $invoiceId, $userId);

            if ($match['status'] === 'matched') {
                $pdo->prepare('UPDATE invoices SET status = "pending_approval", updated_at = :updated_at WHERE id = :id')
                    ->execute(['updated_at' => now_utc(), 'id' => $invoiceId]);
                ApprovalEngine::createFlow(
                    $pdo,
                    $companyId,
                    'invoice',
                    $invoiceId,
                    $totalAmount,
                    $userId,
                    (string) ($jsonBody['department_code'] ?? null),
                    $vendorId
                );
            }

            $pdo->commit();
            json_response(['id' => $invoiceId, 'match' => $match], 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['error' => $e->getMessage()], 422);
        }
    }

    if ($path === '/api/v1/expenses' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT id, category, source_channel, expense_date, distance_km, mileage_amount, amount, status, policy_flags_json
                               FROM expense_claims
                               WHERE company_id = :company_id
                               ORDER BY id DESC
                               LIMIT 200');
        $stmt->execute(['company_id' => $companyId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/expenses' && $method === 'POST') {
        $baseAmount = (float) ($jsonBody['amount'] ?? 0);
        $category = trim((string) ($jsonBody['category'] ?? ''));
        $distanceKm = isset($jsonBody['distance_km']) ? max(0, (float) $jsonBody['distance_km']) : null;
        $mileageRate = isset($jsonBody['mileage_rate']) ? max(0, (float) $jsonBody['mileage_rate']) : null;
        $mileageAmount = ($distanceKm !== null && $mileageRate !== null && $distanceKm > 0 && $mileageRate > 0)
            ? round($distanceKm * $mileageRate, 2)
            : null;
        $amount = round($baseAmount + (float) ($mileageAmount ?? 0), 2);
        $sourceChannel = strtolower(trim((string) ($jsonBody['source_channel'] ?? 'web')));
        $sourceRef = trim((string) ($jsonBody['source_ref'] ?? ''));

        if ($amount <= 0 || $category === '') {
            json_response(['error' => 'category and amount are required'], 422);
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO expense_claims
                (company_id, user_id, category, department_code, source_channel, description, expense_date, start_location, end_location, distance_km, mileage_rate, mileage_amount, amount, currency_code, proof_count, status, created_at, updated_at)
                VALUES
                (:company_id, :user_id, :category, :department_code, :source_channel, :description, :expense_date, :start_location, :end_location, :distance_km, :mileage_rate, :mileage_amount, :amount, :currency_code, :proof_count, :status, :created_at, :updated_at)');
            $insert->execute([
                'company_id' => $companyId,
                'user_id' => $userId,
                'category' => $category,
                'department_code' => trim((string) ($jsonBody['department_code'] ?? 'GEN')),
                'source_channel' => $sourceChannel,
                'description' => trim((string) ($jsonBody['description'] ?? '')),
                'expense_date' => (string) ($jsonBody['expense_date'] ?? today_utc()),
                'start_location' => trim((string) ($jsonBody['start_location'] ?? '')) ?: null,
                'end_location' => trim((string) ($jsonBody['end_location'] ?? '')) ?: null,
                'distance_km' => $distanceKm,
                'mileage_rate' => $mileageRate,
                'mileage_amount' => $mileageAmount,
                'amount' => $amount,
                'currency_code' => strtoupper((string) ($jsonBody['currency_code'] ?? $config['app']['currency'])),
                'proof_count' => max(0, (int) ($jsonBody['proof_count'] ?? 0)),
                'status' => 'submitted',
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);
            $claimId = (int) $pdo->lastInsertId();
            log_capture_event($pdo, $companyId, 'expense', $claimId, $sourceChannel, $sourceRef !== '' ? $sourceRef : null, [
                'category' => $category,
                'amount' => $amount,
            ], $userId);

            $evaluation = ExpensePolicyEngine::evaluate($pdo, $companyId, $claimId);
            if ($evaluation['status'] !== 'policy_flagged') {
                ApprovalEngine::createFlow($pdo, $companyId, 'expense', $claimId, $amount, $userId, (string) ($jsonBody['department_code'] ?? null), null);
            }

            $pdo->commit();
            json_response(['id' => $claimId, 'evaluation' => $evaluation], 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['error' => $e->getMessage()], 422);
        }
    }

    if ($path === '/api/v1/approvals' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT id, entity_type, entity_id, level_order, status, requested_by, approver_user_id, created_at
                               FROM approvals
                               WHERE company_id = :company_id
                               ORDER BY id DESC
                               LIMIT 300');
        $stmt->execute(['company_id' => $companyId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if (preg_match('#^/api/v1/approvals/(\d+)/(approve|reject)$#', $path, $matches) === 1 && $method === 'POST') {
        $approvalId = (int) $matches[1];
        $decision = $matches[2] === 'approve' ? 'approved' : 'rejected';

        $pdo->beginTransaction();
        try {
            $result = ApprovalEngine::decide($pdo, $companyId, $approvalId, $userId, $decision, trim((string) ($jsonBody['note'] ?? '')));
            api_apply_final_decision_to_entity($pdo, $result);
            $pdo->commit();
            json_response(['message' => 'approval_'.$decision, 'result' => $result]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['error' => $e->getMessage()], 422);
        }
    }

    if ($path === '/api/v1/payments' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT id, source_type, source_id, amount, currency_code, payment_mode, status, scheduled_for, utr_reference, created_at
                               FROM payments
                               WHERE company_id = :company_id
                               ORDER BY id DESC
                               LIMIT 200');
        $stmt->execute(['company_id' => $companyId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/payments' && $method === 'POST') {
        $invoiceId = (int) ($jsonBody['source_id'] ?? 0);
        $amount = (float) ($jsonBody['amount'] ?? 0);
        $idemKey = trim((string) ($jsonBody['idempotency_key'] ?? ''));
        $scheduledFor = trim((string) ($jsonBody['scheduled_for'] ?? ''));

        if ($invoiceId <= 0 || $amount <= 0 || $idemKey === '') {
            json_response(['error' => 'source_id, amount, idempotency_key required'], 422);
        }

        if (! api_register_idempotency($pdo, $companyId, 'payment.create', $idemKey, $jsonBody)) {
            json_response(['error' => 'duplicate_request'], 409);
        }

        $taxCheck = $pdo->prepare('SELECT recommendation
                                   FROM tax_reconciliations
                                   WHERE company_id = :company_id AND invoice_id = :invoice_id
                                   ORDER BY id DESC
                                   LIMIT 1');
        $taxCheck->execute(['company_id' => $companyId, 'invoice_id' => $invoiceId]);
        $recommendation = (string) ($taxCheck->fetchColumn() ?: 'release');

        $status = $recommendation === 'hold' ? 'blocked' : 'pending_approval';

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO payments
                (company_id, source_type, source_id, payee_type, payee_id, amount, currency_code, payment_mode, status, idempotency_key, maker_user_id, scheduled_for, created_at, updated_at)
                VALUES
                (:company_id, :source_type, :source_id, :payee_type, :payee_id, :amount, :currency_code, :payment_mode, :status, :idempotency_key, :maker_user_id, :scheduled_for, :created_at, :updated_at)');
            $insert->execute([
                'company_id' => $companyId,
                'source_type' => 'invoice',
                'source_id' => $invoiceId,
                'payee_type' => 'vendor',
                'payee_id' => (int) ($jsonBody['payee_id'] ?? 0),
                'amount' => $amount,
                'currency_code' => strtoupper((string) ($jsonBody['currency_code'] ?? $config['app']['currency'])),
                'payment_mode' => strtoupper((string) ($jsonBody['payment_mode'] ?? 'NEFT')),
                'status' => $status,
                'idempotency_key' => $idemKey,
                'maker_user_id' => $userId,
                'scheduled_for' => $scheduledFor !== '' ? str_replace('T', ' ', $scheduledFor) : null,
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);

            $paymentId = (int) $pdo->lastInsertId();
            if ($status === 'pending_approval') {
                ApprovalEngine::createFlow($pdo, $companyId, 'payment', $paymentId, $amount, $userId, null, null);
            }

            $pdo->commit();
            json_response(['id' => $paymentId, 'status' => $status], 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['error' => $e->getMessage()], 422);
        }
    }

    if (preg_match('#^/api/v1/payments/(\d+)/execute$#', $path, $matches) === 1 && $method === 'POST') {
        $paymentId = (int) $matches[1];

        try {
            $result = PaymentEngine::execute($pdo, $companyId, $paymentId, $userId);
            AuditService::log($pdo, $companyId, $userId, 'payment.executed', 'payment', $paymentId, $result);
            json_response(['id' => $paymentId, 'result' => $result]);
        } catch (Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
    }

    if ($path === '/api/v1/tax/reconciliations/run' && $method === 'POST') {
        $count = TaxReconciliationEngine::run($pdo, $companyId, $userId);
        AuditService::log($pdo, $companyId, $userId, 'tax.reconciliation.run', 'tax_reconciliation', null, ['count' => $count]);
        json_response(['processed' => $count]);
    }

    if ($path === '/api/v1/tax/reconciliations' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT id, invoice_id, match_status, recommendation, hold_reason, decision_status, tax_period, run_at
                               FROM tax_reconciliations
                               WHERE company_id = :company_id
                               ORDER BY id DESC
                               LIMIT 200');
        $stmt->execute(['company_id' => $companyId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/notifications' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT id, channel, subject, message_text, status, created_at, sent_at
                               FROM notifications
                               WHERE company_id = :company_id
                               ORDER BY id DESC
                               LIMIT 200');
        $stmt->execute(['company_id' => $companyId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/capture-events' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT id, entity_type, entity_id, source_channel, source_ref, payload_json, created_at
                               FROM capture_events
                               WHERE company_id = :company_id
                               ORDER BY id DESC
                               LIMIT 200');
        $stmt->execute(['company_id' => $companyId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/inbox' && $method === 'GET') {
        $pendingApprovals = $pdo->prepare('SELECT id, entity_type, entity_id, due_at, status
                                           FROM approvals
                                           WHERE company_id = :company_id
                                             AND status = "pending"
                                           ORDER BY due_at IS NULL ASC, due_at ASC, id DESC
                                           LIMIT 100');
        $pendingApprovals->execute(['company_id' => $companyId]);

        $exceptions = $pdo->prepare('SELECT id, invoice_id, reason_code, status, created_at
                                     FROM matching_exceptions
                                     WHERE company_id = :company_id
                                       AND status = "open"
                                     ORDER BY id DESC
                                     LIMIT 100');
        $exceptions->execute(['company_id' => $companyId]);

        $readyPayments = $pdo->prepare('SELECT id, source_id, payee_id, amount, currency_code, scheduled_for
                                        FROM payments
                                        WHERE company_id = :company_id
                                          AND status = "approved"
                                        ORDER BY scheduled_for IS NULL ASC, scheduled_for ASC, id DESC
                                        LIMIT 100');
        $readyPayments->execute(['company_id' => $companyId]);

        $taxHolds = $pdo->prepare('SELECT id, invoice_id, hold_reason, tax_period, run_at
                                   FROM tax_reconciliations
                                   WHERE company_id = :company_id
                                     AND recommendation = "hold"
                                   ORDER BY id DESC
                                   LIMIT 100');
        $taxHolds->execute(['company_id' => $companyId]);

        json_response([
            'data' => [
                'pending_approvals' => $pendingApprovals->fetchAll(),
                'matching_exceptions' => $exceptions->fetchAll(),
                'approved_payments' => $readyPayments->fetchAll(),
                'tax_holds' => $taxHolds->fetchAll(),
            ],
        ]);
    }

    if ($path === '/api/v1/payment-batches' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT b.id, b.batch_code, b.payment_mode, b.scheduled_for, b.status, b.total_items, b.total_amount,
                                      SUM(CASE WHEN bi.status = "dispatched" THEN 1 ELSE 0 END) AS dispatched_items
                               FROM payment_batches b
                               LEFT JOIN payment_batch_items bi ON bi.batch_id = b.id
                               WHERE b.company_id = :company_id
                               GROUP BY b.id, b.batch_code, b.payment_mode, b.scheduled_for, b.status, b.total_items, b.total_amount
                               ORDER BY b.id DESC
                               LIMIT 100');
        $stmt->execute(['company_id' => $companyId]);
        json_response(['data' => $stmt->fetchAll()]);
    }

    if ($path === '/api/v1/payment-batches' && $method === 'POST') {
        $invoiceIds = $jsonBody['invoice_ids'] ?? [];
        if (! is_array($invoiceIds)) {
            json_response(['error' => 'invoice_ids must be an array'], 422);
        }

        $paymentMode = strtoupper(trim((string) ($jsonBody['payment_mode'] ?? 'NEFT')));
        $currencyCode = strtoupper(trim((string) ($jsonBody['currency_code'] ?? $config['app']['currency'])));
        $scheduledFor = trim((string) ($jsonBody['scheduled_for'] ?? ''));

        $pdo->beginTransaction();
        try {
            $batch = PaymentBatchEngine::createFromApprovedInvoices(
                $pdo,
                $companyId,
                $userId,
                $invoiceIds,
                $paymentMode,
                $currencyCode,
                $scheduledFor !== '' ? str_replace('T', ' ', $scheduledFor) : null
            );
            $pdo->commit();
            json_response(['data' => $batch], 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['error' => $e->getMessage()], 422);
        }
    }

    if (preg_match('#^/api/v1/payment-batches/(\\d+)/dispatch$#', $path, $matches) === 1 && $method === 'POST') {
        $batchId = (int) $matches[1];
        try {
            $result = PaymentBatchEngine::queueApprovedPayments($pdo, $companyId, $batchId, $userId);
            json_response(['data' => $result]);
        } catch (Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
    }

    if (($path === '/api/v1/reports/summary' || $path === '/api/v1/reports') && $method === 'GET') {
        $kpis = [
            'vendors' => (int) $pdo->query('SELECT COUNT(*) FROM vendors WHERE company_id = '.(int) $companyId)->fetchColumn(),
            'invoices' => (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE company_id = '.(int) $companyId)->fetchColumn(),
            'approvals_pending' => (int) $pdo->query('SELECT COUNT(*) FROM approvals WHERE company_id = '.(int) $companyId.' AND status = "pending"')->fetchColumn(),
            'payments' => (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE company_id = '.(int) $companyId)->fetchColumn(),
            'expenses' => (int) $pdo->query('SELECT COUNT(*) FROM expense_claims WHERE company_id = '.(int) $companyId)->fetchColumn(),
        ];
        json_response(['data' => $kpis]);
    }

    json_response(['error' => 'endpoint_not_found', 'path' => $path], 404);
}

function api_authenticated_actor(PDO $pdo): ?array
{
    $bearer = Auth::bearerTokenFromRequest();
    if ($bearer) {
        $actor = Auth::userFromBearerToken($pdo, $bearer);
        if ($actor) {
            return $actor;
        }
    }

    $session = Auth::user();
    if ($session) {
        return [
            'id' => (int) $session['id'],
            'company_id' => (int) $session['company_id'],
            'name' => (string) $session['name'],
            'email' => (string) $session['email'],
        ];
    }

    return null;
}

function api_register_idempotency(PDO $pdo, int $companyId, string $scope, string $key, array $payload): bool
{
    $exists = $pdo->prepare('SELECT id FROM idempotency_keys
                             WHERE company_id = :company_id
                               AND key_scope = :key_scope
                               AND idempotency_key = :idempotency_key
                             LIMIT 1');
    $exists->execute([
        'company_id' => $companyId,
        'key_scope' => $scope,
        'idempotency_key' => $key,
    ]);

    if ($exists->fetch()) {
        return false;
    }

    $insert = $pdo->prepare('INSERT INTO idempotency_keys
        (company_id, key_scope, idempotency_key, request_hash, status_code, response_json, created_at)
        VALUES
        (:company_id, :key_scope, :idempotency_key, :request_hash, :status_code, :response_json, :created_at)');
    $insert->execute([
        'company_id' => $companyId,
        'key_scope' => $scope,
        'idempotency_key' => $key,
        'request_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        'status_code' => 0,
        'response_json' => null,
        'created_at' => now_utc(),
    ]);

    return true;
}

function api_decode_base64_payload(string $encoded): string
{
    $value = trim($encoded);
    if ($value === '') {
        throw new RuntimeException('Document content is empty.');
    }

    if (str_starts_with($value, 'data:')) {
        $parts = explode(',', $value, 2);
        $value = $parts[1] ?? '';
    }

    $value = str_replace(' ', '+', $value);
    $decoded = base64_decode($value, true);
    if ($decoded === false || $decoded === '') {
        throw new RuntimeException('Invalid base64 document payload.');
    }

    return $decoded;
}

function api_assert_company_exists(PDO $pdo, int $companyId): void
{
    $company = $pdo->prepare('SELECT id
                              FROM companies
                              WHERE id = :id
                                AND status = "active"
                              LIMIT 1');
    $company->execute(['id' => $companyId]);
    if (! $company->fetch()) {
        throw new RuntimeException('Invalid or inactive company_id in webhook payload.');
    }
}

function api_resolve_webhook_actor_user_id(PDO $pdo, int $companyId, array $payload): int
{
    $candidateUserId = (int) ($payload['created_by'] ?? $payload['user_id'] ?? $payload['actor_user_id'] ?? $payload['captured_by'] ?? 0);
    if ($candidateUserId > 0) {
        $lookup = $pdo->prepare('SELECT cu.user_id
                                 FROM company_user cu
                                 JOIN users u ON u.id = cu.user_id
                                 WHERE cu.company_id = :company_id
                                   AND cu.user_id = :user_id
                                   AND cu.status = "active"
                                   AND u.status = "active"
                                 LIMIT 1');
        $lookup->execute([
            'company_id' => $companyId,
            'user_id' => $candidateUserId,
        ]);
        $found = $lookup->fetchColumn();
        if ($found !== false) {
            return (int) $found;
        }
    }

    $fallback = $pdo->prepare('SELECT cu.user_id
                               FROM company_user cu
                               JOIN users u ON u.id = cu.user_id
                               JOIN roles r ON r.id = cu.role_id
                               WHERE cu.company_id = :company_id
                                 AND cu.status = "active"
                                 AND u.status = "active"
                               ORDER BY
                                 CASE
                                   WHEN JSON_CONTAINS(r.permissions_json, :perm_all) THEN 1
                                   WHEN JSON_CONTAINS(r.permissions_json, :perm_invoices) THEN 2
                                   WHEN JSON_CONTAINS(r.permissions_json, :perm_approvals) THEN 3
                                   ELSE 9
                                 END ASC,
                                 cu.user_id ASC
                               LIMIT 1');
    $fallback->execute([
        'company_id' => $companyId,
        'perm_all' => '"*"',
        'perm_invoices' => '"invoices.manage"',
        'perm_approvals' => '"approvals.decide"',
    ]);
    $resolved = $fallback->fetchColumn();
    if ($resolved === false) {
        throw new RuntimeException('No active company user available to own inbound capture.');
    }

    return (int) $resolved;
}

function api_resolve_or_create_vendor_id(PDO $pdo, int $companyId, int $actorUserId, array $payload): int
{
    $vendorId = (int) ($payload['vendor_id'] ?? 0);
    if ($vendorId > 0) {
        $exists = $pdo->prepare('SELECT id
                                 FROM vendors
                                 WHERE id = :id
                                   AND company_id = :company_id
                                 LIMIT 1');
        $exists->execute([
            'id' => $vendorId,
            'company_id' => $companyId,
        ]);
        if ($exists->fetch()) {
            return $vendorId;
        }

        throw new RuntimeException('vendor_id not found for this company.');
    }

    $vendorPayload = is_array($payload['vendor'] ?? null) ? $payload['vendor'] : [];

    $vendorName = trim((string) ($payload['vendor_name'] ?? $vendorPayload['name'] ?? ''));
    $vendorEmail = trim((string) ($payload['vendor_email'] ?? $vendorPayload['email'] ?? ''));
    $vendorPhone = trim((string) ($payload['vendor_phone'] ?? $vendorPayload['phone'] ?? ''));
    $vendorTaxId = trim((string) ($payload['vendor_tax_id'] ?? $vendorPayload['tax_id'] ?? ''));
    $bankMasked = trim((string) ($payload['vendor_bank_account_masked'] ?? $vendorPayload['bank_account_masked'] ?? ''));

    if ($vendorTaxId !== '') {
        $byTax = $pdo->prepare('SELECT id
                                FROM vendors
                                WHERE company_id = :company_id
                                  AND tax_id = :tax_id
                                LIMIT 1');
        $byTax->execute([
            'company_id' => $companyId,
            'tax_id' => $vendorTaxId,
        ]);
        $found = $byTax->fetchColumn();
        if ($found !== false) {
            return (int) $found;
        }
    }

    if ($vendorEmail !== '') {
        $byEmail = $pdo->prepare('SELECT id
                                  FROM vendors
                                  WHERE company_id = :company_id
                                    AND email = :email
                                  LIMIT 1');
        $byEmail->execute([
            'company_id' => $companyId,
            'email' => $vendorEmail,
        ]);
        $found = $byEmail->fetchColumn();
        if ($found !== false) {
            return (int) $found;
        }
    }

    if ($vendorName !== '') {
        $byName = $pdo->prepare('SELECT id
                                 FROM vendors
                                 WHERE company_id = :company_id
                                   AND name = :name
                                 LIMIT 1');
        $byName->execute([
            'company_id' => $companyId,
            'name' => $vendorName,
        ]);
        $found = $byName->fetchColumn();
        if ($found !== false) {
            return (int) $found;
        }
    }

    $resolvedName = $vendorName !== ''
        ? $vendorName
        : ('Inbound Vendor '.gmdate('Ymd-His'));
    $verify = ProviderRegistry::identity()->verifyTaxIdentity($vendorTaxId);

    $insert = $pdo->prepare('INSERT INTO vendors
        (company_id, name, email, phone, tax_id, bank_account_masked, compliance_score, status, created_at, updated_at)
        VALUES
        (:company_id, :name, :email, :phone, :tax_id, :bank_account_masked, :compliance_score, :status, :created_at, :updated_at)');
    $insert->execute([
        'company_id' => $companyId,
        'name' => $resolvedName,
        'email' => $vendorEmail !== '' ? $vendorEmail : null,
        'phone' => $vendorPhone !== '' ? $vendorPhone : null,
        'tax_id' => $vendorTaxId !== '' ? $vendorTaxId : null,
        'bank_account_masked' => $bankMasked !== '' ? $bankMasked : null,
        'compliance_score' => (int) ($verify['score'] ?? 50),
        'status' => 'active',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
    $newVendorId = (int) $pdo->lastInsertId();

    AuditService::log($pdo, $companyId, $actorUserId, 'vendor.created.from_webhook', 'vendor', $newVendorId, [
        'source' => 'messaging_webhook',
    ]);

    return $newVendorId;
}

function api_unique_invoice_number(PDO $pdo, int $companyId, string $candidate): string
{
    $base = trim($candidate);
    if ($base === '') {
        $base = 'MSG-'.gmdate('Ymd-His');
    }
    $base = substr($base, 0, 110);

    $attempt = 0;
    $invoiceNumber = $base;
    $exists = $pdo->prepare('SELECT id
                             FROM invoices
                             WHERE company_id = :company_id
                               AND invoice_number = :invoice_number
                             LIMIT 1');

    while (true) {
        $exists->execute([
            'company_id' => $companyId,
            'invoice_number' => $invoiceNumber,
        ]);
        if (! $exists->fetch()) {
            return $invoiceNumber;
        }

        $attempt++;
        $suffix = '-'.($attempt + 1);
        $invoiceNumber = substr($base, 0, max(1, 120 - strlen($suffix))).$suffix;
        if ($attempt > 50) {
            $invoiceNumber = 'MSG-'.gmdate('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
            return substr($invoiceNumber, 0, 120);
        }
    }
}

function api_ingest_messaging_invoice_webhook(PDO $pdo, array $config, string $event, array $payload): array
{
    $companyId = (int) ($payload['company_id'] ?? 0);
    if ($companyId <= 0) {
        throw new RuntimeException('company_id is required for inbound messaging webhook.');
    }
    api_assert_company_exists($pdo, $companyId);

    $actorUserId = api_resolve_webhook_actor_user_id($pdo, $companyId, $payload);
    $vendorId = api_resolve_or_create_vendor_id($pdo, $companyId, $actorUserId, $payload);

    $sourceChannel = strtolower(trim((string) ($payload['source_channel'] ?? '')));
    if ($sourceChannel === '') {
        if (str_contains(strtolower($event), 'email')) {
            $sourceChannel = 'email';
        } elseif (str_contains(strtolower($event), 'whatsapp')) {
            $sourceChannel = 'whatsapp';
        } else {
            $sourceChannel = 'whatsapp';
        }
    }
    if (! in_array($sourceChannel, ['web', 'email', 'slack', 'whatsapp', 'mobile'], true)) {
        $sourceChannel = 'whatsapp';
    }

    $sourceRef = trim((string) ($payload['source_ref'] ?? $payload['from'] ?? $payload['sender'] ?? ''));

    $documentPath = trim((string) ($payload['document_path'] ?? $payload['object_key'] ?? ''));
    $storedDocument = null;
    $documentPayload = is_array($payload['document'] ?? null) ? $payload['document'] : [];
    $base64 = trim((string) ($documentPayload['content_base64'] ?? $payload['document_base64'] ?? ''));
    if ($base64 !== '') {
        $rawBytes = api_decode_base64_payload($base64);
        $storedDocument = store_raw_file_content(
            $config,
            $rawBytes,
            trim((string) ($documentPayload['filename'] ?? $payload['document_name'] ?? 'invoice-upload')),
            trim((string) ($documentPayload['mime_type'] ?? $payload['document_mime_type'] ?? 'application/octet-stream')),
            $companyId,
            'invoice'
        );
        $documentPath = (string) ($storedDocument['object_key'] ?? $documentPath);
    }

    $totalAmount = round((float) ($payload['total_amount'] ?? $payload['amount'] ?? 0), 2);
    if ($totalAmount <= 0) {
        throw new RuntimeException('total_amount is required and must be greater than zero.');
    }

    $ocr = ProviderRegistry::ocr()->extractInvoice($documentPath);
    $invoiceNumber = api_unique_invoice_number(
        $pdo,
        $companyId,
        trim((string) ($payload['invoice_number'] ?? ($ocr['invoice_number'] ?? '')))
    );

    $invoiceDate = trim((string) ($payload['invoice_date'] ?? today_utc()));
    $dueDate = trim((string) ($payload['due_date'] ?? $invoiceDate));
    $taxAmount = round((float) ($payload['tax_amount'] ?? 0), 2);
    $subtotalAmount = round((float) ($payload['subtotal_amount'] ?? ($totalAmount - $taxAmount)), 2);
    if ($subtotalAmount < 0) {
        $subtotalAmount = 0.0;
    }

    $insert = $pdo->prepare('INSERT INTO invoices
        (company_id, vendor_id, po_id, grn_id, invoice_number, invoice_date, due_date, subtotal_amount, tax_amount, total_amount, source_channel, extracted_data_json, status, created_by, created_at, updated_at)
        VALUES
        (:company_id, :vendor_id, :po_id, :grn_id, :invoice_number, :invoice_date, :due_date, :subtotal_amount, :tax_amount, :total_amount, :source_channel, :extracted_data_json, :status, :created_by, :created_at, :updated_at)');
    $insert->execute([
        'company_id' => $companyId,
        'vendor_id' => $vendorId,
        'po_id' => (int) ($payload['po_id'] ?? 0) ?: null,
        'grn_id' => (int) ($payload['grn_id'] ?? 0) ?: null,
        'invoice_number' => $invoiceNumber,
        'invoice_date' => $invoiceDate,
        'due_date' => $dueDate,
        'subtotal_amount' => $subtotalAmount,
        'tax_amount' => $taxAmount,
        'total_amount' => $totalAmount,
        'source_channel' => $sourceChannel,
        'extracted_data_json' => json_encode($ocr, JSON_THROW_ON_ERROR),
        'status' => 'captured',
        'created_by' => $actorUserId,
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
    $invoiceId = (int) $pdo->lastInsertId();

    if ($storedDocument !== null) {
        persist_document_metadata($pdo, $companyId, 'invoice', $invoiceId, $storedDocument, $actorUserId);
    }

    log_capture_event($pdo, $companyId, 'invoice', $invoiceId, $sourceChannel, $sourceRef !== '' ? $sourceRef : null, [
        'provider' => 'messaging',
        'event' => $event,
        'message_id' => (string) ($payload['message_id'] ?? ''),
        'document_path' => $documentPath,
    ], $actorUserId);

    $match = MatchingEngine::evaluateInvoice($pdo, $config, $companyId, $invoiceId, $actorUserId);

    if ($match['status'] === 'matched') {
        $pdo->prepare('UPDATE invoices
                       SET status = "pending_approval",
                           updated_at = :updated_at
                       WHERE id = :id')
            ->execute([
                'updated_at' => now_utc(),
                'id' => $invoiceId,
            ]);

        ApprovalEngine::createFlow(
            $pdo,
            $companyId,
            'invoice',
            $invoiceId,
            $totalAmount,
            $actorUserId,
            trim((string) ($payload['department_code'] ?? '')) ?: null,
            $vendorId
        );
    }

    AuditService::log($pdo, $companyId, $actorUserId, 'invoice.captured.via_messaging', 'invoice', $invoiceId, [
        'source_channel' => $sourceChannel,
        'event' => $event,
        'match_status' => $match['status'],
        'match_reason' => $match['reason'] ?? null,
    ]);

    return [
        'invoice_id' => $invoiceId,
        'company_id' => $companyId,
        'status' => $match['status'] === 'matched' ? 'pending_approval' : 'exception',
        'match' => $match,
        'source_channel' => $sourceChannel,
        'invoice_number' => $invoiceNumber,
    ];
}

function api_handle_webhook(PDO $pdo, array $config, string $provider, string $event): void
{
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    $payload = is_array($payload) ? $payload : [];

    $idempotencyKey = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($payload['idempotency_key'] ?? '')));
    $signature = trim((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''));

    if ($idempotencyKey === '' || $signature === '') {
        json_response(['error' => 'missing_idempotency_or_signature'], 400);
    }

    $secret = (string) ($config['integrations']['webhook_secrets'][$provider] ?? '');
    if ($secret === '') {
        json_response(['error' => 'unknown_provider'], 404);
    }

    $expected = hash_hmac('sha256', $raw, $secret);
    $normalized = str_contains($signature, '=') ? explode('=', $signature, 2)[1] : $signature;

    if (! hash_equals($expected, $normalized)) {
        json_response(['error' => 'invalid_signature'], 401);
    }

    $exists = $pdo->prepare('SELECT id, status_code, response_json
                             FROM idempotency_keys
                             WHERE key_scope = :key_scope
                               AND idempotency_key = :idempotency_key
                             LIMIT 1');
    $scope = 'webhook:'.$provider.':'.$event;
    $exists->execute(['key_scope' => $scope, 'idempotency_key' => $idempotencyKey]);
    $seen = $exists->fetch();

    if ($seen) {
        $statusCode = (int) ($seen['status_code'] ?: 200);
        $response = is_string($seen['response_json']) ? json_decode($seen['response_json'], true) : ['status' => 'duplicate'];
        json_response(is_array($response) ? $response : ['status' => 'duplicate'], $statusCode > 0 ? $statusCode : 200);
    }

    $idempotencyCompanyId = max(0, (int) ($payload['company_id'] ?? 0));
    if ($idempotencyCompanyId > 0) {
        if (function_exists('web_sync_company_integrations')) {
            web_sync_company_integrations($pdo, $idempotencyCompanyId);
        }
        IntegrationRuntimeConfig::applyForCompany($pdo, $idempotencyCompanyId, false, null);
    } else {
        IntegrationRuntimeConfig::resetToBaseline();
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO idempotency_keys
            (company_id, key_scope, idempotency_key, request_hash, status_code, response_json, created_at)
            VALUES
            (:company_id, :key_scope, :idempotency_key, :request_hash, :status_code, :response_json, :created_at)')
            ->execute([
                'company_id' => $idempotencyCompanyId > 0 ? $idempotencyCompanyId : null,
                'key_scope' => $scope,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => hash('sha256', $raw),
                'status_code' => 0,
                'response_json' => null,
                'created_at' => now_utc(),
            ]);

        $pdo->prepare('INSERT INTO webhook_events
            (provider, event_name, idempotency_key, signature, payload_json, status, received_at, processed_at)
            VALUES
            (:provider, :event_name, :idempotency_key, :signature, :payload_json, :status, :received_at, :processed_at)')
            ->execute([
                'provider' => $provider,
                'event_name' => $event,
                'idempotency_key' => $idempotencyKey,
                'signature' => $signature,
                'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
                'status' => 'processed',
                'received_at' => now_utc(),
                'processed_at' => now_utc(),
            ]);

        $responsePayload = ['status' => 'accepted', 'provider' => $provider, 'event' => $event];

        if ($provider === 'bank' && $event === 'payment_completed') {
            $paymentId = (int) ($payload['payment_id'] ?? 0);
            if ($paymentId > 0) {
                $pdo->prepare('UPDATE payments
                               SET status = "completed",
                                   utr_reference = :utr_reference,
                                   executed_at = :executed_at,
                                   updated_at = :updated_at
                               WHERE id = :id')
                    ->execute([
                        'utr_reference' => (string) ($payload['utr'] ?? ('UTR'.strtoupper(bin2hex(random_bytes(5))))),
                        'executed_at' => now_utc(),
                        'updated_at' => now_utc(),
                        'id' => $paymentId,
                    ]);
            }
            $responsePayload['payment_id'] = $paymentId;
        } elseif (
            (
                $provider === 'messaging' &&
                in_array($event, ['inbound_invoice', 'invoice_received', 'inbound_document', 'email_inbound_invoice', 'whatsapp_inbound_invoice'], true)
            ) ||
            (
                in_array($provider, ['mail', 'whatsapp'], true) &&
                in_array($event, ['inbound_invoice', 'invoice_received', 'inbound_document'], true)
            )
        ) {
            $responsePayload['capture'] = api_ingest_messaging_invoice_webhook($pdo, $config, $event, $payload);
        }

        $pdo->prepare('UPDATE idempotency_keys
                       SET status_code = :status_code,
                           response_json = :response_json
                       WHERE key_scope = :key_scope
                         AND idempotency_key = :idempotency_key')
            ->execute([
                'status_code' => 200,
                'response_json' => json_encode($responsePayload, JSON_THROW_ON_ERROR),
                'key_scope' => $scope,
                'idempotency_key' => $idempotencyKey,
            ]);

        $pdo->commit();
        json_response($responsePayload, 200);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['error' => 'webhook_failed', 'message' => $e->getMessage()], 500);
    }
}

function api_apply_final_decision_to_entity(PDO $pdo, array $result): void
{
    if (($result['is_final'] ?? false) !== true) {
        return;
    }

    $entityType = (string) ($result['entity_type'] ?? '');
    $entityId = (int) ($result['entity_id'] ?? 0);
    $decision = (string) ($result['decision'] ?? '');
    if ($entityId <= 0) {
        return;
    }

    if ($entityType === 'invoice') {
        $pdo->prepare('UPDATE invoices SET status = :status, updated_at = :updated_at WHERE id = :id')
            ->execute([
                'status' => $decision === 'approved' ? 'approved' : 'rejected',
                'updated_at' => now_utc(),
                'id' => $entityId,
            ]);

        if ($decision === 'approved') {
            $invoiceMeta = $pdo->prepare('SELECT company_id
                                          FROM invoices
                                          WHERE id = :id
                                          LIMIT 1');
            $invoiceMeta->execute(['id' => $entityId]);
            $companyId = (int) ($invoiceMeta->fetchColumn() ?: 0);

            if ($companyId > 0) {
                $pdo->prepare('INSERT INTO integration_jobs
                    (company_id, provider, job_type, status, payload_json, attempts, run_at, created_at, updated_at)
                    VALUES
                    (:company_id, :provider, :job_type, :status, :payload_json, :attempts, :run_at, :created_at, :updated_at)')
                    ->execute([
                        'company_id' => $companyId,
                        'provider' => 'erp_connector',
                        'job_type' => 'erp.sync_voucher',
                        'status' => 'queued',
                        'payload_json' => json_encode([
                            'company_id' => $companyId,
                            'invoice_id' => $entityId,
                        ], JSON_THROW_ON_ERROR),
                        'attempts' => 0,
                        'run_at' => now_utc(),
                        'created_at' => now_utc(),
                        'updated_at' => now_utc(),
                    ]);
            }
        }
    }

    if ($entityType === 'po') {
        $pdo->prepare('UPDATE purchase_orders SET status = :status, updated_at = :updated_at WHERE id = :id')
            ->execute([
                'status' => $decision === 'approved' ? 'approved' : 'rejected',
                'updated_at' => now_utc(),
                'id' => $entityId,
            ]);
    }

    if ($entityType === 'expense') {
        $pdo->prepare('UPDATE expense_claims SET status = :status, updated_at = :updated_at WHERE id = :id')
            ->execute([
                'status' => $decision === 'approved' ? 'approved' : 'rejected',
                'updated_at' => now_utc(),
                'id' => $entityId,
            ]);
    }

    if ($entityType === 'payment') {
        $pdo->prepare('UPDATE payments SET status = :status, updated_at = :updated_at WHERE id = :id')
            ->execute([
                'status' => $decision === 'approved' ? 'approved' : 'failed',
                'updated_at' => now_utc(),
                'id' => $entityId,
            ]);
    }
}
