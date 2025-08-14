<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// API Version 1 routes
Route::prefix('v1')->group(function () {
    
    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware('auth:sanctum');

    // Authentication routes
    Route::prefix('auth')->group(function () {
        // Public authentication routes with rate limiting
        Route::post('/register', [App\Http\Controllers\Api\V1\AuthController::class, 'register'])
            ->middleware('throttle:5,1');
        Route::post('/login', [App\Http\Controllers\Api\V1\AuthController::class, 'login'])
            ->middleware(['login.rate.limit', 'throttle:10,1']);
        // Password reset routes
        Route::post('/password/request-reset', [App\Http\Controllers\Api\V1\AuthController::class, 'requestPasswordReset'])
            ->middleware('throttle:3,1');
        Route::post('/password/reset', [App\Http\Controllers\Api\V1\AuthController::class, 'resetPassword'])
            ->middleware('throttle:5,1');
        Route::post('/password/verify-token', [App\Http\Controllers\Api\V1\AuthController::class, 'verifyPasswordResetToken'])
            ->middleware('throttle:10,1');
        
        // Email verification routes
        Route::get('/email/verify/{id}/{hash}', [App\Http\Controllers\Api\V1\AuthController::class, 'verifyEmail'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
        Route::post('/email/verification-notification', [App\Http\Controllers\Api\V1\AuthController::class, 'resendVerificationEmail'])
            ->middleware('throttle:6,1');
        Route::post('/email/check-verification', [App\Http\Controllers\Api\V1\AuthController::class, 'checkEmailVerification'])
            ->middleware('throttle:6,1');

        // Protected authentication routes (Sanctum) with session security
        Route::middleware(['auth:sanctum', 'session.security'])->group(function () {
            Route::post('/logout', [App\Http\Controllers\Api\V1\AuthController::class, 'logout']);
            Route::post('/logout-all', [App\Http\Controllers\Api\V1\AuthController::class, 'logoutAll']);
            Route::get('/sessions', [App\Http\Controllers\Api\V1\AuthController::class, 'getActiveSessions']);
            Route::post('/sessions/revoke', [App\Http\Controllers\Api\V1\AuthController::class, 'revokeSession']);
        });

        // JWT Authentication routes
        Route::prefix('jwt')->group(function () {
            // Public JWT routes
            Route::post('/login', [App\Http\Controllers\Api\V1\JWTAuthController::class, 'login'])
                ->middleware(['login.rate.limit', 'throttle:10,1']);
            Route::post('/refresh', [App\Http\Controllers\Api\V1\JWTAuthController::class, 'refreshToken'])
                ->middleware('throttle:20,1');

            // Protected JWT routes
            Route::middleware('jwt.auth')->group(function () {
                Route::post('/logout', [App\Http\Controllers\Api\V1\JWTAuthController::class, 'logout']);
                Route::post('/logout-all', [App\Http\Controllers\Api\V1\JWTAuthController::class, 'logoutAll']);
                Route::get('/tokens', [App\Http\Controllers\Api\V1\JWTAuthController::class, 'getActiveTokens']);
                Route::post('/tokens/revoke', [App\Http\Controllers\Api\V1\JWTAuthController::class, 'revokeToken']);
            });
        });
    });

    // Public food routes
    Route::prefix('foods')->group(function () {
        Route::get('/search', [App\Http\Controllers\Api\V1\FoodController::class, 'search']);
        Route::get('/popular', [App\Http\Controllers\Api\V1\FoodController::class, 'popular']);
        Route::get('/categories', [App\Http\Controllers\Api\V1\FoodController::class, 'categories']);
        Route::get('/{id}', [App\Http\Controllers\Api\V1\FoodController::class, 'show']);
    });

    // Protected API routes with session security
    Route::middleware(['auth:sanctum', 'session.security'])->group(function () {
        
        // User management
        Route::prefix('user')->group(function () {
            Route::get('/profile', [App\Http\Controllers\Api\V1\UserController::class, 'profile']);
            Route::put('/profile', [App\Http\Controllers\Api\V1\UserController::class, 'updateProfile']);
            Route::get('/metrics', [App\Http\Controllers\Api\V1\UserController::class, 'getMetrics']);
            Route::post('/metrics', [App\Http\Controllers\Api\V1\UserController::class, 'storeMetrics']);
            Route::get('/stats', [App\Http\Controllers\Api\V1\UserController::class, 'getStats']);
        });
        
        // Food management (protected)
        Route::prefix('foods')->group(function () {
            Route::post('/', [App\Http\Controllers\Api\V1\FoodController::class, 'store']);
            Route::put('/{id}', [App\Http\Controllers\Api\V1\FoodController::class, 'update']);
        });
        
        // Food logging
        Route::prefix('food-logs')->group(function () {
            Route::post('/', [App\Http\Controllers\Api\V1\FoodLogController::class, 'store']);
            Route::get('/daily', [App\Http\Controllers\Api\V1\FoodLogController::class, 'daily']);
            Route::get('/daily/{date}', [App\Http\Controllers\Api\V1\FoodLogController::class, 'dailyByDate']);
            Route::get('/summary', [App\Http\Controllers\Api\V1\FoodLogController::class, 'nutritionSummary']);
            Route::put('/{id}', [App\Http\Controllers\Api\V1\FoodLogController::class, 'update']);
            Route::delete('/{id}', [App\Http\Controllers\Api\V1\FoodLogController::class, 'destroy']);
        });
        
        // Test route for authenticated users
        Route::get('/test', function (Request $request) {
            return response()->json([
                'message' => 'Sanctum authentication is working!',
                'user' => $request->user()->only(['id', 'first_name', 'last_name', 'email'])
            ]);
        });
        
    });
    
});