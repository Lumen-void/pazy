<?php

declare(strict_types=1);

namespace Pazy\Integrations;

use Pazy\Integrations\Contracts\BankPaymentProvider;
use Pazy\Integrations\Contracts\ERPConnector;
use Pazy\Integrations\Contracts\IdentityVerificationProvider;
use Pazy\Integrations\Contracts\MessagingProvider;
use Pazy\Integrations\Contracts\OcrProvider;
use Pazy\Integrations\Contracts\TaxReconciliationProvider;
use Pazy\Integrations\Live\LiveBankPaymentProvider;
use Pazy\Integrations\Live\LiveERPConnector;
use Pazy\Integrations\Live\LiveIdentityVerificationProvider;
use Pazy\Integrations\Live\LiveMessagingProvider;
use Pazy\Integrations\Live\LiveTaxReconciliationProvider;
use Pazy\Integrations\Stubs\StubBankPaymentProvider;
use Pazy\Integrations\Stubs\StubERPConnector;
use Pazy\Integrations\Stubs\StubIdentityVerificationProvider;
use Pazy\Integrations\Stubs\StubMessagingProvider;
use Pazy\Integrations\Stubs\StubOcrProvider;
use Pazy\Integrations\Stubs\StubTaxReconciliationProvider;

final class ProviderRegistry
{
    public static function ocr(): OcrProvider
    {
        return new StubOcrProvider();
    }

    public static function bank(): BankPaymentProvider
    {
        if (trim((string) getenv('BANK_API_BASE_URL')) !== '') {
            return new LiveBankPaymentProvider();
        }

        return new StubBankPaymentProvider();
    }

    public static function erp(): ERPConnector
    {
        if (
            trim((string) getenv('ERP_SYNC_URL')) !== '' ||
            trim((string) getenv('ZOHO_BOOKS_SYNC_ENDPOINT')) !== '' ||
            trim((string) getenv('TALLY_SYNC_URL')) !== ''
        ) {
            return new LiveERPConnector();
        }

        return new StubERPConnector();
    }

    public static function tax(): TaxReconciliationProvider
    {
        if (trim((string) getenv('TAX_API_BASE_URL')) !== '') {
            return new LiveTaxReconciliationProvider();
        }

        return new StubTaxReconciliationProvider();
    }

    public static function messaging(): MessagingProvider
    {
        if (
            trim((string) getenv('SLACK_WEBHOOK_URL')) !== '' ||
            trim((string) getenv('WHATSAPP_ACCESS_TOKEN')) !== '' ||
            trim((string) getenv('SENDGRID_API_KEY')) !== '' ||
            trim((string) getenv('MESSAGING_OUTBOUND_URL')) !== ''
        ) {
            return new LiveMessagingProvider();
        }

        return new StubMessagingProvider();
    }

    public static function identity(): IdentityVerificationProvider
    {
        if (trim((string) getenv('MCA_VERIFICATION_URL')) !== '') {
            return new LiveIdentityVerificationProvider();
        }

        return new StubIdentityVerificationProvider();
    }
}
