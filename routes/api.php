<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\Smtp2GoWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', fn (Request $request) => $request->user());

// SMTP2Go delivery reports. Authenticated by the shared secret in the
// Authorization header — see Smtp2GoWebhookRequest.
Route::post('/webhooks/smtp2go', Smtp2GoWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.smtp2go');
