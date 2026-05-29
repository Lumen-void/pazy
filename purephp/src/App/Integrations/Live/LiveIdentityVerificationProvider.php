<?php

declare(strict_types=1);

namespace Pazy\Integrations\Live;

use Pazy\Integrations\Contracts\IdentityVerificationProvider;
use Pazy\Integrations\Support\HttpClient;

final class LiveIdentityVerificationProvider implements IdentityVerificationProvider
{
    public function verifyTaxIdentity(string $taxId): array
    {
        $normalizedTaxId = trim($taxId);
        if ($normalizedTaxId === '') {
            return [
                'provider' => 'live-identity',
                'tax_id' => '',
                'valid' => false,
                'score' => 0,
                'reason' => 'Missing tax ID.',
            ];
        }

        $endpoint = trim((string) getenv('MCA_VERIFICATION_URL'));
        if ($endpoint === '') {
            throw new \RuntimeException('MCA_VERIFICATION_URL is not configured.');
        }

        $headers = [];
        $token = trim((string) getenv('MCA_API_TOKEN'));
        if ($token === '') {
            $token = trim((string) getenv('IDENTITY_SYNC_TOKEN'));
        }
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $payload = [
            'tax_id' => $normalizedTaxId,
        ];

        $timeout = max(3, (int) (getenv('IDENTITY_SYNC_TIMEOUT') ?: 25));
        $response = HttpClient::request('POST', $endpoint, $headers, $payload, $timeout);

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            throw new \RuntimeException('Identity verification failed: HTTP '.$response['status_code'].' '.$this->truncate((string) $response['body']));
        }

        $json = is_array($response['json']) ? $response['json'] : [];
        $valid = $this->resolveValidity($json);
        $score = $this->resolveScore($json, $valid);

        return [
            'provider' => 'live-identity',
            'tax_id' => $normalizedTaxId,
            'valid' => $valid,
            'score' => $score,
            'entity_name' => (string) ($json['entity_name'] ?? $json['name'] ?? ''),
            'reference' => (string) ($json['reference'] ?? $json['request_id'] ?? ''),
            'raw' => $json,
        ];
    }

    private function resolveValidity(array $json): bool
    {
        if (array_key_exists('valid', $json)) {
            return (bool) $json['valid'];
        }

        if (array_key_exists('is_valid', $json)) {
            return (bool) $json['is_valid'];
        }

        $status = strtolower(trim((string) ($json['status'] ?? $json['result'] ?? '')));
        return in_array($status, ['valid', 'verified', 'active', 'ok', 'success', 'pass'], true);
    }

    private function resolveScore(array $json, bool $valid): int
    {
        if (isset($json['score']) && is_numeric($json['score'])) {
            return max(0, min(100, (int) round((float) $json['score'])));
        }

        return $valid ? 95 : 20;
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
