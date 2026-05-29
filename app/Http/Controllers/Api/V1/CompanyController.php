<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Company::query()->orderBy('name');

        if ($request->filled('organization_id')) {
            $query->where('organization_id', (int) $request->integer('organization_id'));
        }

        return $this->success($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'base_currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'tax_profile_json' => ['nullable', 'array'],
        ]);

        $company = Company::query()->create($validated);
        $this->audit($request, 'company.created', 'company', $company->id);

        return $this->success($company, 201);
    }

    public function show(Company $company): JsonResponse
    {
        return $this->success($company);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'base_currency' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'max:50'],
            'tax_profile_json' => ['sometimes', 'array'],
        ]);

        $company->update($validated);
        $this->audit($request, 'company.updated', 'company', $company->id);

        return $this->success($company);
    }
}
