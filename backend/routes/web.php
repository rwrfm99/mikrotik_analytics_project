<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['name' => 'MikroTik Analytics', 'api' => '/api/v1']);
});

Route::get('/health', HealthController::class);
