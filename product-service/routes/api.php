<?php

use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::apiResource('v1/products', ProductController::class);

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'product-service',
        'timestamp' => now()->toISOString(),
    ]);
});