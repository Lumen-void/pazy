<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\DispatchNotificationJob;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $rows = Notification::query()
            ->forCompany($this->companyId($request))
            ->latest('id')
            ->paginate(20);

        return $this->success($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'channel' => ['required', 'string', 'max:50'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message_json' => ['required', 'array'],
        ]);

        $notification = Notification::query()->create([
            'company_id' => $this->companyId($request),
            'user_id' => $validated['user_id'] ?? null,
            'channel' => strtolower($validated['channel']),
            'subject' => $validated['subject'] ?? null,
            'message_json' => $validated['message_json'],
            'status' => 'queued',
        ]);

        DispatchNotificationJob::dispatch($notification->id);
        $this->audit($request, 'notification.queued', 'notification', $notification->id);

        return $this->success($notification, 201);
    }
}
