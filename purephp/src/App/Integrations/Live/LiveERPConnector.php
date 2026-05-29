<?php

declare(strict_types=1);

namespace Pazy\Integrations\Live;

use Pazy\Integrations\Contracts\ERPConnector;
use Pazy\Integrations\Support\HttpClient;

final class LiveERPConnector implements ERPConnector
{
    public function syncVoucher(array $invoice): array
    {
        $endpoint = trim((string) getenv('ERP_SYNC_URL'));
        if ($endpoint === '') {
            $endpoint = trim((string) getenv('ZOHO_BOOKS_SYNC_ENDPOINT'));
        }
        if ($endpoint === '') {
            $endpoint = trim((string) getenv('TALLY_SYNC_URL'));
        }
        if ($endpoint === '') {
            throw new \RuntimeException('ERP sync endpoint not configured. Set ERP_SYNC_URL or provider-specific sync URL.');
        }

        $token = trim((string) getenv('ERP_SYNC_TOKEN'));
        if ($token === '') {
            $token = trim((string) getenv('ZOHO_BOOKS_ACCESS_TOKEN'));
        }

        $headers = [];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $provider = trim((string) getenv('ERP_PROVIDER'));
        if ($provider === '') {
            $provider = str_contains(strtolower($endpoint), 'zoho') ? 'zoho' : (str_contains(strtolower($endpoint), 'tally') ? 'tally' : 'generic');
        }

        $payload = [
            'provider' => $provider,
            'company_id' => $invoice['company_id'] ?? null,
            'invoice_id' => $invoice['id'] ?? null,
            'invoice_number' => $invoice['invoice_number'] ?? null,
            'invoice_date' => $invoice['invoice_date'] ?? null,
            'due_date' => $invoice['due_date'] ?? null,
            'vendor' => [
                'id' => $invoice['vendor_id'] ?? null,
                'name' => $invoice['vendor_name'] ?? null,
                'tax_id' => $invoice['vendor_tax_id'] ?? null,
            ],
            'amounts' => [
                'subtotal' => (float) ($invoice['subtotal_amount'] ?? 0),
                'tax' => (float) ($invoice['tax_amount'] ?? 0),
                'total' => (float) ($invoice['total_amount'] ?? 0),
                'currency' => $invoice['currency_code'] ?? 'INR',
            ],
            'meta' => [
                'po_id' => $invoice['po_id'] ?? null,
                'grn_id' => $invoice['grn_id'] ?? null,
                'source_channel' => $invoice['source_channel'] ?? null,
            ],
        ];

        $timeout = max(3, (int) (getenv('ERP_SYNC_TIMEOUT') ?: 25));
        $response = HttpClient::request('POST', $endpoint, $headers, $payload, $timeout);

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            throw new \RuntimeException('ERP sync failed: HTTP '.$response['status_code'].' '.$this->truncate((string) $response['body']));
        }

        $json = is_array($response['json']) ? $response['json'] : [];

        return [
            'provider' => 'live-erp',
            'erp_provider' => $provider,
            'status' => (string) ($json['status'] ?? 'synced'),
            'voucher_no' => (string) ($json['voucher_no'] ?? $json['voucher_number'] ?? $json['reference'] ?? ('ERP'.strtoupper(bin2hex(random_bytes(4))))),
            'invoice_id' => $invoice['id'] ?? null,
            'http_status' => $response['status_code'],
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

