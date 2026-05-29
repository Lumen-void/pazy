<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = (string) $request->route('provider');
        $signature = (string) $request->header('X-Signature', '');

        $secret = (string) (
            config('services.providers.'.$provider.'.webhook_secret')
            ?? config('services.webhook_secret')
        );

        if ($secret === '') {
            abort(500, 'Webhook secret is not configured.');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(401, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
