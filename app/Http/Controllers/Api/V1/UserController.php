<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $users = User::query()
            ->whereHas('memberships', fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('name')
            ->paginate(20);

        return $this->success($users);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:10'],
            'role_id' => ['required', 'exists:roles,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
        ]);

        $user = DB::transaction(function () use ($validated, $companyId) {
            $user = User::query()->firstOrCreate(
                ['email' => $validated['email']],
                [
                    'name' => $validated['name'],
                    'password' => $validated['password'],
                    'status' => 'active',
                ],
            );

            CompanyUser::query()->updateOrCreate(
                ['company_id' => $companyId, 'user_id' => $user->id],
                [
                    'role_id' => $validated['role_id'],
                    'department_id' => $validated['department_id'] ?? null,
                    'cost_center_id' => $validated['cost_center_id'] ?? null,
                    'status' => 'active',
                ],
            );

            return $user;
        });

        $this->audit($request, 'user.assigned', 'user', $user->id, ['company_id' => $companyId]);

        return $this->success($user, 201);
    }
}
