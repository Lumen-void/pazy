<?php

namespace App\Providers;

use App\Modules\Integrations\Contracts\BankPaymentProvider;
use App\Modules\Integrations\Contracts\ERPConnector;
use App\Modules\Integrations\Contracts\IdentityVerificationProvider;
use App\Modules\Integrations\Contracts\MessagingProvider;
use App\Modules\Integrations\Contracts\OcrProvider;
use App\Modules\Integrations\Contracts\TaxReconciliationProvider;
use App\Modules\Integrations\Stubs\StubBankPaymentProvider;
use App\Modules\Integrations\Stubs\StubERPConnector;
use App\Modules\Integrations\Stubs\StubIdentityVerificationProvider;
use App\Modules\Integrations\Stubs\StubMessagingProvider;
use App\Modules\Integrations\Stubs\StubOcrProvider;
use App\Modules\Integrations\Stubs\StubTaxReconciliationProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OcrProvider::class, StubOcrProvider::class);
        $this->app->bind(BankPaymentProvider::class, StubBankPaymentProvider::class);
        $this->app->bind(ERPConnector::class, StubERPConnector::class);
        $this->app->bind(TaxReconciliationProvider::class, StubTaxReconciliationProvider::class);
        $this->app->bind(MessagingProvider::class, StubMessagingProvider::class);
        $this->app->bind(IdentityVerificationProvider::class, StubIdentityVerificationProvider::class);
    }

    public function boot(): void
    {
        //
    }
}
