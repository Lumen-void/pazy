<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->success(Organization::query()->orderBy('name')->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:organizations,slug'],
            'status' => ['nullable', 'string', 'max:50'],
            'settings_json' => ['nullable', 'array'],
        ]);

        $organization = Organization::query()->create($validated);
        $this->audit($request, 'organization.created', 'organization', $organization->id);

        return $this->success($organization, 201);
    }

    public function show(Organization $organization): JsonResponse
    {
        return $this->success($organization);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'max:50'],
            'settings_json' => ['sometimes', 'array'],
        ]);

        $organization->update($validated);
        $this->audit($request, 'organization.updated', 'organization', $organization->id);

        return $this->success($organization);
    }
}
