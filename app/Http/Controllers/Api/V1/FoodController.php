<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Food;
use App\Services\FoodDatabaseService;
use App\Services\NutritionalCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class FoodController extends BaseApiController
{
    protected FoodDatabaseService $foodDatabaseService;
    protected NutritionalCalculationService $nutritionalCalculationService;

    public function __construct(
        FoodDatabaseService $foodDatabaseService,
        NutritionalCalculationService $nutritionalCalculationService
    ) {
        $this->foodDatabaseService = $foodDatabaseService;
        $this->nutritionalCalculationService = $nutritionalCalculationService;
    }
    /**
     * Search for foods
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'query' => 'required|string|min:2|max:100',
                'category' => 'sometimes|string|max:100',
                'brand' => 'sometimes|string|max:100',
                'verified_only' => 'sometimes|boolean',
                'max_calories' => 'sometimes|numeric|min:0',
                'min_protein' => 'sometimes|numeric|min:0',
                'max_carbs' => 'sometimes|numeric|min:0',
                'max_fat' => 'sometimes|numeric|min:0',
                'allergen_free' => 'sometimes|array',
                'dietary_preference' => 'sometimes|string',
                'per_page' => 'sometimes|integer|min:1|max:50',
                'use_external_apis' => 'sometimes|boolean'
            ]);

            $limit = $validatedData['per_page'] ?? 20;
            
            // Try external APIs first if requested
            if ($validatedData['use_external_apis'] ?? true) {
                try {
                    $externalResults = $this->foodDatabaseService->searchFoods(
                        $validatedData['query'],
                        $limit,
                        $validatedData
                    );
                    
                    if (!empty($externalResults['foods'])) {
                        return $this->success($externalResults, 'Foods retrieved from external database');
                    }
                } catch (\Exception $e) {
                    // Fall through to local search
                }
            }

            // Local database search with advanced filtering
            $query = Food::query();
            
            // Apply advanced search filters
            $query = $query->advancedSearch($validatedData);

            // Apply sorting - prioritize verified foods and usage count
            $query->orderByDesc('is_verified')
                  ->orderByDesc('usage_count')
                  ->orderBy('name');

            $foods = $query->paginate($limit);

            return $this->success([
                'foods' => $foods->items(),
                'pagination' => [
                    'current_page' => $foods->currentPage(),
                    'total_pages' => $foods->lastPage(),
                    'per_page' => $foods->perPage(),
                    'total' => $foods->total()
                ],
                'source' => 'local'
            ], 'Foods retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to search foods', 500, $e->getMessage());
        }
    }

    /**
     * Get specific food details
     */
    public function show(string $id): JsonResponse
    {
        try {
            $food = Food::find($id);

            if (!$food) {
                return $this->notFoundResponse('Food not found');
            }

            // Increment usage count
            $food->increment('usage_count');

            return $this->success($food, 'Food details retrieved successfully');

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to retrieve food details');
        }
    }

    /**
     * Get popular foods
     */
    public function popular(Request $request): JsonResponse
    {
        try {
            $validatedData = $this->validateRequest($request, [
                'category' => 'sometimes|string|max:100',
                'limit' => 'sometimes|integer|min:1|max:50',
            ]);

            $limit = $validatedData['limit'] ?? 20;
            $query = Food::query()->where('is_verified', true);

            if (isset($validatedData['category'])) {
                $query->where('category', $validatedData['category']);
            }

            $foods = $query->orderByDesc('usage_count')
                          ->orderBy('name')
                          ->limit($limit)
                          ->get();

            return $this->success($foods, 'Popular foods retrieved successfully');

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to retrieve popular foods');
        }
    }

    /**
     * Get food categories
     */
    public function categories(): JsonResponse
    {
        try {
            $categories = Food::select('category')
                             ->whereNotNull('category')
                             ->groupBy('category')
                             ->orderBy('category')
                             ->pluck('category');

            return $this->success($categories, 'Food categories retrieved successfully');

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to retrieve food categories');
        }
    }

    /**
     * Create new food (for verified users or admin)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            $validatedData = $this->validateRequest($request, [
                'name' => 'required|string|max:255',
                'brand' => 'sometimes|nullable|string|max:100',
                'category' => 'sometimes|nullable|string|max:100',
                'subcategory' => 'sometimes|nullable|string|max:100',
                'calories_per_100g' => 'required|numeric|min:0|max:9999',
                'protein_per_100g' => 'sometimes|numeric|min:0|max:100',
                'carbs_per_100g' => 'sometimes|numeric|min:0|max:100',
                'fat_per_100g' => 'sometimes|numeric|min:0|max:100',
                'fiber_per_100g' => 'sometimes|numeric|min:0|max:100',
                'sugar_per_100g' => 'sometimes|numeric|min:0|max:100',
                'sodium_per_100g' => 'sometimes|numeric|min:0|max:10000',
                'common_serving_size' => 'sometimes|nullable|numeric|min:0',
                'common_serving_unit' => 'sometimes|nullable|string|max:20',
                'barcode' => 'sometimes|nullable|string|max:50',
                'additional_nutrients' => 'sometimes|nullable|array',
            ]);

            $validatedData['created_by'] = $user->id;
            $validatedData['data_source'] = 'user_created';
            $validatedData['is_verified'] = false; // Admin verification required

            $food = Food::create($validatedData);

            return $this->success($food, 'Food created successfully. It will be reviewed for verification.', 201);

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to create food');
        }
    }

    /**
     * Update food (only for food creator or admin)
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            $food = Food::find($id);

            if (!$food) {
                return $this->notFoundResponse('Food not found');
            }

            // Check if user can update this food
            if ($food->created_by !== $user->id) {
                return $this->forbiddenResponse('You can only update foods you created');
            }

            $validatedData = $this->validateRequest($request, [
                'name' => 'sometimes|required|string|max:255',
                'brand' => 'sometimes|nullable|string|max:100',
                'category' => 'sometimes|nullable|string|max:100',
                'subcategory' => 'sometimes|nullable|string|max:100',
                'calories_per_100g' => 'sometimes|required|numeric|min:0|max:9999',
                'protein_per_100g' => 'sometimes|numeric|min:0|max:100',
                'carbs_per_100g' => 'sometimes|numeric|min:0|max:100',
                'fat_per_100g' => 'sometimes|numeric|min:0|max:100',
                'fiber_per_100g' => 'sometimes|numeric|min:0|max:100',
                'sugar_per_100g' => 'sometimes|numeric|min:0|max:100',
                'sodium_per_100g' => 'sometimes|numeric|min:0|max:10000',
                'common_serving_size' => 'sometimes|nullable|numeric|min:0',
                'common_serving_unit' => 'sometimes|nullable|string|max:20',
                'barcode' => 'sometimes|nullable|string|max:50',
                'additional_nutrients' => 'sometimes|nullable|array',
            ]);

            $food->update($validatedData);

            return $this->success($food->fresh(), 'Food updated successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to update food', 500, $e->getMessage());
        }
    }

    /**
     * Get autocomplete suggestions
     */
    public function autocomplete(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'query' => 'required|string|min:1|max:50'
            ]);

            $query = $request->get('query');
            $cacheKey = "food_autocomplete_" . md5($query);
            
            $suggestions = Cache::remember($cacheKey, 300, function () use ($query) {
                return Food::where('name', 'ILIKE', "%{$query}%")
                          ->where('is_verified', true)
                          ->orderByDesc('usage_count')
                          ->limit(10)
                          ->pluck('name')
                          ->toArray();
            });

            return $this->success($suggestions, 'Autocomplete suggestions retrieved');

        } catch (\Exception $e) {
            return $this->error('Failed to get autocomplete suggestions', 500, $e->getMessage());
        }
    }

    /**
     * Get food details with nutrition calculation
     */
    public function getNutritionDetails(Request $request, string $id): JsonResponse
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:0',
                'unit' => 'required|string|max:20'
            ]);

            $food = Food::find($id);
            if (!$food) {
                return $this->error('Food not found', 404);
            }

            // Calculate nutrition for the specified amount
            $nutritionPer100g = [
                'calories_per_100g' => $food->calories_per_100g,
                'protein_per_100g' => $food->protein_per_100g,
                'carbohydrates_per_100g' => $food->carbohydrates_per_100g,
                'fat_per_100g' => $food->fat_per_100g,
                'fiber_per_100g' => $food->fiber_per_100g,
                'sugar_per_100g' => $food->sugar_per_100g,
                'sodium_per_100g' => $food->sodium_per_100g
            ];

            $calculatedNutrition = $this->nutritionalCalculationService->calculateNutrition(
                $nutritionPer100g,
                $request->get('amount'),
                $request->get('unit'),
                $food->name
            );

            $result = [
                'food' => $food,
                'calculated_nutrition' => $calculatedNutrition,
                'macro_distribution' => $food->getMacroDistribution()
            ];

            return $this->success($result, 'Nutrition details calculated successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to calculate nutrition', 500, $e->getMessage());
        }
    }

    /**
     * Get user's favorite foods
     */
    public function favorites(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $favorites = $user->favoriteFoods()
                            ->with(['creator'])
                            ->orderBy('user_favorite_foods.created_at', 'desc')
                            ->paginate(20);

            return $this->success($favorites, 'Favorite foods retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to get favorite foods', 500, $e->getMessage());
        }
    }

    /**
     * Add food to favorites
     */
    public function addToFavorites(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $food = Food::find($id);
            
            if (!$food) {
                return $this->error('Food not found', 404);
            }

            if (!$user->favoriteFoods()->where('food_id', $id)->exists()) {
                $user->favoriteFoods()->attach($id);
            }

            return $this->success(null, 'Food added to favorites');

        } catch (\Exception $e) {
            return $this->error('Failed to add food to favorites', 500, $e->getMessage());
        }
    }

    /**
     * Remove food from favorites
     */
    public function removeFromFavorites(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $user->favoriteFoods()->detach($id);

            return $this->success(null, 'Food removed from favorites');

        } catch (\Exception $e) {
            return $this->error('Failed to remove food from favorites', 500, $e->getMessage());
        }
    }

    /**
     * Get recently used foods
     */
    public function recent(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $recentFoods = Food::recentForUser($user->id, 30)
                              ->limit(20)
                              ->get();

            return $this->success($recentFoods, 'Recent foods retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to get recent foods', 500, $e->getMessage());
        }
    }

    /**
     * Import food from external API and cache locally
     */
    public function importExternal(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'external_id' => 'required|string',
                'source' => 'required|in:usda,edamam'
            ]);

            $food = $this->foodDatabaseService->importAndCacheFood(
                $request->get('external_id'),
                $request->get('source')
            );

            if (!$food) {
                return $this->error('Failed to import food', 400);
            }

            return $this->success($food, 'Food imported successfully', 201);

        } catch (\Exception $e) {
            return $this->error('Failed to import food', 500, $e->getMessage());
        }
    }

    /**
     * Compare nutritional values between foods
     */
    public function compare(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'food_ids' => 'required|array|min:2|max:5',
                'food_ids.*' => 'integer|exists:foods,id',
                'nutrients' => 'sometimes|array'
            ]);

            $foods = Food::whereIn('id', $request->get('food_ids'))->get();
            
            if ($foods->count() < 2) {
                return $this->error('At least 2 foods required for comparison', 400);
            }

            $comparison = [];
            $nutrients = $request->get('nutrients', ['calories', 'protein', 'carbs', 'fat', 'fiber', 'sugar', 'sodium']);

            for ($i = 0; $i < $foods->count() - 1; $i++) {
                $food1 = $foods[$i];
                $food2 = $foods[$i + 1];
                
                $food1Data = [
                    'calories' => $food1->calories_per_100g,
                    'protein' => $food1->protein_per_100g,
                    'carbs' => $food1->carbohydrates_per_100g,
                    'fat' => $food1->fat_per_100g,
                    'fiber' => $food1->fiber_per_100g,
                    'sugar' => $food1->sugar_per_100g,
                    'sodium' => $food1->sodium_per_100g
                ];
                
                $food2Data = [
                    'calories' => $food2->calories_per_100g,
                    'protein' => $food2->protein_per_100g,
                    'carbs' => $food2->carbohydrates_per_100g,
                    'fat' => $food2->fat_per_100g,
                    'fiber' => $food2->fiber_per_100g,
                    'sugar' => $food2->sugar_per_100g,
                    'sodium' => $food2->sodium_per_100g
                ];

                $comparison[] = [
                    'food1' => $food1,
                    'food2' => $food2,
                    'comparison' => $this->nutritionalCalculationService->compareNutrition(
                        $food1Data,
                        $food2Data,
                        $nutrients
                    )
                ];
            }

            return $this->success($comparison, 'Food comparison completed');

        } catch (\Exception $e) {
            return $this->error('Failed to compare foods', 500, $e->getMessage());
        }
    }

    /**
     * Get barcode lookup (placeholder for future implementation)
     */
    public function lookupBarcode(Request $request, string $barcode): JsonResponse
    {
        try {
            $food = Food::where('barcode', $barcode)->first();
            
            if ($food) {
                return $this->success($food, 'Food found by barcode');
            }

            // TODO: Implement external barcode APIs (OpenFoodFacts, UPC Database, etc.)
            
            return $this->error('Food not found for this barcode', 404);

        } catch (\Exception $e) {
            return $this->error('Failed to lookup barcode', 500, $e->getMessage());
        }
    }
}