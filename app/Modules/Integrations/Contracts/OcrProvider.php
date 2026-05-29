<?php

namespace App\Modules\Integrations\Contracts;

interface OcrProvider
{
    public function extractInvoiceData(string $documentPath): array;
}
