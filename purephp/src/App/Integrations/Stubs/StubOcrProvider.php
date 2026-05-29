<?php

declare(strict_types=1);

namespace Pazy\Integrations\Stubs;

use Pazy\Integrations\Contracts\OcrProvider;

final class StubOcrProvider implements OcrProvider
{
    public function extractInvoice(string $documentPath): array
    {
        return [
            'provider' => 'stub',
            'invoice_number' => 'OCR-'.strtoupper(substr(md5($documentPath), 0, 8)),
            'confidence' => 0.95,
            'line_items' => [],
        ];
    }
}
