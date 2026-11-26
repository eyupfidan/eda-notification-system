<?php

use App\Http\Controllers\Api\V1\BatchController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\TemplateController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications', [NotificationController::class, 'store']);
    Route::post('notifications/batch', [BatchController::class, 'store']);
    Route::get('notifications/{notification}', [NotificationController::class, 'show'])->whereUuid('notification');
    Route::patch('notifications/{notification}/cancel', [NotificationController::class, 'cancel'])->whereUuid('notification');
    Route::post('notifications/{notification}/retry', [NotificationController::class, 'retry'])->whereUuid('notification');

    Route::get('batches/{batchId}', [BatchController::class, 'show'])->whereUuid('batchId');
    Route::patch('batches/{batchId}/cancel', [BatchController::class, 'cancel'])->whereUuid('batchId');
    Route::post('batches/{batchId}/retry', [BatchController::class, 'retry'])->whereUuid('batchId');

    Route::apiResource('templates', TemplateController::class)->whereUuid('template');
    Route::post('templates/{template}/preview', [TemplateController::class, 'preview'])->whereUuid('template');
});
