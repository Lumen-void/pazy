<?php

declare(strict_types=1);

namespace Pazy\Integrations\Live;

use Pazy\Integrations\Contracts\BankPaymentProvider;
use Pazy\Integrations\Support\HttpClient;

final class LiveBankPaymentProvider implements BankPaymentProvider
{
    public function transfer(array $instruction): array
    {
        $baseUrl = rtrim((string) getenv('BANK_API_BASE_URL'), '/');
        if ($baseUrl === '') {
            throw new \RuntimeException('BANK_API_BASE_URL is not configured.');
        }

        $path = (string) (getenv('BANK_API_TRANSFER_PATH') ?: '/payments/transfer');
        $endpoint = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : $baseUrl.'/'.ltrim($path, '/');

        $headers = [];
        $token = trim((string) getenv('BANK_API_TOKEN'));
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $apiKey = trim((string) getenv('BANK_API_KEY'));
        if ($apiKey !== '') {
            $headers['X-API-Key'] = $apiKey;
        }

        $requestPayload = [
            'payment_id' => $instruction['payment_id'] ?? null,
            'amount' => (float) ($instruction['amount'] ?? 0),
            'currency' => (string) ($instruction['currency'] ?? 'INR'),
            'mode' => (string) ($instruction['mode'] ?? 'NEFT'),
            'payee_id' => $instruction['payee_id'] ?? null,
            'idempotency_key' => (string) ($instruction['idempotency_key'] ?? ('pmt-'.($instruction['payment_id'] ?? '').'-'.date('YmdHis'))),
        ];

        $timeout = max(3, (int) (getenv('BANK_API_TIMEOUT') ?: 25));
        $response = HttpClient::request('POST', $endpoint, $headers, $requestPayload, $timeout);

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            throw new \RuntimeException('Bank API transfer failed: HTTP '.$response['status_code'].' '.$this->truncate((string) $response['body']));
        }

        $json = is_array($response['json']) ? $response['json'] : [];

        return [
            'provider' => 'live-bank',
            'status' => (string) ($json['status'] ?? 'accepted'),
            'reference' => (string) ($json['reference'] ?? $json['utr'] ?? ('BNK'.strtoupper(bin2hex(random_bytes(4))))),
            'request' => $requestPayload,
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

