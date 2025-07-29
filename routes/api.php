<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Authentication routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [App\Http\Controllers\Api\AuthController::class, 'register']);
    Route::post('/login', [App\Http\Controllers\Api\AuthController::class, 'login']);
    Route::post('/logout', [App\Http\Controllers\Api\AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/password/reset', [App\Http\Controllers\Api\AuthController::class, 'resetPassword']);
});

// Test route for authenticated users
Route::middleware('auth:sanctum')->get('/test', function (Request $request) {
    return response()->json([
        'message' => 'Sanctum authentication is working!',
        'user' => $request->user()->only(['id', 'first_name', 'last_name', 'email'])
    ]);
});