<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\Integration\ZadarmaCallController;

Route::controller(ZadarmaCallController::class)->prefix('integrations/zadarma')->group(function () {
    Route::post('calls', 'store')->name('admin.integrations.zadarma.calls.store');
});
