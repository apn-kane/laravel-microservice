<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'user-service',
        'timestamp' => now()->toISOString(),
    ]);
});

Route::apiResource('v1/users', UserController::class);