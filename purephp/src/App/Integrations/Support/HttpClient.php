<?php

declare(strict_types=1);

namespace Pazy\Integrations\Support;

final class HttpClient
{
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $jsonBody = null,
        int $timeoutSeconds = 30
    ): array {
        if (! function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required for live integrations.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize HTTP client.');
        }

        $normalizedMethod = strtoupper($method);
        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = trim((string) $key).': '.trim((string) $value);
        }

        $payload = null;
        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_THROW_ON_ERROR);
            $headerLines[] = 'Content-Type: application/json';
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(1, $timeoutSeconds));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(max(1, $timeoutSeconds), 10));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $normalizedMethod);

        if ($headerLines !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP call failed: '.$error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        $parsedHeaders = self::parseHeaders($rawHeaders);
        $json = json_decode($body, true);
        $jsonPayload = is_array($json) ? $json : null;

        return [
            'status_code' => $statusCode,
            'headers' => $parsedHeaders,
            'body' => $body,
            'json' => $jsonPayload,
        ];
    }

    private static function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $lines = preg_split('/\r\n|\n|\r/', trim($rawHeaders)) ?: [];
        foreach ($lines as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $lower = strtolower($name);
            if ($lower === '') {
                continue;
            }
            $headers[$lower] = $value;
        }

        return $headers;
    }
}

