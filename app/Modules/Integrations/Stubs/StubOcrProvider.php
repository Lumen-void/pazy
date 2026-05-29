<?php

namespace App\Modules\Integrations\Stubs;

use App\Modules\Integrations\Contracts\OcrProvider;

class StubOcrProvider implements OcrProvider
{
    public function extractInvoiceData(string $documentPath): array
    {
        return [
            'vendor_name' => 'Stub Vendor',
            'invoice_number' => 'INV-STUB-'.substr(sha1($documentPath), 0, 8),
            'invoice_date' => now()->toDateString(),
            'currency' => 'USD',
            'line_items' => [
                [
                    'description' => 'Stub Line Item',
                    'quantity' => 1,
                    'unit_price' => '100.00',
                    'tax_rate' => '0.00',
                ],
            ],
            'total_amount' => '100.00',
            'confidence' => 0.95,
        ];
    }
}
