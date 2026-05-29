<?php

namespace App\Jobs;

use App\Enums\TaxDecisionStatus;
use App\Models\Invoice;
use App\Models\TaxReconciliation;
use App\Modules\Integrations\Contracts\TaxReconciliationProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunTaxReconciliationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $invoiceId, public string $jurisdiction = 'generic')
    {
    }

    public function handle(TaxReconciliationProvider $provider): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if (! $invoice) {
            return;
        }

        $response = $provider->reconcile([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'tax_amount' => (float) $invoice->tax_amount,
            'total_amount' => (float) $invoice->total_amount,
        ], $this->jurisdiction);

        TaxReconciliation::query()->create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'jurisdiction' => $this->jurisdiction,
            'source_reference' => $response['details']['source'] ?? 'stub-tax',
            'match_status' => $response['match_status'] ?? 'mismatched',
            'recommendation' => $response['recommendation'] ?? TaxDecisionStatus::Hold->value,
            'details_json' => $response['details'] ?? [],
            'run_at' => now(),
        ]);
    }
}
