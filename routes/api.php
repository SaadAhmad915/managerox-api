<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\MetaWebhookController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Middleware\VerifyMetaSignature;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('api.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/dashboard', DashboardController::class);

    Route::post('leads/{lead}/convert', [LeadController::class, 'convert']);
    Route::apiResource('leads', LeadController::class);
    Route::apiResource('contacts', ContactController::class);
    Route::apiResource('deals', DealController::class);
    Route::apiResource('tasks', TaskController::class)->except('show');
});

/*
 * Meta Lead Ads webhook. Deliberately outside auth:sanctum — Meta's servers
 * have no session. Authenticity comes from the signed request body instead,
 * which VerifyMetaSignature checks against the app secret.
 */
Route::prefix('webhooks/meta')->group(function () {
    Route::get('leads', [MetaWebhookController::class, 'verify']);
    Route::post('leads', [MetaWebhookController::class, 'receive'])
        ->middleware(VerifyMetaSignature::class);
});
