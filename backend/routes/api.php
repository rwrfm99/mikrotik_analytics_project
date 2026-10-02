<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\HotspotController;
use App\Http\Controllers\TrafficController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', HealthController::class);
Route::get('/v1/hotspot/collection', [HotspotController::class, 'status']);
Route::get('/v1/hotspot/users/active', [HotspotController::class, 'active']);
Route::get('/v1/hotspot/sessions', [HotspotController::class, 'sessions']);
Route::get('/v1/traffic/users', [TrafficController::class, 'users']);
Route::get('/v1/traffic/status', [TrafficController::class, 'status']);
Route::get('/v1/traffic/users/{user}', [TrafficController::class, 'report']);
