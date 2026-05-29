<?php

use App\Jobs\RunTaxReconciliationJob;
use App\Models\Invoice;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('finance:reconcile-taxes {--jurisdiction=generic} {--limit=100}', function () {
    $limit = (int) $this->option('limit');
    $jurisdiction = (string) $this->option('jurisdiction');

    $invoices = Invoice::query()->latest('id')->limit($limit)->get(['id']);

    foreach ($invoices as $invoice) {
        RunTaxReconciliationJob::dispatch($invoice->id, $jurisdiction);
    }

    $this->info(sprintf('Queued tax reconciliation for %d invoices.', $invoices->count()));
})->purpose('Queue tax reconciliation jobs for recent invoices.');

Schedule::command('finance:reconcile-taxes')->hourly();
