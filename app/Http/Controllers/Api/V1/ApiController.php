<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\ValueObjects\AuditEventData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    protected function companyId(Request $request): int
    {
        return (int) $request->attributes->get('company_id', 0);
    }

    protected function success(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    protected function audit(Request $request, string $action, string $entityType, ?int $entityId, array $metadata = []): void
    {
        app(AuditLogger::class)->log(
            new AuditEventData($action, $entityType, $entityId, $metadata),
            request: $request,
            companyId: $this->companyId($request) ?: null,
            userId: $request->user()?->id,
        );
    }
}
