# Pazy-Like Finance Platform (Laravel 12 + Vue 3)

This project implements an MVP architecture for a business-spend automation platform with:

- Multi-company org model
- AP + procurement + reimbursements + payments + tax reconciliation modules
- API-first integrations with swappable provider contracts and stub adapters
- Sanctum auth, company scoping, audit trail, idempotent webhooks
- Queue jobs for OCR, payment execution, notifications, and tax reconciliation

## Stack

- Backend: Laravel 12 (PHP 8.2+)
- Frontend: Blade + Vue 3 + Vite
- Database: PostgreSQL 16 target (SQLite supported for tests/local quick boot)
- Queue/Cache/Session: Redis (sync/array in tests)
- Auth: Sanctum
- Queue UI: Horizon

## Quick Start

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

Optional workers:

```bash
php artisan queue:work
php artisan horizon
```

## API Namespace

All APIs are under `/api/v1`.

Core endpoints include:

- `/auth`
- `/organizations`, `/companies`, `/users`
- `/vendors`
- `/purchase-orders`, `/grns`, `/invoices`
- `/expenses`
- `/approvals`
- `/payments`
- `/tax/reconciliations`
- `/notifications`
- `/reports`
- `/webhooks/{provider}/{event}`

## Architecture Highlights

- **Module engines**
  - `ApprovalEngine`: multi-level policy-driven routing
  - `MatchingEngine`: PO/GRN/invoice validation
  - `ExpensePolicyEngine`: claim policy evaluation
- **Integration contracts**
  - `OcrProvider`, `BankPaymentProvider`, `ERPConnector`, `TaxReconciliationProvider`, `MessagingProvider`, `IdentityVerificationProvider`
- **Stub adapters**
  - Implemented in `app/Modules/Integrations/Stubs` for API-first development
- **Audit + idempotency**
  - Immutable `audit_events`
  - `idempotency_keys` and webhook dedup via `integration_webhook_events`

## Scheduler

A scheduled reconciliation command is registered:

- `finance:reconcile-taxes`

Run manually:

```bash
php artisan finance:reconcile-taxes --jurisdiction=generic --limit=100
```

## Testing

```bash
php artisan test
```

Current suite covers:

- Approval policy enqueue behavior
- Matching engine outcomes
- Expense policy limits
- Company-scoped auth flow
- Invoice → approval → payment flow
- Signed webhook idempotency
