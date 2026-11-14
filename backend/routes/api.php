<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentDeliveryController;
use App\Http\Controllers\DocumentSeriesController;
use App\Http\Controllers\IssuanceController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::post('/v1/webhooks/mercadopago', MercadoPagoWebhookController::class)
    ->withoutMiddleware([EnsureFrontendRequestsAreStateful::class]);

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'account.active'])

    ->group(function (): void {
        Route::get('/dashboard', DashboardController::class);
        Route::post('/subscriptions/{subscriptionId}/cancel', [SubscriptionController::class, 'cancel'])->whereUuid('subscriptionId');
        Route::get('/plans', [SubscriptionController::class, 'plans']);
        Route::get('/subscription', [SubscriptionController::class, 'show']);
        Route::get('/usage', [SubscriptionController::class, 'usage']);
        Route::get('/subscriptions/history', [SubscriptionController::class, 'history']);
        Route::post('/subscriptions', [SubscriptionController::class, 'store']);
        Route::get('/subscriptions/{subscriptionId}', [SubscriptionController::class, 'intent'])->whereUuid('subscriptionId');

        Route::get('/documents/{documentId}/collections', [PaymentController::class, 'show'])->whereUuid('documentId');
        Route::post('/documents/{documentId}/payment-link', [PaymentController::class, 'create'])->whereUuid('documentId');
        Route::post('/documents/{documentId}/payments/reconcile', [PaymentController::class, 'reconcile'])->whereUuid('documentId');
        Route::get('/documents/{documentId}/pdf', [DocumentDeliveryController::class, 'download'])->whereUuid('documentId');
        Route::post('/documents/{documentId}/pdf/regenerate', [DocumentDeliveryController::class, 'regenerate'])->whereUuid('documentId');
        Route::post('/documents/{documentId}/email', [DocumentDeliveryController::class, 'email'])->whereUuid('documentId');
        Route::post('/documents/{documentId}/issue', [IssuanceController::class, 'issue'])->whereUuid('documentId');
        Route::post('/documents/{documentId}/retry', [IssuanceController::class, 'retry'])->whereUuid('documentId');
        Route::get('/documents/{documentId}/events', [IssuanceController::class, 'events'])->whereUuid('documentId');
        Route::get('/document-series-options', [DocumentController::class, 'seriesOptions']);
        Route::post('/documents/preview', [DocumentController::class, 'preview'])->name('documents.preview');
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::get('/documents/{documentId}', [DocumentController::class, 'show'])->whereUuid('documentId');
        Route::patch('/documents/{documentId}', [DocumentController::class, 'update'])->whereUuid('documentId');
        Route::delete('/documents/{documentId}', [DocumentController::class, 'destroy'])->whereUuid('documentId');

        Route::get('/series', [DocumentSeriesController::class, 'index']);
        Route::post('/series', [DocumentSeriesController::class, 'store']);
        Route::get('/series/{seriesId}', [DocumentSeriesController::class, 'show'])->whereUuid('seriesId');
        Route::patch('/series/{seriesId}', [DocumentSeriesController::class, 'update'])->whereUuid('seriesId');
        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::get('/products/{productId}', [ProductController::class, 'show'])->whereUuid('productId');
        Route::patch('/products/{productId}', [ProductController::class, 'update'])->whereUuid('productId');
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{customerId}', [CustomerController::class, 'show'])->whereUuid('customerId');
        Route::patch('/customers/{customerId}', [CustomerController::class, 'update'])->whereUuid('customerId');
        Route::get('/company', [CompanyController::class, 'show']);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/company', [CompanyController::class, 'update']);
        Route::patch('/users/{userId}', [UserController::class, 'update'])
            ->whereUuid('userId');

        Route::get('/me', function (Request $request) {
            $user = $request->user();
            $company = $user->company;

            return response()->json([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'company' => [
                        'id' => $company->id,
                        'legal_name' => $company->legal_name,
                        'trade_name' => $company->trade_name,
                    ],

                ],
            ]);
        });
    });
