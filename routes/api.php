<?php
use App\Http\Controllers\Api\CsoApiController;

Route::middleware('auth:sanctum')->get('/drivers/available', [CsoApiController::class, 'getAvailableDrivers']);