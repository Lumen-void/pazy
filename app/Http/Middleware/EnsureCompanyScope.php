<?php

namespace App\Http\Middleware;

use App\Models\CompanyUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Authentication required.');
        }

        $companyId = (int) ($request->header('X-Company-ID') ?? $request->query('company_id'));

        if ($companyId < 1) {
            abort(400, 'X-Company-ID header is required.');
        }

        $membership = CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            abort(403, 'You are not allowed to access this company scope.');
        }

        $request->attributes->set('company_id', $companyId);
        $request->attributes->set('role_id', $membership->role_id);

        return $next($request);
    }
}
