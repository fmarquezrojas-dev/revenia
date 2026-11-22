<?php

use App\Http\Controllers\BankReceiptController;
use App\Http\Controllers\CollectionFollowupController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerStatementController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentDeliveryController;
use App\Http\Controllers\DocumentSeriesController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IssuanceController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\MerchantConnectionController;
use App\Http\Controllers\OdooController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReceivablesController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::post('/v1/webhooks/mercadopago/companies/{merchantCompany}', MercadoPagoWebhookController::class)
    ->whereUuid('merchantCompany')->withoutMiddleware([EnsureFrontendRequestsAreStateful::class]);
Route::post('/v1/webhooks/mercadopago', MercadoPagoWebhookController::class)
    ->withoutMiddleware([EnsureFrontendRequestsAreStateful::class]);

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'account.active'])

    ->group(function (): void {
        Route::get('/merchant-connection', [MerchantConnectionController::class, 'show']);
        Route::put('/merchant-connection', [MerchantConnectionController::class, 'link'])->middleware('throttle:8,1');
        Route::delete('/merchant-connection', [MerchantConnectionController::class, 'destroy']);
        Route::get('/integrations/odoo', [OdooController::class, 'show']);
        Route::put('/integrations/odoo', [OdooController::class, 'configure'])->middleware('throttle:10,1');
        Route::patch('/integrations/odoo', [OdooController::class, 'toggle'])->middleware('throttle:10,1');
        Route::match(['put', 'delete'], '/integrations/odoo/mappings', [OdooController::class, 'mapping']);
        Route::get('/documents/{documentId}/odoo', [OdooController::class, 'document'])->whereUuid('documentId');
        Route::post('/documents/{documentId}/odoo', [OdooController::class, 'queue'])->whereUuid('documentId')->middleware('throttle:30,1');
        Route::get('/receivables', ReceivablesController::class);
        Route::prefix('bank-receipts')->controller(BankReceiptController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/accounts', 'accounts');
            Route::post('/accounts', 'saveAccount');
            Route::put('/accounts/{id}', 'saveAccount')->whereUuid('id');
            Route::get('/{id}', 'show')->whereUuid('id');
            Route::get('/{id}/candidates', 'candidates')->whereUuid('id');
            Route::post('/{id}/{action}', 'store')->whereUuid('id')->whereIn('action', ['confirm', 'identify', 'apply', 'reverse']);
        });
        Route::get('/receivables/agenda', [CollectionFollowupController::class, 'agenda']);
        Route::get('/receivables/{id}/followup', [CollectionFollowupController::class, 'show'])->whereUuid('id');
        Route::post('/receivables/{id}/followup/{action}', [CollectionFollowupController::class, 'store'])
            ->whereUuid('id')->whereIn('action', ['assign', 'contact', 'correct', 'promise', 'reschedule', 'cancel', 'fulfill'])
            ->middleware('throttle:60,1');
        Route::get('/incidents', [IncidentController::class, 'index']);
        Route::prefix('incidents/{process}/{operation}')->where(['process' => 'emission|pdf|email|collection|odoo',
            'operation' => '[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}'])->group(function (): void {
                Route::get('/', [IncidentController::class, 'show']);
                Route::post('/actions', [IncidentController::class, 'act'])->middleware('throttle:30,1');
            });
        Route::get('/dashboard', DashboardController::class);
        Route::post('/subscriptions/{subscriptionId}/cancel', [SubscriptionController::class, 'cancel'])->whereUuid('subscriptionId');
        Route::get('/plans', [SubscriptionController::class, 'plans']);
        Route::get('/subscription', [SubscriptionController::class, 'show']);
        Route::get('/usage', [SubscriptionController::class, 'usage']);
        Route::get('/subscriptions/history', [SubscriptionController::class, 'history']);
        Route::post('/subscriptions', [SubscriptionController::class, 'store']);
        Route::get('/subscriptions/{subscriptionId}', [SubscriptionController::class, 'intent'])->whereUuid('subscriptionId');

        Route::post('/documents/{documentId}/credit-notes', [DocumentController::class, 'creditNote'])->whereUuid('documentId');
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
        Route::get('/customers/{customerId}/statement', CustomerStatementController::class)->whereUuid('customerId');
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
