<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Health check endpoint (no rate limiting)
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now()->toISOString(),
        'version' => '1.0.0'
    ]);
});

// Authentication routes with rate limiting
Route::prefix('auth')->middleware(['api.throttle:10,1'])->group(function () {
    Route::post('/register', [App\Http\Controllers\Api\AuthController::class, 'register']);
    Route::post('/login', [App\Http\Controllers\Api\AuthController::class, 'login']);
    Route::post('/logout', [App\Http\Controllers\Api\AuthController::class, 'logout'])->middleware('api.auth');
    Route::post('/password/reset', [App\Http\Controllers\Api\AuthController::class, 'resetPassword']);
});

// Public food routes with moderate rate limiting
Route::prefix('foods')->middleware(['api.throttle:100,1'])->group(function () {
    Route::get('/search', [App\Http\Controllers\Api\FoodController::class, 'search']);
    Route::get('/popular', [App\Http\Controllers\Api\FoodController::class, 'popular']);
    Route::get('/categories', [App\Http\Controllers\Api\FoodController::class, 'categories']);
    Route::get('/{id}', [App\Http\Controllers\Api\FoodController::class, 'show']);
});

// Protected API routes with authentication and rate limiting
Route::middleware(['api.auth', 'api.throttle:200,1'])->group(function () {
    
    // User info route
    Route::get('/user', function (Request $request) {
        return response()->json([
            'success' => true,
            'data' => $request->user()->makeHidden(['password_hash'])
        ]);
    });
    
    // User management
    Route::prefix('user')->group(function () {
        Route::get('/profile', [App\Http\Controllers\Api\UserController::class, 'profile']);
        Route::put('/profile', [App\Http\Controllers\Api\UserController::class, 'updateProfile']);
        Route::get('/metrics', [App\Http\Controllers\Api\UserController::class, 'getMetrics']);
        Route::post('/metrics', [App\Http\Controllers\Api\UserController::class, 'storeMetrics']);
        Route::get('/stats', [App\Http\Controllers\Api\UserController::class, 'getStats']);
    });
    
    // Food management (protected)
    Route::prefix('foods')->group(function () {
        Route::post('/', [App\Http\Controllers\Api\FoodController::class, 'store']);
        Route::put('/{id}', [App\Http\Controllers\Api\FoodController::class, 'update']);
    });
    
    // Food logging
    Route::prefix('food-logs')->group(function () {
        Route::post('/', [App\Http\Controllers\Api\FoodLogController::class, 'store']);
        Route::get('/daily', [App\Http\Controllers\Api\FoodLogController::class, 'daily']);
        Route::get('/daily/{date}', [App\Http\Controllers\Api\FoodLogController::class, 'dailyByDate']);
        Route::get('/summary', [App\Http\Controllers\Api\FoodLogController::class, 'nutritionSummary']);
        Route::put('/{id}', [App\Http\Controllers\Api\FoodLogController::class, 'update']);
        Route::delete('/{id}', [App\Http\Controllers\Api\FoodLogController::class, 'destroy']);
    });
    
    // Test route for authenticated users
    Route::get('/test', function (Request $request) {
        return response()->json([
            'success' => true,
            'message' => 'API middleware is working!',
            'data' => [
                'user' => $request->user()->only(['id', 'first_name', 'last_name', 'email']),
                'api_version' => $request->attributes->get('api_version'),
                'timestamp' => now()->toISOString(),
            ]
        ]);
    });
    
});