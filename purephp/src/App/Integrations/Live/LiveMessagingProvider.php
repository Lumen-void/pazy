<?php

declare(strict_types=1);

namespace Pazy\Integrations\Live;

use Pazy\Integrations\Contracts\MessagingProvider;
use Pazy\Integrations\Support\HttpClient;

final class LiveMessagingProvider implements MessagingProvider
{
    public function send(string $channel, string $recipient, string $subject, string $message): array
    {
        $normalized = strtolower(trim($channel));

        return match ($normalized) {
            'email' => $this->sendEmail($recipient, $subject, $message),
            'slack' => $this->sendSlack($recipient, $subject, $message),
            'whatsapp' => $this->sendWhatsApp($recipient, $subject, $message),
            default => $this->sendGenericWebhook($normalized, $recipient, $subject, $message),
        };
    }

    private function sendEmail(string $recipient, string $subject, string $message): array
    {
        $sendGridKey = trim((string) getenv('SENDGRID_API_KEY'));
        $sendGridFrom = trim((string) getenv('SENDGRID_FROM_EMAIL'));
        if ($sendGridKey !== '' && $sendGridFrom !== '') {
            $payload = [
                'personalizations' => [[
                    'to' => [['email' => $recipient]],
                    'subject' => $subject,
                ]],
                'from' => ['email' => $sendGridFrom],
                'content' => [[
                    'type' => 'text/plain',
                    'value' => $message,
                ]],
            ];

            $response = HttpClient::request(
                'POST',
                'https://api.sendgrid.com/v3/mail/send',
                ['Authorization' => 'Bearer '.$sendGridKey],
                $payload,
                max(3, (int) (getenv('MESSAGING_TIMEOUT') ?: 25))
            );

            if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
                throw new \RuntimeException('SendGrid send failed: HTTP '.$response['status_code']);
            }

            return [
                'provider' => 'live-sendgrid',
                'channel' => 'email',
                'recipient' => $recipient,
                'status' => 'sent',
                'message_id' => (string) ($response['headers']['x-message-id'] ?? ('MSG'.strtoupper(bin2hex(random_bytes(4))))),
                'subject' => $subject,
            ];
        }

        $headers = [];
        $from = trim((string) getenv('MAIL_FROM_ADDRESS'));
        if ($from !== '') {
            $headers[] = 'From: '.$from;
            $headers[] = 'Reply-To: '.$from;
        }
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';

        $sent = mail($recipient, $subject, $message, implode("\r\n", $headers));
        if (! $sent) {
            throw new \RuntimeException('mail() delivery failed. Configure SendGrid or SMTP relay.');
        }

        return [
            'provider' => 'live-mail',
            'channel' => 'email',
            'recipient' => $recipient,
            'status' => 'sent',
            'message_id' => 'MAIL'.strtoupper(bin2hex(random_bytes(4))),
            'subject' => $subject,
        ];
    }

    private function sendSlack(string $recipient, string $subject, string $message): array
    {
        $webhookUrl = trim((string) getenv('SLACK_WEBHOOK_URL'));
        if ($webhookUrl === '' && str_starts_with($recipient, 'http')) {
            $webhookUrl = $recipient;
        }
        if ($webhookUrl === '') {
            throw new \RuntimeException('SLACK_WEBHOOK_URL is not configured.');
        }

        $payload = ['text' => trim($subject."\n".$message)];
        $response = HttpClient::request('POST', $webhookUrl, [], $payload, max(3, (int) (getenv('MESSAGING_TIMEOUT') ?: 25)));

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            throw new \RuntimeException('Slack send failed: HTTP '.$response['status_code']);
        }

        return [
            'provider' => 'live-slack',
            'channel' => 'slack',
            'recipient' => $recipient,
            'status' => 'sent',
            'message_id' => 'SLK'.strtoupper(bin2hex(random_bytes(4))),
            'subject' => $subject,
        ];
    }

    private function sendWhatsApp(string $recipient, string $subject, string $message): array
    {
        $token = trim((string) getenv('WHATSAPP_ACCESS_TOKEN'));
        $phoneNumberId = trim((string) getenv('WHATSAPP_PHONE_NUMBER_ID'));
        if ($token === '' || $phoneNumberId === '') {
            throw new \RuntimeException('WhatsApp Cloud credentials are not configured.');
        }

        $url = 'https://graph.facebook.com/v20.0/'.$phoneNumberId.'/messages';
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => preg_replace('/[^0-9]/', '', $recipient),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => trim($subject."\n".$message),
            ],
        ];
        $headers = ['Authorization' => 'Bearer '.$token];
        $response = HttpClient::request('POST', $url, $headers, $payload, max(3, (int) (getenv('MESSAGING_TIMEOUT') ?: 25)));

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            throw new \RuntimeException('WhatsApp send failed: HTTP '.$response['status_code'].' '.trim((string) $response['body']));
        }

        $json = is_array($response['json']) ? $response['json'] : [];
        $messageId = (string) ($json['messages'][0]['id'] ?? ('WSP'.strtoupper(bin2hex(random_bytes(4)))));

        return [
            'provider' => 'live-whatsapp',
            'channel' => 'whatsapp',
            'recipient' => $recipient,
            'status' => 'sent',
            'message_id' => $messageId,
            'subject' => $subject,
        ];
    }

    private function sendGenericWebhook(string $channel, string $recipient, string $subject, string $message): array
    {
        $endpoint = trim((string) getenv('MESSAGING_OUTBOUND_URL'));
        if ($endpoint === '') {
            throw new \RuntimeException('Unknown messaging channel and MESSAGING_OUTBOUND_URL is not configured.');
        }

        $token = trim((string) getenv('MESSAGING_OUTBOUND_TOKEN'));
        $headers = [];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $payload = [
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => $subject,
            'message' => $message,
        ];
        $response = HttpClient::request('POST', $endpoint, $headers, $payload, max(3, (int) (getenv('MESSAGING_TIMEOUT') ?: 25)));

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            throw new \RuntimeException('Generic messaging webhook failed: HTTP '.$response['status_code']);
        }

        $json = is_array($response['json']) ? $response['json'] : [];

        return [
            'provider' => 'live-messaging',
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => (string) ($json['status'] ?? 'sent'),
            'message_id' => (string) ($json['message_id'] ?? ('MSG'.strtoupper(bin2hex(random_bytes(4))))),
            'subject' => $subject,
        ];
    }
}

