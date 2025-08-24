<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\MealPlan;
use App\Models\MealPlanMeal;
use App\Models\UserDietaryPreference;
use App\Services\MealPlanGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class MealPlanController extends BaseApiController
{
    private MealPlanGeneratorService $mealPlanGenerator;

    public function __construct(MealPlanGeneratorService $mealPlanGenerator)
    {
        $this->mealPlanGenerator = $mealPlanGenerator;
    }

    /**
     * Get user's meal plans
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $validatedData = $request->validate([
                'status' => 'sometimes|string|in:' . implode(',', MealPlan::STATUSES),
                'per_page' => 'sometimes|integer|min:1|max:50'
            ]);

            $query = MealPlan::forUser($user->id)
                            ->with(['meals' => function($q) {
                                $q->orderBy('date')->orderBy('meal_type');
                            }])
                            ->orderByDesc('created_at');

            if (!empty($validatedData['status'])) {
                $query->where('status', $validatedData['status']);
            }

            $mealPlans = $query->paginate($validatedData['per_page'] ?? 20);

            // Add progress information
            $mealPlans->getCollection()->transform(function ($mealPlan) {
                $mealPlan->progress_percentage = $mealPlan->getProgressPercentage();
                $mealPlan->days_remaining = $mealPlan->getDaysRemaining();
                $mealPlan->is_current = $mealPlan->isCurrent();
                return $mealPlan;
            });

            return $this->success([
                'meal_plans' => $mealPlans->items(),
                'pagination' => [
                    'current_page' => $mealPlans->currentPage(),
                    'total_pages' => $mealPlans->lastPage(),
                    'per_page' => $mealPlans->perPage(),
                    'total' => $mealPlans->total()
                ]
            ], 'Meal plans retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve meal plans', 500, $e->getMessage());
        }
    }

    /**
     * Get specific meal plan
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $mealPlan = MealPlan::forUser($user->id)
                              ->with([
                                  'meals.recipe.ingredients.food',
                                  'meals.substitutedWithRecipe'
                              ])
                              ->findOrFail($id);

            // Add calculated fields
            $mealPlan->progress_percentage = $mealPlan->getProgressPercentage();
            $mealPlan->days_remaining = $mealPlan->getDaysRemaining();
            $mealPlan->is_current = $mealPlan->isCurrent();
            $mealPlan->adherence_score = $mealPlan->calculateAdherenceScore();
            $mealPlan->actual_nutrition = $mealPlan->calculateActualNutrition();
            $mealPlan->target_macro_distribution = $mealPlan->getTargetMacroDistribution();
            $mealPlan->actual_macro_distribution = $mealPlan->getActualMacroDistribution();

            return $this->success($mealPlan, 'Meal plan retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Meal plan not found', 404, $e->getMessage());
        }
    }

    /**
     * Generate new meal plan
     */
    public function generate(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'sometimes|string|max:1000',
                'start_date' => 'required|date|after_or_equal:today',
                'duration_days' => 'required|integer|min:1|max:90',
                'target_calories' => 'sometimes|numeric|min:800|max:5000',
                'target_protein' => 'sometimes|numeric|min:0|max:500',
                'target_carbs' => 'sometimes|numeric|min:0|max:1000',
                'target_fat' => 'sometimes|numeric|min:0|max:300',
                'dietary_preferences' => 'sometimes|array',
                'dietary_preferences.*' => 'string',
                'allergen_restrictions' => 'sometimes|array',
                'allergen_restrictions.*' => 'string',
                'cuisine_preferences' => 'sometimes|array',
                'cuisine_preferences.*' => 'string',
                'budget_limit' => 'sometimes|numeric|min:0',
                'max_cooking_time' => 'sometimes|integer|min:5|max:300',
                'difficulty_level' => 'sometimes|integer|min:1|max:4',
                'meal_types' => 'sometimes|array|min:1',
                'meal_types.*' => 'string|in:breakfast,lunch,dinner,snack_morning,snack_afternoon,snack_evening',
                'include_meal_prep' => 'sometimes|boolean',
                'prioritize_seasonal' => 'sometimes|boolean',
                'avoid_repetition' => 'sometimes|boolean',
                'max_reuse_days' => 'sometimes|integer|min:1|max:14',
                'default_servings' => 'sometimes|numeric|min:0.5|max:10',
                'generation_preferences' => 'sometimes|array'
            ]);

            // Set defaults if not provided
            $validatedData['meal_types'] = $validatedData['meal_types'] ?? ['breakfast', 'lunch', 'dinner'];
            
            $mealPlan = $this->mealPlanGenerator->generateMealPlan($user, $validatedData);

            return $this->success($mealPlan, 'Meal plan generated successfully', 201);

        } catch (\Exception $e) {
            return $this->error('Failed to generate meal plan', 500, $e->getMessage());
        }
    }

    /**
     * Regenerate meal plan with feedback
     */
    public function regenerate(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $mealPlan = MealPlan::forUser($user->id)->findOrFail($id);

            $validatedData = $request->validate([
                'feedback' => 'required|array',
                'feedback.disliked_recipes' => 'sometimes|array',
                'feedback.disliked_recipes.*' => 'integer|exists:recipes,id',
                'feedback.preferred_cuisines' => 'sometimes|array',
                'feedback.preferred_cuisines.*' => 'string',
                'feedback.cooking_time_preference' => 'sometimes|string|in:shorter,longer,same',
                'feedback.portion_feedback' => 'sometimes|string|in:too_small,too_large,just_right',
                'feedback.variety_feedback' => 'sometimes|string|in:more_variety,less_variety,good',
                'feedback.meal_types_to_regenerate' => 'sometimes|array',
                'feedback.meal_types_to_regenerate.*' => 'string|in:breakfast,lunch,dinner,snack_morning,snack_afternoon,snack_evening',
                'feedback.dates_to_regenerate' => 'sometimes|array',
                'feedback.dates_to_regenerate.*' => 'date',
                'feedback.notes' => 'sometimes|string|max:1000'
            ]);

            $updatedMealPlan = $this->mealPlanGenerator->regenerateWithFeedback($mealPlan, $validatedData['feedback']);

            return $this->success($updatedMealPlan, 'Meal plan regenerated with feedback');

        } catch (\Exception $e) {
            return $this->error('Failed to regenerate meal plan', 500, $e->getMessage());
        }
    }

    /**
     * Get alternative meal suggestions
     */
    public function getAlternatives(Request $request, string $mealId): JsonResponse
    {
        try {
            $user = $request->user();
            $validatedData = $request->validate([
                'count' => 'sometimes|integer|min:1|max:20'
            ]);

            $meal = MealPlanMeal::whereHas('mealPlan', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })->findOrFail($mealId);

            $alternatives = $this->mealPlanGenerator->generateAlternatives(
                $meal, 
                $validatedData['count'] ?? 5
            );

            return $this->success([
                'alternatives' => $alternatives,
                'current_meal' => $meal->load('recipe')
            ], 'Alternative meal suggestions retrieved');

        } catch (\Exception $e) {
            return $this->error('Failed to get alternatives', 500, $e->getMessage());
        }
    }

    /**
     * Substitute a meal
     */
    public function substituteMeal(Request $request, string $mealId): JsonResponse
    {
        try {
            $user = $request->user();
            $validatedData = $request->validate([
                'new_recipe_id' => 'required|integer|exists:recipes,id',
                'reason' => 'sometimes|array',
                'reason.category' => 'sometimes|string|in:dislike,allergy,time,difficulty,ingredients',
                'reason.notes' => 'sometimes|string|max:500'
            ]);

            $meal = MealPlanMeal::whereHas('mealPlan', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })->findOrFail($mealId);

            return DB::transaction(function () use ($meal, $validatedData) {
                $meal->substitute($validatedData['new_recipe_id'], $validatedData['reason'] ?? null);
                
                // Update meal plan actual nutrition
                $meal->mealPlan->updateActualNutrition();
                
                return $this->success($meal->fresh(['recipe', 'substitutedWithRecipe']), 'Meal substituted successfully');
            });

        } catch (\Exception $e) {
            return $this->error('Failed to substitute meal', 500, $e->getMessage());
        }
    }

    /**
     * Mark meal as completed
     */
    public function completeMeal(Request $request, string $mealId): JsonResponse
    {
        try {
            $user = $request->user();
            $validatedData = $request->validate([
                'notes' => 'sometimes|string|max:500',
                'rating' => 'sometimes|integer|min:1|max:5',
                'feedback' => 'sometimes|string|max:1000'
            ]);

            $meal = MealPlanMeal::whereHas('mealPlan', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })->findOrFail($mealId);

            $meal->markCompleted(
                $validatedData['notes'] ?? null,
                $validatedData['rating'] ?? null,
                $validatedData['feedback'] ?? null
            );

            // Update meal plan statistics
            $meal->mealPlan->updateActualNutrition();
            $meal->mealPlan->calculateAdherenceScore();

            return $this->success($meal->fresh(), 'Meal marked as completed');

        } catch (\Exception $e) {
            return $this->error('Failed to complete meal', 500, $e->getMessage());
        }
    }

    /**
     * Skip a meal
     */
    public function skipMeal(Request $request, string $mealId): JsonResponse
    {
        try {
            $user = $request->user();
            $validatedData = $request->validate([
                'reason' => 'sometimes|string|max:500'
            ]);

            $meal = MealPlanMeal::whereHas('mealPlan', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })->findOrFail($mealId);

            $meal->markSkipped($validatedData['reason'] ?? null);

            // Update adherence score
            $meal->mealPlan->calculateAdherenceScore();

            return $this->success($meal->fresh(), 'Meal marked as skipped');

        } catch (\Exception $e) {
            return $this->error('Failed to skip meal', 500, $e->getMessage());
        }
    }

    /**
     * Get shopping list for meal plan
     */
    public function getShoppingList(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $mealPlan = MealPlan::forUser($user->id)->findOrFail($id);

            $validatedData = $request->validate([
                'date_range_start' => 'sometimes|date',
                'date_range_end' => 'sometimes|date|after_or_equal:date_range_start',
                'group_by_category' => 'sometimes|boolean',
                'exclude_completed' => 'sometimes|boolean'
            ]);

            $shoppingList = $mealPlan->generateShoppingList();

            // Apply filters if specified
            if ($validatedData['date_range_start'] ?? false) {
                // Filter shopping list by date range - would need additional implementation
            }

            if ($validatedData['group_by_category'] ?? false) {
                // Group ingredients by category
                $categorized = [];
                foreach ($shoppingList as $ingredient => $details) {
                    $category = $details['food']->category ?? 'Other';
                    $categorized[$category][] = array_merge(['name' => $ingredient], $details);
                }
                $shoppingList = $categorized;
            }

            return $this->success([
                'shopping_list' => $shoppingList,
                'meal_plan' => $mealPlan->only(['id', 'name', 'start_date', 'end_date'])
            ], 'Shopping list generated successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to generate shopping list', 500, $e->getMessage());
        }
    }

    /**
     * Get meal prep suggestions
     */
    public function getMealPrepSuggestions(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $mealPlan = MealPlan::forUser($user->id)->findOrFail($id);

            $suggestions = $mealPlan->getMealPrepSuggestions();

            return $this->success([
                'meal_prep_suggestions' => $suggestions,
                'meal_plan' => $mealPlan->only(['id', 'name', 'include_meal_prep'])
            ], 'Meal prep suggestions retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to get meal prep suggestions', 500, $e->getMessage());
        }
    }

    /**
     * Get meal plan analytics
     */
    public function getAnalytics(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $mealPlan = MealPlan::forUser($user->id)->findOrFail($id);

            $analytics = [
                'adherence_score' => $mealPlan->calculateAdherenceScore(),
                'nutritional_accuracy' => $this->calculateNutritionalAccuracy($mealPlan),
                'variety_score' => $this->calculateVarietyScore($mealPlan),
                'completion_stats' => $this->getCompletionStats($mealPlan),
                'favorite_meals' => $this->getFavoriteMeals($mealPlan),
                'cuisine_distribution' => $this->getCuisineDistribution($mealPlan),
                'cooking_time_analysis' => $this->getCookingTimeAnalysis($mealPlan),
                'daily_nutrition_trends' => $this->getDailyNutritionTrends($mealPlan)
            ];

            return $this->success($analytics, 'Meal plan analytics retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to get analytics', 500, $e->getMessage());
        }
    }

    /**
     * Update user dietary preferences
     */
    public function updateDietaryPreferences(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $validatedData = $request->validate([
                'dietary_restrictions' => 'sometimes|array',
                'allergens' => 'sometimes|array',
                'cuisine_preferences' => 'sometimes|array',
                'disliked_ingredients' => 'sometimes|array',
                'preferred_ingredients' => 'sometimes|array',
                'max_cooking_time' => 'sometimes|integer|min:5|max:300',
                'preferred_difficulty_max' => 'sometimes|integer|min:1|max:4',
                'target_calories_per_day' => 'sometimes|numeric|min:800|max:5000',
                'macro_targets' => 'sometimes|array',
                'prioritize_protein' => 'sometimes|boolean',
                'low_sodium' => 'sometimes|boolean',
                'low_sugar' => 'sometimes|boolean',
                'high_fiber' => 'sometimes|boolean',
                'meal_frequency' => 'sometimes|array',
                'meal_prep_friendly' => 'sometimes|boolean',
                'budget_per_meal' => 'sometimes|numeric|min:0',
                'seasonal_preference' => 'sometimes|boolean',
                'region' => 'sometimes|string|max:100',
                'equipment_available' => 'sometimes|array',
                'shopping_preferences' => 'sometimes|array'
            ]);

            $preferences = UserDietaryPreference::updateOrCreate(
                ['user_id' => $user->id],
                $validatedData
            );

            return $this->success($preferences, 'Dietary preferences updated successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to update dietary preferences', 500, $e->getMessage());
        }
    }

    // Helper methods for analytics
    private function calculateNutritionalAccuracy(MealPlan $mealPlan): array
    {
        $actualNutrition = $mealPlan->calculateActualNutrition();
        
        $accuracy = [];
        $targets = [
            'calories' => $mealPlan->target_calories_per_day,
            'protein' => $mealPlan->target_protein_per_day,
            'carbs' => $mealPlan->target_carbs_per_day,
            'fat' => $mealPlan->target_fat_per_day
        ];

        foreach ($targets as $nutrient => $target) {
            if ($target > 0) {
                $actual = $actualNutrition[$nutrient];
                $accuracy[$nutrient] = [
                    'target' => $target,
                    'actual' => $actual,
                    'accuracy_percentage' => min(100, ($actual / $target) * 100)
                ];
            }
        }

        return $accuracy;
    }

    private function calculateVarietyScore(MealPlan $mealPlan): float
    {
        $meals = $mealPlan->meals()->with('recipe')->get();
        $uniqueRecipes = $meals->pluck('recipe_id')->unique()->count();
        $totalMeals = $meals->count();

        return $totalMeals > 0 ? ($uniqueRecipes / $totalMeals) * 100 : 0;
    }

    private function getCompletionStats(MealPlan $mealPlan): array
    {
        $meals = $mealPlan->meals;
        $total = $meals->count();
        
        return [
            'total_meals' => $total,
            'completed' => $meals->where('status', MealPlanMeal::STATUS_COMPLETED)->count(),
            'skipped' => $meals->where('status', MealPlanMeal::STATUS_SKIPPED)->count(),
            'substituted' => $meals->where('status', MealPlanMeal::STATUS_SUBSTITUTED)->count(),
            'planned' => $meals->where('status', MealPlanMeal::STATUS_PLANNED)->count()
        ];
    }

    private function getFavoriteMeals(MealPlan $mealPlan): Collection
    {
        return $mealPlan->meals()
            ->with('recipe')
            ->where('user_rating', '>=', 4)
            ->orderByDesc('user_rating')
            ->limit(5)
            ->get();
    }

    private function getCuisineDistribution(MealPlan $mealPlan): array
    {
        $meals = $mealPlan->meals()->with('recipe')->get();
        $cuisines = $meals->pluck('recipe.cuisine_type')->filter()->countBy();
        
        return $cuisines->toArray();
    }

    private function getCookingTimeAnalysis(MealPlan $mealPlan): array
    {
        $meals = $mealPlan->meals()->with('recipe')->get();
        $cookingTimes = $meals->pluck('recipe.total_time_minutes')->filter();
        
        return [
            'average_cooking_time' => $cookingTimes->avg(),
            'min_cooking_time' => $cookingTimes->min(),
            'max_cooking_time' => $cookingTimes->max(),
            'time_distribution' => [
                'quick' => $cookingTimes->filter(fn($time) => $time <= 30)->count(),
                'moderate' => $cookingTimes->filter(fn($time) => $time > 30 && $time <= 60)->count(),
                'long' => $cookingTimes->filter(fn($time) => $time > 60)->count()
            ]
        ];
    }

    private function getDailyNutritionTrends(MealPlan $mealPlan): array
    {
        $meals = $mealPlan->meals()->with('recipe')->get();
        $mealsByDate = $meals->groupBy('date');
        
        $trends = [];
        foreach ($mealsByDate as $date => $dayMeals) {
            $dailyTotals = [
                'calories' => $dayMeals->sum('planned_calories'),
                'protein' => $dayMeals->sum('planned_protein'),
                'carbs' => $dayMeals->sum('planned_carbs'),
                'fat' => $dayMeals->sum('planned_fat')
            ];
            
            $trends[$date] = $dailyTotals;
        }
        
        return $trends;
    }
}