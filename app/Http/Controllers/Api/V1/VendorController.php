<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Vendor;
use App\Modules\Integrations\Contracts\IdentityVerificationProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $vendors = Vendor::query()
            ->forCompany($companyId)
            ->orderBy('name')
            ->paginate(20);

        return $this->success($vendors);
    }

    public function store(Request $request, IdentityVerificationProvider $verification): JsonResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'gstin' => ['nullable', 'string', 'max:30'],
            'pan' => ['nullable', 'string', 'max:30'],
            'bank_account_encrypted' => ['nullable', 'string'],
            'contact_info_json' => ['nullable', 'array'],
        ]);

        $gstinCheck = ! empty($validated['gstin']) ? $verification->verify('gstin', $validated['gstin']) : null;
        $panCheck = ! empty($validated['pan']) ? $verification->verify('pan', $validated['pan']) : null;

        $vendor = Vendor::query()->create([
            ...$validated,
            'company_id' => $companyId,
            'owner_user_id' => $request->user()?->id,
            'kyc_status' => ($gstinCheck['verified'] ?? true) && ($panCheck['verified'] ?? true) ? 'verified' : 'pending_review',
            'status' => 'active',
            'compliance_score' => 100,
        ]);

        $this->audit($request, 'vendor.created', 'vendor', $vendor->id);

        return $this->success($vendor, 201);
    }

    public function show(Request $request, Vendor $vendor): JsonResponse
    {
        abort_unless($vendor->company_id === $this->companyId($request), 404);

        return $this->success($vendor);
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        abort_unless($vendor->company_id === $this->companyId($request), 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'gstin' => ['sometimes', 'nullable', 'string', 'max:30'],
            'pan' => ['sometimes', 'nullable', 'string', 'max:30'],
            'bank_account_encrypted' => ['sometimes', 'nullable', 'string'],
            'contact_info_json' => ['sometimes', 'array'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]);

        $vendor->update($validated);
        $this->audit($request, 'vendor.updated', 'vendor', $vendor->id);

        return $this->success($vendor);
    }
}
