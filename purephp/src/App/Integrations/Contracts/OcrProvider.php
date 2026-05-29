<?php

declare(strict_types=1);

namespace Pazy\Integrations\Contracts;

interface OcrProvider
{
    public function extractInvoice(string $documentPath): array;
}
