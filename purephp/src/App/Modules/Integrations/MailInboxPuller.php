<?php

declare(strict_types=1);

namespace Pazy\Modules\Integrations;

final class MailInboxPuller
{
    public static function probe(): array
    {
        if (! function_exists('imap_open')) {
            throw new \RuntimeException('PHP IMAP extension is required for mail inbox pull.');
        }

        $host = trim((string) getenv('MAIL_INBOUND_IMAP_HOST'));
        $port = (int) (getenv('MAIL_INBOUND_IMAP_PORT') ?: 993);
        $encryption = strtolower(trim((string) (getenv('MAIL_INBOUND_IMAP_ENCRYPTION') ?: 'ssl')));
        $folder = trim((string) (getenv('MAIL_INBOUND_IMAP_FOLDER') ?: 'INBOX'));
        $username = (string) getenv('MAIL_INBOUND_IMAP_USERNAME');
        $password = (string) getenv('MAIL_INBOUND_IMAP_PASSWORD');

        if ($host === '' || $username === '' || $password === '') {
            throw new \RuntimeException('MAIL_INBOUND_IMAP_HOST, MAIL_INBOUND_IMAP_USERNAME, and MAIL_INBOUND_IMAP_PASSWORD are required.');
        }

        $flags = '/imap/'.$encryption.'/novalidate-cert';
        $mailbox = sprintf('{%s:%d%s}%s', $host, max(1, $port), $flags, $folder);
        $imap = @imap_open($mailbox, $username, $password);
        if ($imap === false) {
            $errors = imap_errors();
            throw new \RuntimeException('Unable to connect inbox: '.implode('; ', is_array($errors) ? $errors : []));
        }

        $mailboxInfo = imap_check($imap);
        $unseen = imap_search($imap, 'UNSEEN') ?: [];
        imap_close($imap);

        return [
            'provider' => 'live-mail-inbound',
            'status' => 'connected',
            'mailbox' => $folder,
            'total_messages' => (int) ($mailboxInfo->Nmsgs ?? 0),
            'unseen_messages' => count($unseen),
        ];
    }

    public static function pull(\PDO $pdo, array $config, int $companyId, array $payload): array
    {
        if (! function_exists('imap_open')) {
            throw new \RuntimeException('PHP IMAP extension is required for mail inbox pull.');
        }

        $host = trim((string) getenv('MAIL_INBOUND_IMAP_HOST'));
        $port = (int) (getenv('MAIL_INBOUND_IMAP_PORT') ?: 993);
        $encryption = strtolower(trim((string) (getenv('MAIL_INBOUND_IMAP_ENCRYPTION') ?: 'ssl')));
        $folder = trim((string) (getenv('MAIL_INBOUND_IMAP_FOLDER') ?: 'INBOX'));
        $username = (string) getenv('MAIL_INBOUND_IMAP_USERNAME');
        $password = (string) getenv('MAIL_INBOUND_IMAP_PASSWORD');

        if ($host === '' || $username === '' || $password === '') {
            throw new \RuntimeException('MAIL_INBOUND_IMAP_HOST, MAIL_INBOUND_IMAP_USERNAME, and MAIL_INBOUND_IMAP_PASSWORD are required.');
        }

        $flags = '/imap/'.$encryption.'/novalidate-cert';
        $mailbox = sprintf('{%s:%d%s}%s', $host, max(1, $port), $flags, $folder);

        $imap = @imap_open($mailbox, $username, $password);
        if ($imap === false) {
            $errors = imap_errors();
            throw new \RuntimeException('Unable to connect inbox: '.implode('; ', is_array($errors) ? $errors : []));
        }

        $limit = max(1, min(50, (int) ($payload['limit'] ?? 10)));
        $vendorId = max(0, (int) ($payload['vendor_id'] ?? 0));
        $defaultAmount = max(1.0, (float) ($payload['default_total_amount'] ?? 100.0));
        $markAsSeen = ((int) ($payload['mark_as_seen'] ?? 1)) === 1;
        $moveTo = trim((string) ($payload['move_to_folder'] ?? getenv('MAIL_INBOUND_IMAP_MOVE_TO')));

        $messageNumbers = imap_search($imap, 'UNSEEN') ?: [];
        rsort($messageNumbers, SORT_NUMERIC);
        $messageNumbers = array_slice($messageNumbers, 0, $limit);

        $result = [
            'fetched' => count($messageNumbers),
            'processed' => 0,
            'captured' => [],
            'skipped' => [],
            'errors' => [],
        ];

        foreach ($messageNumbers as $messageNo) {
            try {
                $overview = imap_fetch_overview($imap, (string) $messageNo, 0);
                $meta = is_array($overview) && isset($overview[0]) ? $overview[0] : null;
                $subject = trim((string) ($meta->subject ?? ''));
                $fromRaw = (string) ($meta->from ?? '');
                $sender = self::extractEmailAddress($fromRaw);
                $senderName = self::senderToVendorName($sender);

                $attachment = self::extractFirstAttachment($imap, (int) $messageNo);
                if ($attachment === null) {
                    $result['skipped'][] = [
                        'message_no' => $messageNo,
                        'reason' => 'no_supported_attachment',
                    ];
                    continue;
                }

                $plainBody = self::extractPlainText($imap, (int) $messageNo) ?? '';
                $totalAmount = self::extractAmount($subject.' '.$plainBody, $defaultAmount);
                $invoiceNumber = self::deriveInvoiceNumber($subject, (int) $messageNo);

                $capturePayload = [
                    'company_id' => $companyId,
                    'source_channel' => 'email',
                    'source_ref' => $sender !== '' ? $sender : ('imap-msg-'.$messageNo),
                    'vendor_id' => $vendorId > 0 ? $vendorId : null,
                    'vendor_name' => $vendorId > 0 ? null : $senderName,
                    'vendor_email' => $sender !== '' ? $sender : null,
                    'invoice_number' => $invoiceNumber,
                    'invoice_date' => today_utc(),
                    'due_date' => today_utc(),
                    'total_amount' => $totalAmount,
                    'document' => [
                        'filename' => $attachment['filename'],
                        'mime_type' => $attachment['mime_type'],
                        'content_base64' => base64_encode($attachment['raw']),
                    ],
                    'message_id' => 'imap-'.$messageNo.'-'.substr(sha1($subject.$sender), 0, 12),
                ];

                /** @var array $capture */
                $capture = \api_ingest_messaging_invoice_webhook($pdo, $config, 'email_inbound_invoice', $capturePayload);

                if ($markAsSeen) {
                    imap_setflag_full($imap, (string) $messageNo, '\\Seen');
                }
                if ($moveTo !== '') {
                    @imap_mail_move($imap, (string) $messageNo, $moveTo);
                }

                $result['processed']++;
                $result['captured'][] = [
                    'message_no' => $messageNo,
                    'invoice_id' => $capture['invoice_id'] ?? null,
                    'invoice_number' => $capture['invoice_number'] ?? $invoiceNumber,
                    'sender' => $sender,
                ];
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'message_no' => $messageNo,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if ($moveTo !== '') {
            @imap_expunge($imap);
        }
        imap_close($imap);

        return $result;
    }

    private static function extractEmailAddress(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $matches) === 1) {
            return strtolower(trim((string) $matches[1]));
        }

        if (filter_var(trim($from), FILTER_VALIDATE_EMAIL)) {
            return strtolower(trim($from));
        }

        return '';
    }

    private static function senderToVendorName(string $sender): string
    {
        if ($sender === '') {
            return 'Inbound Email Vendor';
        }

        $local = explode('@', $sender)[0] ?? 'inbound-vendor';
        $local = str_replace(['.', '_', '-'], ' ', strtolower($local));
        $parts = array_filter(array_map(static fn (string $piece): string => ucfirst(trim($piece)), explode(' ', $local)));
        $name = trim(implode(' ', $parts));

        return $name !== '' ? $name : 'Inbound Email Vendor';
    }

    private static function deriveInvoiceNumber(string $subject, int $messageNo): string
    {
        if (preg_match('/\b([A-Z]{2,5}[-\/]?\d{2,})\b/i', $subject, $match) === 1) {
            return substr(strtoupper((string) $match[1]), 0, 120);
        }

        return 'MAIL-'.gmdate('Ymd').'-'.$messageNo;
    }

    private static function extractAmount(string $text, float $defaultAmount): float
    {
        $normalized = str_replace(',', '', $text);
        if (preg_match('/(?:rs|inr|amount|total|invoice)\s*[:\-]?\s*([0-9]+(?:\.[0-9]{1,2})?)/i', $normalized, $match) === 1) {
            return max(0.01, round((float) $match[1], 2));
        }

        if (preg_match('/\b([0-9]{2,}(?:\.[0-9]{1,2})?)\b/', $normalized, $match) === 1) {
            return max(0.01, round((float) $match[1], 2));
        }

        return max(0.01, round($defaultAmount, 2));
    }

    private static function extractPlainText($imap, int $messageNo): ?string
    {
        $structure = imap_fetchstructure($imap, $messageNo);
        if (! $structure) {
            return null;
        }

        if (! isset($structure->parts) || ! is_array($structure->parts)) {
            $raw = imap_body($imap, (string) $messageNo) ?: '';
            return self::decodePartBody((string) $raw, (int) ($structure->encoding ?? 0));
        }

        foreach ($structure->parts as $index => $part) {
            $subtype = strtoupper((string) ($part->subtype ?? ''));
            $type = (int) ($part->type ?? 0);
            if ($type === 0 && in_array($subtype, ['PLAIN', 'TEXT'], true)) {
                $partNo = (string) ($index + 1);
                $raw = imap_fetchbody($imap, (string) $messageNo, $partNo) ?: '';
                return self::decodePartBody((string) $raw, (int) ($part->encoding ?? 0));
            }
        }

        return null;
    }

    private static function extractFirstAttachment($imap, int $messageNo): ?array
    {
        $structure = imap_fetchstructure($imap, $messageNo);
        if (! $structure) {
            return null;
        }

        $parts = self::flattenParts($structure);
        foreach ($parts as $item) {
            $partNo = $item['part_no'];
            $part = $item['part'];
            $filename = self::extractFilename($part);

            $subtype = strtolower((string) ($part->subtype ?? ''));
            $mimeType = self::resolveMimeType((int) ($part->type ?? 0), $subtype);
            $isSupported = in_array($mimeType, [
                'application/pdf',
                'image/png',
                'image/jpeg',
                'image/webp',
            ], true);

            if (! $isSupported && $filename === '') {
                continue;
            }

            $raw = imap_fetchbody($imap, (string) $messageNo, $partNo) ?: '';
            $decoded = self::decodePartBody((string) $raw, (int) ($part->encoding ?? 0));
            if ($decoded === '') {
                continue;
            }

            if ($filename === '') {
                $filename = 'mail-attachment-'.$messageNo.'.'.self::mimeToExtension($mimeType);
            }

            return [
                'filename' => $filename,
                'mime_type' => $mimeType,
                'raw' => $decoded,
            ];
        }

        return null;
    }

    private static function flattenParts(object $structure, string $prefix = ''): array
    {
        $flat = [];

        if (! isset($structure->parts) || ! is_array($structure->parts)) {
            $flat[] = [
                'part_no' => $prefix !== '' ? $prefix : '1',
                'part' => $structure,
            ];
            return $flat;
        }

        foreach ($structure->parts as $index => $part) {
            $partNo = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);
            if (isset($part->parts) && is_array($part->parts)) {
                $flat = array_merge($flat, self::flattenParts($part, $partNo));
                continue;
            }

            $flat[] = [
                'part_no' => $partNo,
                'part' => $part,
            ];
        }

        return $flat;
    }

    private static function extractFilename(object $part): string
    {
        $candidates = [];

        if (isset($part->dparameters) && is_array($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                $attribute = strtolower((string) ($param->attribute ?? ''));
                if (in_array($attribute, ['filename', 'name'], true)) {
                    $candidates[] = (string) ($param->value ?? '');
                }
            }
        }

        if (isset($part->parameters) && is_array($part->parameters)) {
            foreach ($part->parameters as $param) {
                $attribute = strtolower((string) ($param->attribute ?? ''));
                if (in_array($attribute, ['filename', 'name'], true)) {
                    $candidates[] = (string) ($param->value ?? '');
                }
            }
        }

        foreach ($candidates as $candidate) {
            $clean = trim((string) imap_utf8($candidate));
            if ($clean !== '') {
                return $clean;
            }
        }

        return '';
    }

    private static function resolveMimeType(int $type, string $subtype): string
    {
        $map = [
            0 => 'text/',
            1 => 'multipart/',
            2 => 'message/',
            3 => 'application/',
            4 => 'audio/',
            5 => 'image/',
            6 => 'video/',
            7 => 'other/',
        ];

        $prefix = $map[$type] ?? 'application/';
        $value = $prefix.strtolower($subtype);

        return match ($value) {
            'image/jpg' => 'image/jpeg',
            default => $value,
        };
    }

    private static function mimeToExtension(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }

    private static function decodePartBody(string $raw, int $encoding): string
    {
        return match ($encoding) {
            3 => (string) imap_base64($raw),
            4 => (string) imap_qprint($raw),
            default => $raw,
        };
    }
}
