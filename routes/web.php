<?php

use App\Http\Controllers\ObservabilityController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => config('app.name'),
    'status' => 'ok',
]));

Route::get('/health', [ObservabilityController::class, 'health']);
Route::get('/metrics', [ObservabilityController::class, 'metrics']);
