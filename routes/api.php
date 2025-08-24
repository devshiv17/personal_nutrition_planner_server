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
        Route::get('/autocomplete', [App\Http\Controllers\Api\V1\FoodController::class, 'autocomplete']);
        Route::get('/popular', [App\Http\Controllers\Api\V1\FoodController::class, 'popular']);
        Route::get('/categories', [App\Http\Controllers\Api\V1\FoodController::class, 'categories']);
        Route::get('/barcode/{barcode}', [App\Http\Controllers\Api\V1\FoodController::class, 'lookupBarcode']);
        Route::get('/{id}', [App\Http\Controllers\Api\V1\FoodController::class, 'show']);
        Route::get('/{id}/nutrition', [App\Http\Controllers\Api\V1\FoodController::class, 'getNutritionDetails']);
    });

    // Public recipe routes
    Route::prefix('recipes')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\V1\RecipeController::class, 'index']);
        Route::get('/popular', [App\Http\Controllers\Api\V1\RecipeController::class, 'popular']);
        Route::get('/collections', [App\Http\Controllers\Api\V1\RecipeController::class, 'collections']);
        Route::get('/{id}', [App\Http\Controllers\Api\V1\RecipeController::class, 'show']);
        Route::get('/{id}/scale', [App\Http\Controllers\Api\V1\RecipeController::class, 'scale']);
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
            Route::post('/import', [App\Http\Controllers\Api\V1\FoodController::class, 'importExternal']);
            Route::post('/compare', [App\Http\Controllers\Api\V1\FoodController::class, 'compare']);
            
            // Food favorites
            Route::get('/favorites', [App\Http\Controllers\Api\V1\FoodController::class, 'favorites']);
            Route::post('/{id}/favorite', [App\Http\Controllers\Api\V1\FoodController::class, 'addToFavorites']);
            Route::delete('/{id}/favorite', [App\Http\Controllers\Api\V1\FoodController::class, 'removeFromFavorites']);
            Route::get('/recent', [App\Http\Controllers\Api\V1\FoodController::class, 'recent']);
        });

        // Recipe management (protected)
        Route::prefix('recipes')->group(function () {
            Route::post('/', [App\Http\Controllers\Api\V1\RecipeController::class, 'store']);
            Route::put('/{id}', [App\Http\Controllers\Api\V1\RecipeController::class, 'update']);
            Route::delete('/{id}', [App\Http\Controllers\Api\V1\RecipeController::class, 'destroy']);
            Route::post('/{id}/duplicate', [App\Http\Controllers\Api\V1\RecipeController::class, 'duplicate']);
            
            // Recipe ratings
            Route::post('/{id}/rating', [App\Http\Controllers\Api\V1\RecipeController::class, 'addRating']);
            
            // Recipe favorites
            Route::get('/favorites', [App\Http\Controllers\Api\V1\RecipeController::class, 'favorites']);
            Route::post('/{id}/favorite', [App\Http\Controllers\Api\V1\RecipeController::class, 'addToFavorites']);
            Route::delete('/{id}/favorite', [App\Http\Controllers\Api\V1\RecipeController::class, 'removeFromFavorites']);
            
            // Recipe collections
            Route::post('/collections', [App\Http\Controllers\Api\V1\RecipeController::class, 'createCollection']);
            Route::post('/{id}/collections', [App\Http\Controllers\Api\V1\RecipeController::class, 'addToCollection']);
            
            // Recipe image management
            Route::get('/{id}/images', [App\Http\Controllers\Api\V1\RecipeController::class, 'getImages']);
            Route::post('/{id}/images', [App\Http\Controllers\Api\V1\RecipeController::class, 'uploadImages']);
            Route::delete('/{id}/images', [App\Http\Controllers\Api\V1\RecipeController::class, 'deleteImage']);
            Route::put('/{id}/images/metadata', [App\Http\Controllers\Api\V1\RecipeController::class, 'updateImageMetadata']);
            Route::put('/{id}/images/reorder', [App\Http\Controllers\Api\V1\RecipeController::class, 'reorderImages']);
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

        // Health metrics
        Route::prefix('health-metrics')->group(function () {
            Route::get('/', [App\Http\Controllers\Api\V1\HealthMetricsController::class, 'index']);
            Route::post('/', [App\Http\Controllers\Api\V1\HealthMetricsController::class, 'store']);
            Route::get('/types', [App\Http\Controllers\Api\V1\HealthMetricsController::class, 'types']);
            Route::get('/dashboard', [App\Http\Controllers\Api\V1\HealthMetricsController::class, 'dashboard']);
            Route::get('/history', [App\Http\Controllers\Api\V1\HealthMetricsController::class, 'history']);
            
            // Outlier detection routes
            Route::get('/outliers/analyze', [App\Http\Controllers\Api\V1\HealthMetricsController::class, 'analyzeOutliers']);
            Route::get('/outliers/methods', [App\Http\Controllers\Api\V1\HealthMetricsController::class, 'outlierMethods']);
            Route::post('/outliers/validate', [App\Http\Controllers\Api\V1\HealthMetricsController::class, 'validateMetric']);
            
            Route::get('/{id}', [App\Http\Controllers\Api\V1\HealthMetricsController::class, 'show']);
            Route::put('/{id}', [App\Http\Controllers\Api\V1\HealthMetricsController::class, 'update']);
            Route::delete('/{id}', [App\Http\Controllers\Api\V1\HealthMetricsController::class, 'destroy']);
        });

        // Goals management
        Route::prefix('goals')->group(function () {
            Route::get('/', [App\Http\Controllers\Api\V1\GoalsController::class, 'index']);
            Route::post('/', [App\Http\Controllers\Api\V1\GoalsController::class, 'store']);
            Route::get('/types', [App\Http\Controllers\Api\V1\GoalsController::class, 'types']);
            Route::get('/progress', [App\Http\Controllers\Api\V1\GoalsController::class, 'progress']);
            Route::get('/{id}', [App\Http\Controllers\Api\V1\GoalsController::class, 'show']);
            Route::put('/{id}', [App\Http\Controllers\Api\V1\GoalsController::class, 'update']);
            Route::delete('/{id}', [App\Http\Controllers\Api\V1\GoalsController::class, 'destroy']);
            Route::post('/{id}/update-progress', [App\Http\Controllers\Api\V1\GoalsController::class, 'updateProgress']);
        });

        // Meal planning
        Route::prefix('meal-plans')->group(function () {
            Route::get('/', [App\Http\Controllers\Api\V1\MealPlanController::class, 'index']);
            Route::post('/generate', [App\Http\Controllers\Api\V1\MealPlanController::class, 'generate']);
            Route::get('/{id}', [App\Http\Controllers\Api\V1\MealPlanController::class, 'show']);
            Route::post('/{id}/regenerate', [App\Http\Controllers\Api\V1\MealPlanController::class, 'regenerate']);
            Route::get('/{id}/shopping-list', [App\Http\Controllers\Api\V1\MealPlanController::class, 'getShoppingList']);
            Route::get('/{id}/meal-prep', [App\Http\Controllers\Api\V1\MealPlanController::class, 'getMealPrepSuggestions']);
            Route::get('/{id}/analytics', [App\Http\Controllers\Api\V1\MealPlanController::class, 'getAnalytics']);
            
            // Meal management
            Route::get('/meals/{mealId}/alternatives', [App\Http\Controllers\Api\V1\MealPlanController::class, 'getAlternatives']);
            Route::post('/meals/{mealId}/substitute', [App\Http\Controllers\Api\V1\MealPlanController::class, 'substituteMeal']);
            Route::post('/meals/{mealId}/complete', [App\Http\Controllers\Api\V1\MealPlanController::class, 'completeMeal']);
            Route::post('/meals/{mealId}/skip', [App\Http\Controllers\Api\V1\MealPlanController::class, 'skipMeal']);
        });

        // Dietary preferences
        Route::prefix('dietary-preferences')->group(function () {
            Route::get('/', function (Request $request) {
                $user = $request->user();
                $preferences = $user->dietaryPreferences;
                return response()->json(['data' => $preferences]);
            });
            Route::post('/', [App\Http\Controllers\Api\V1\MealPlanController::class, 'updateDietaryPreferences']);
        });

        // Profile completion
        Route::prefix('profile')->group(function () {
            Route::get('/completion', [App\Http\Controllers\Api\V1\ProfileController::class, 'completion']);
            Route::get('/progress', [App\Http\Controllers\Api\V1\ProfileController::class, 'progress']);
            Route::get('/sections', [App\Http\Controllers\Api\V1\ProfileController::class, 'sections']);
            Route::post('/sections/complete', [App\Http\Controllers\Api\V1\ProfileController::class, 'markSectionComplete']);
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