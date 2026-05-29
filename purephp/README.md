# Pazy-Like Finance Platform (Pure PHP + MySQL)

Framework-free implementation using only:
- PHP
- CSS
- JavaScript
- MySQL

## Stack and Architecture
- Front controller: `/Applications/XAMPP/xamppfiles/htdocs/pazy/purephp/public/index.php`
- Modular service layer in `/Applications/XAMPP/xamppfiles/htdocs/pazy/purephp/src/App`
- API namespace: `/api/v1`
- Signed + idempotent webhooks: `/api/v1/webhooks/{provider}/{event}`
- Multi-company IAM/RBAC, approvals engine, procurement matching, reimbursements policy checks, payments, tax reconciliations, integration adapters, immutable audit events.

## Run on XAMPP (macOS)

1. Create local env file for pure PHP app:
```bash
cp /Applications/XAMPP/xamppfiles/htdocs/pazy/purephp/.env.example /Applications/XAMPP/xamppfiles/htdocs/pazy/purephp/.env
```

2. Run local setup (schema + seed + preflight):
```bash
cd /Applications/XAMPP/xamppfiles/htdocs/pazy/purephp
./scripts/setup_local.sh
```

Alternative manual DB import:
```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root < /Applications/XAMPP/xamppfiles/htdocs/pazy/purephp/database/schema.sql
/Applications/XAMPP/xamppfiles/bin/mysql -u root < /Applications/XAMPP/xamppfiles/htdocs/pazy/purephp/database/seed.sql
```

3. Ensure storage is writable by Apache (required for uploads):
```bash
mkdir -p /Applications/XAMPP/xamppfiles/htdocs/pazy/purephp/storage/object
chmod -R 777 /Applications/XAMPP/xamppfiles/htdocs/pazy/purephp/storage
```

Note:
- Optional feature tables for Integrations Marketplace, Cards, Credit Line, and UPI are auto-created on first authenticated request.

5. Open app:
- `http://localhost/pazy/purephp/public/index.php`
- Public website pages:
  - `http://localhost/pazy/purephp/public/` (Home)
  - `http://localhost/pazy/purephp/public/index.php?page=about`
  - `http://localhost/pazy/purephp/public/index.php?page=features`
  - `http://localhost/pazy/purephp/public/index.php?page=pricing`
  - `http://localhost/pazy/purephp/public/index.php?page=contact`

App module pages (after login):
- `http://localhost/pazy/purephp/public/index.php?page=explore`
- `http://localhost/pazy/purephp/public/index.php?page=connected-banking`
- `http://localhost/pazy/purephp/public/index.php?page=cards`
- `http://localhost/pazy/purephp/public/index.php?page=credit-line`
- `http://localhost/pazy/purephp/public/index.php?page=upi`
- `http://localhost/pazy/purephp/public/index.php?page=bulk-payout`
- `http://localhost/pazy/purephp/public/index.php?page=integrations`

## Ops Console

Use the CLI console for DB/bootstrap, worker operations, backups, and smoke tests:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/pazy/purephp
php bin/console.php help
```

Common commands:

```bash
# Health and readiness
php bin/console.php infra:doctor
php bin/console.php integrations:preflight --company=1 --probe=0

# Database
php bin/console.php db:init --seed=1
php bin/console.php db:seed

# Worker
php bin/console.php worker:run --limit=100 --company=all --actor=1
./scripts/worker_loop.sh 15 100 all 1

# Backups
php bin/console.php backup:create
php bin/console.php backup:restore --file=/absolute/path/to/backup.sql

# End-to-end smoke validation
php bin/console.php qa:smoke --company=1
```

Cron examples are provided in:
- `/Applications/XAMPP/xamppfiles/htdocs/pazy/purephp/scripts/cron.example`

## Default Users (password same for all)
- `admin@pazy.local`
- `finance@pazy.local`
- `ops@pazy.local`
- `employee@pazy.local`

Password:
- `password1234`

## Key Modules Implemented
- IAM/RBAC with multi-company context switch
- Vendor onboarding and compliance scoring
- Procurement (`PO -> GRN`)
- AP invoice capture + OCR stub + 3-way matching exception queue
- Multi-channel capture telemetry (`web/email/slack/whatsapp/mobile`) for invoices/expenses
- Dedicated operations inbox with bulk actions
- Dedicated matching workspace for PO/GRN relinking and rematch
- Configurable approvals with maker-checker constraints
- Approval policy builder with SLA + escalation reminders
- Reimbursements with policy engine and violation tracking
- Mileage-aware reimbursements (distance x rate) with policy checks
- Payments with idempotency key, scheduling, batch command center, and dispatch queues
- Connected banking workspace with account and payment rail visibility
- Explore module hub with product-card navigation
- Settings-style integration marketplace (Mail, Slack, Zoho, Odoo, Tally, WhatsApp, Workspace, AD, Oracle, NetSuite, etc.)
- Corporate cards module with issuance, spend limits, and MCC controls
- Credit line management module with utilization tracking
- UPI wallet controls with limits and activity snapshot
- Tax reconciliation release/hold decisions with explicit hold reasons and vendor compliance trend
- Notifications + integration jobs
- Webhook verification and replay protection
- Reporting and audit timeline
- File uploads to object storage-like local path (`purephp/storage/object`) with SQL metadata in `documents`
- Integration worker with retry + dead-letter handling
- Live-capable adapters for bank payout API, ERP voucher sync API, Slack webhook, WhatsApp Cloud, SendGrid email, and IMAP inbound capture (in-app vault first, `.env` fallback)
- API/webhook/form rate limiting with persistent limiter store
- Security headers + optional HTTPS enforcement + centralized error log (`purephp/logs/app-error.log`)

## API Quick Check

Health:
```bash
curl -s http://localhost/pazy/purephp/public/api/v1/health
```

Token login:
```bash
curl -s -X POST http://localhost/pazy/purephp/public/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@pazy.local","password":"password1234","token_name":"local"}'
```

Use returned token:
```bash
curl -s http://localhost/pazy/purephp/public/api/v1/vendors \
  -H "Authorization: Bearer <TOKEN>"
```

Operations inbox payload:
```bash
curl -s http://localhost/pazy/purephp/public/api/v1/inbox \
  -H "Authorization: Bearer <TOKEN>"
```

Create payment batch:
```bash
curl -s -X POST http://localhost/pazy/purephp/public/api/v1/payment-batches \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"invoice_ids":[1],"payment_mode":"NEFT","currency_code":"INR","scheduled_for":"2026-03-25 18:30:00"}'
```

Upload document:
```bash
curl -s -X POST http://localhost/pazy/purephp/public/api/v1/documents/upload \
  -H "Authorization: Bearer <TOKEN>" \
  -F "entity_type=invoice" \
  -F "entity_id=1" \
  -F "file=@/absolute/path/to/invoice.pdf"
```

Run integration worker:
```bash
curl -s -X POST http://localhost/pazy/purephp/public/api/v1/workers/integration-jobs/run \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"limit":25}'
```

## Live Integration Setup

Integration credentials can be managed directly in-app (recommended) and are stored per company in `company_integrations.connection_meta_json` with encrypted values.

Runtime source order:
1. In-app integration vault (per company)
2. `.env` fallback when a key is not configured in-app

Supported live connectors in this build:
- Bank payouts: `BANK_API_*`
- ERP voucher sync: `ERP_SYNC_URL` (or `ZOHO_BOOKS_SYNC_ENDPOINT` / `TALLY_SYNC_URL`)
- Identity sync: `GOOGLE_WORKSPACE_SYNC_URL`, `MICROSOFT_AD_SYNC_URL`, `IDENTITY_SYNC_TOKEN`
- Tax and compliance probes: `TAX_API_BASE_URL`, `TAX_API_TOKEN`, `MCA_VERIFICATION_URL`, `MCA_API_TOKEN`
- Slack outbound: `SLACK_WEBHOOK_URL`
- WhatsApp outbound: `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`
- Email outbound: `SENDGRID_API_KEY`, `SENDGRID_FROM_EMAIL` (or PHP `mail()` fallback)
- Mail inbound auto-scan: `MAIL_INBOUND_IMAP_*` + PHP IMAP extension

Validation flow:
1. Open `http://localhost/pazy/purephp/public/index.php?page=integrations`
2. Click `Manage` on a provider card to open the `Manage Provider` setup panel.
3. In that panel:
   - Save required keys in the in-app credential vault (per provider).
   - For OAuth-capable providers (Slack, Zoho, Google Workspace, Microsoft AD), copy callback URL and whitelist it in provider app settings.
4. Click `Connect` and approve access on the provider side.
5. Return to Pazy and verify provider status badges + run `Test Connection` from `Advanced`.
6. Check integration operations tables (`jobs`, `webhooks`, `capture events`) for successful runs.

OAuth callback URL to whitelist in provider app settings:
- `http://localhost/pazy/purephp/public/index.php?page=integrations&oauth=callback`

## Go-Live Ownership Split

Implemented in code:
- Compact integration marketplace cards with dedicated per-provider setup panel (`Manage`).
- OAuth flow wiring for Slack, Zoho, Google Workspace, Microsoft AD with in-app encrypted config storage.
- Live adapters enabled for:
  - Bank payouts (`BANK_API_BASE_URL`)
  - ERP voucher sync (`ERP_SYNC_URL` / `ZOHO_BOOKS_SYNC_ENDPOINT` / `TALLY_SYNC_URL`)
  - Messaging (`SLACK_WEBHOOK_URL`, `WHATSAPP_*`, `SENDGRID_*`)
  - Tax reconciliation (`TAX_API_BASE_URL`)
  - MCA/identity verification (`MCA_VERIFICATION_URL`)
- Runtime preflight command to identify missing live dependencies.

You must complete:
1. Create OAuth apps in Slack/Zoho/Google/Microsoft and add the callback URL above.
2. Enter real provider credentials/tokens in `Manage Provider` for each integration.
3. Grant external account permissions (workspace/books/directory scopes) during OAuth consent.
4. Configure and expose webhook endpoints publicly (for provider callbacks/events where required).
5. Run `php bin/console.php integrations:preflight --company=1 --probe=1` until all checks are `READY`.
6. Run worker loop in deployment (`php bin/console.php worker:run --limit=100`) via cron/supervisor.

## Inbound WhatsApp/Email Invoice Capture (Auto-Scan)

Send to:
- `POST /api/v1/webhooks/messaging/inbound_invoice`

Required:
- `Idempotency-Key` header
- `X-Signature` header (`sha256=<hmac_hex>`)
- JSON payload with at least: `company_id`, `total_amount`, and either `vendor_id` or vendor details.

Example:
```bash
RAW='{
  "company_id": 1,
  "source_channel": "whatsapp",
  "source_ref": "+91-900000001",
  "vendor_name": "Acme Supplies Pvt Ltd",
  "vendor_tax_id": "29ABCDE1234F1Z5",
  "invoice_number": "WA-INV-1001",
  "invoice_date": "2026-03-25",
  "due_date": "2026-04-05",
  "total_amount": 177000,
  "tax_amount": 27000,
  "po_id": 1,
  "grn_id": 1,
  "document": {
    "filename": "invoice.pdf",
    "mime_type": "application/pdf",
    "content_base64": "JVBERi0xLjQKJ..."
  }
}'

SIG=$(printf '%s' "$RAW" | openssl dgst -sha256 -hmac 'messaging-local-secret' -hex | sed 's/^.* //')

curl -s -X POST http://localhost/pazy/purephp/public/api/v1/webhooks/messaging/inbound_invoice \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: msg-inv-1001" \
  -H "X-Signature: sha256=$SIG" \
  -d "$RAW"
```

Behavior:
- Captures invoice from WhatsApp/email payload.
- Stores attachment in object storage when `document.content_base64` is provided.
- Runs OCR stub, 3-way matching, and approval routing automatically.

## Security and Reliability Defaults

- Toggle HTTPS redirect with `APP_FORCE_HTTPS=1` in production.
- Security headers are configurable through:
  - `SECURITY_HSTS_MAX_AGE`
  - `SECURITY_X_FRAME_OPTIONS`
  - `SECURITY_X_CONTENT_TYPE_OPTIONS`
  - `SECURITY_REFERRER_POLICY`
  - `SECURITY_PERMISSIONS_POLICY`
  - `SECURITY_CONTENT_SECURITY_POLICY`
- Rate limits:
  - `RATE_LIMIT_AUTH_PER_MINUTE`
  - `RATE_LIMIT_API_PER_MINUTE`
  - `RATE_LIMIT_WEBHOOK_PER_MINUTE`
  - `RATE_LIMIT_WEB_POST_PER_MINUTE`
- Worker reliability:
  - `WORKER_MAX_ATTEMPTS`
  - `WORKER_RETRY_BASE_SECONDS`

## What Is Fully Live vs Stub

- Fully live-capable with credentials:
  - Bank payout API, ERP sync API, Slack webhook, WhatsApp Cloud, SendGrid email, IMAP capture.
- Probed/live-ready via endpoint checks:
  - Tax (`TAX_API_BASE_URL`), MCA (`MCA_VERIFICATION_URL`), Google Workspace sync, Microsoft AD sync.
- Still stubbed in this build:
  - OCR extraction model internals and jurisdiction-specific tax decision logic are pluggable stubs by default.
