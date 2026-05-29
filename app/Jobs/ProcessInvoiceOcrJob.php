<?php

namespace App\Jobs;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Modules\Integrations\Contracts\OcrProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInvoiceOcrJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $invoiceId)
    {
    }

    public function handle(OcrProvider $provider): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if (! $invoice || ! $invoice->file_path) {
            return;
        }

        $result = $provider->extractInvoiceData($invoice->file_path);

        $invoice->update([
            'extracted_data_json' => $result,
            'status' => InvoiceStatus::Extracted->value,
            'invoice_number' => $invoice->invoice_number ?: ($result['invoice_number'] ?? 'OCR-'.$invoice->id),
            'invoice_date' => $invoice->invoice_date ?: ($result['invoice_date'] ?? now()->toDateString()),
            'total_amount' => $invoice->total_amount ?: ($result['total_amount'] ?? 0),
        ]);

        if (! empty($result['line_items']) && $invoice->items()->count() === 0) {
            foreach ($result['line_items'] as $line) {
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'description' => $line['description'] ?? 'OCR Line Item',
                    'quantity' => (string) ($line['quantity'] ?? 1),
                    'unit_price' => (string) ($line['unit_price'] ?? 0),
                    'tax_rate' => (string) ($line['tax_rate'] ?? 0),
                ]);
            }
        }
    }
}
