<?php

use App\Http\Controllers\Api\EventController;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('events', EventController::class)
        ->middleware('throttle:analytics-ingestion')
        ->name('api.v1.events.store');

    Route::options('events', function (): Response {
        return response('', 204)->withHeaders([
            'Access-Control-Allow-Origin' => request()->header('Origin', '*'),
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '86400',
        ]);
    });
});
