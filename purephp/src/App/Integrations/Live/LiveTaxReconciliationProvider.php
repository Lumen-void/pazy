<?php

declare(strict_types=1);

namespace Pazy\Integrations\Live;

use Pazy\Integrations\Contracts\TaxReconciliationProvider;
use Pazy\Integrations\Support\HttpClient;

final class LiveTaxReconciliationProvider implements TaxReconciliationProvider
{
    public function reconcile(array $invoice): array
    {
        $endpoint = trim((string) getenv('TAX_API_BASE_URL'));
        if ($endpoint === '') {
            throw new \RuntimeException('TAX_API_BASE_URL is not configured.');
        }

        $headers = [];
        $token = trim((string) getenv('TAX_API_TOKEN'));
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $payload = [
            'invoice_id' => (int) ($invoice['id'] ?? 0),
            'company_id' => isset($invoice['company_id']) ? (int) $invoice['company_id'] : null,
            'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
            'invoice_date' => (string) ($invoice['invoice_date'] ?? ''),
            'tax_amount' => (float) ($invoice['tax_amount'] ?? 0),
            'total_amount' => (float) ($invoice['total_amount'] ?? 0),
            'currency_code' => (string) ($invoice['currency_code'] ?? 'INR'),
        ];

        $timeout = max(3, (int) (getenv('TAX_API_TIMEOUT') ?: 25));
        $response = HttpClient::request('POST', $endpoint, $headers, $payload, $timeout);

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            throw new \RuntimeException('Tax reconciliation failed: HTTP '.$response['status_code'].' '.$this->truncate((string) $response['body']));
        }

        $json = is_array($response['json']) ? $response['json'] : [];

        $status = strtolower(trim((string) ($json['status'] ?? $json['decision'] ?? $json['recommendation'] ?? '')));
        if ($status !== 'release' && $status !== 'hold') {
            $status = ((string) ($json['match_status'] ?? '')) === 'matched' ? 'release' : 'hold';
        }

        $matchStatus = trim((string) ($json['match_status'] ?? ($status === 'release' ? 'matched' : 'mismatch')));
        if ($matchStatus === '') {
            $matchStatus = $status === 'release' ? 'matched' : 'mismatch';
        }

        return [
            'provider' => 'live-tax',
            'status' => $status,
            'match_status' => $matchStatus,
            'confidence' => isset($json['confidence']) ? (float) $json['confidence'] : null,
            'raw' => $json,
        ];
    }

    private function truncate(string $value, int $max = 260): string
    {
        $trimmed = trim($value);
        if (strlen($trimmed) <= $max) {
            return $trimmed;
        }

        return substr($trimmed, 0, $max - 3).'...';
    }
}
