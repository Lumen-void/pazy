<?php

namespace App\Console\Commands;

use App\Jobs\RunTaxReconciliationJob;
use App\Models\Invoice;
use Illuminate\Console\Command;

class ReconcileTaxesCommand extends Command
{
    protected $signature = 'finance:reconcile-taxes {--jurisdiction=generic} {--limit=100}';

    protected $description = 'Queue tax reconciliation for recent invoices';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $jurisdiction = (string) $this->option('jurisdiction');

        $invoices = Invoice::query()
            ->latest('id')
            ->limit($limit)
            ->get(['id']);

        foreach ($invoices as $invoice) {
            RunTaxReconciliationJob::dispatch($invoice->id, $jurisdiction);
        }

        $this->info(sprintf('Queued tax reconciliation for %d invoices.', $invoices->count()));

        return self::SUCCESS;
    }
}
