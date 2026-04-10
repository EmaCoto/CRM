<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Controllers\Integration\ZadarmaWebhookController;

/**
 * Home routes.
 */
Route::get('/', [Controller::class, 'redirectToLogin'])->name('krayin.home');

Route::match(['GET', 'POST'], 'zadarma/webhooks/call-events', [ZadarmaWebhookController::class, 'handle'])
    ->name('front.integrations.zadarma.webhook');
