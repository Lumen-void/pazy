<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $companyId = (int) $request->attributes->get('company_id');

        if (! $user || ! $user->hasPermission($permission, $companyId)) {
            abort(403, 'Missing required permission: '.$permission);
        }

        return $next($request);
    }
}
