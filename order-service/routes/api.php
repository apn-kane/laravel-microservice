<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::apiResource('v1/orders', OrderController::class);

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'order-service',
        'timestamp' => now()->toISOString(),
    ]);
});