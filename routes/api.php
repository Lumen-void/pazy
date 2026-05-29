<?php

use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\GrnController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\TaxReconciliationController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VendorController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::post('/webhooks/{provider}/{event}', [WebhookController::class, 'handle'])
        ->middleware('verify.signature');

    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::apiResource('organizations', OrganizationController::class)->only(['index', 'store', 'show', 'update']);
        Route::apiResource('companies', CompanyController::class)->only(['index', 'store', 'show', 'update']);

        Route::middleware(['company.scope'])->group(function (): void {
            Route::apiResource('users', UserController::class)->only(['index', 'store']);
            Route::apiResource('vendors', VendorController::class)->only(['index', 'store', 'show', 'update']);
            Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'store', 'show']);
            Route::apiResource('grns', GrnController::class)->only(['index', 'store']);
            Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store', 'show']);
            Route::post('/invoices/{invoice}/match', [InvoiceController::class, 'match']);
            Route::post('/invoices/{invoice}/submit-approval', [InvoiceController::class, 'submitForApproval']);
            Route::apiResource('expenses', ExpenseController::class)->only(['index', 'store']);

            Route::get('/approvals', [ApprovalController::class, 'index']);
            Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve']);
            Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject']);

            Route::apiResource('payments', PaymentController::class)->only(['index', 'store']);
            Route::post('/payments/{payment}/execute', [PaymentController::class, 'execute']);

            Route::get('/tax/reconciliations', [TaxReconciliationController::class, 'index']);
            Route::post('/tax/reconciliations/run', [TaxReconciliationController::class, 'run']);

            Route::apiResource('notifications', NotificationController::class)->only(['index', 'store']);
            Route::get('/reports', [ReportController::class, 'summary']);
        });
    });
});
