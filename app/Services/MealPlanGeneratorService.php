<?php

namespace App\Services;

use App\Models\User;
use App\Models\Recipe;
use App\Models\MealPlan;
use App\Models\MealPlanMeal;
use App\Models\UserDietaryPreference;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MealPlanGeneratorService
{
    private NutritionalCalculationService $nutritionalCalculationService;
    
    public function __construct(NutritionalCalculationService $nutritionalCalculationService)
    {
        $this->nutritionalCalculationService = $nutritionalCalculationService;
    }

    /**
     * Generate a meal plan for a user
     */
    public function generateMealPlan(User $user, array $parameters): MealPlan
    {
        return DB::transaction(function () use ($user, $parameters) {
            // Create the meal plan
            $mealPlan = $this->createMealPlan($user, $parameters);
            
            // Generate meals for each day
            $this->generateMeals($mealPlan, $parameters);
            
            // Optimize the meal plan
            $this->optimizeMealPlan($mealPlan);
            
            // Update meal plan status
            $mealPlan->update(['status' => MealPlan::STATUS_ACTIVE]);
            
            return $mealPlan->fresh(['meals.recipe']);
        });
    }

    /**
     * Regenerate meals based on user feedback
     */
    public function regenerateWithFeedback(MealPlan $mealPlan, array $feedback): MealPlan
    {
        return DB::transaction(function () use ($mealPlan, $feedback) {
            // Update feedback data
            $mealPlan->update([
                'feedback_data' => array_merge($mealPlan->feedback_data ?? [], $feedback)
            ]);
            
            // Regenerate specific days or meal types based on feedback
            $this->processUserFeedback($mealPlan, $feedback);
            
            // Re-optimize
            $this->optimizeMealPlan($mealPlan);
            
            return $mealPlan->fresh(['meals.recipe']);
        });
    }

    /**
     * Generate alternative meal suggestions
     */
    public function generateAlternatives(MealPlanMeal $meal, int $count = 5): Collection
    {
        $preferences = $meal->mealPlan->user->dietaryPreferences;
        
        $alternatives = $this->findSuitableRecipes(
            $preferences,
            $meal->meal_type,
            $meal->date,
            $meal->mealPlan,
            $count + 5 // Get extra to filter out current recipe
        );
        
        // Remove current recipe if present
        $alternatives = $alternatives->filter(function ($recipe) use ($meal) {
            return $recipe->id !== $meal->recipe_id;
        });
        
        // Score and sort alternatives
        $scoredAlternatives = $alternatives->map(function ($recipe) use ($preferences, $meal) {
            $score = $this->calculateRecipeScore($recipe, $preferences, $meal);
            $recipe->suggestion_score = $score;
            return $recipe;
        })->sortByDesc('suggestion_score');
        
        return $scoredAlternatives->take($count)->values();
    }

    /**
     * Create base meal plan
     */
    private function createMealPlan(User $user, array $parameters): MealPlan
    {
        $startDate = Carbon::parse($parameters['start_date']);
        $endDate = $startDate->copy()->addDays($parameters['duration_days'] - 1);
        
        $userPreferences = $user->dietaryPreferences ?? new UserDietaryPreference();
        
        return MealPlan::create([
            'user_id' => $user->id,
            'name' => $parameters['name'] ?? "Meal Plan for {$startDate->format('M d, Y')}",
            'description' => $parameters['description'] ?? null,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'duration_days' => $parameters['duration_days'],
            'target_calories_per_day' => $parameters['target_calories'] ?? $userPreferences->target_calories_per_day ?? 2000,
            'target_protein_per_day' => $parameters['target_protein'] ?? $this->calculateProteinTarget($parameters['target_calories'] ?? 2000),
            'target_carbs_per_day' => $parameters['target_carbs'] ?? $this->calculateCarbsTarget($parameters['target_calories'] ?? 2000),
            'target_fat_per_day' => $parameters['target_fat'] ?? $this->calculateFatTarget($parameters['target_calories'] ?? 2000),
            'dietary_preferences' => $parameters['dietary_preferences'] ?? $userPreferences->dietary_restrictions ?? [],
            'allergen_restrictions' => $parameters['allergen_restrictions'] ?? $userPreferences->allergens ?? [],
            'cuisine_preferences' => $parameters['cuisine_preferences'] ?? $userPreferences->cuisine_preferences ?? [],
            'budget_limit_per_day' => $parameters['budget_limit'] ?? $userPreferences->budget_per_meal,
            'max_cooking_time_minutes' => $parameters['max_cooking_time'] ?? $userPreferences->max_cooking_time,
            'preferred_difficulty_level' => $parameters['difficulty_level'] ?? $userPreferences->preferred_difficulty_max ?? 3,
            'meal_types' => $parameters['meal_types'] ?? ['breakfast', 'lunch', 'dinner'],
            'include_meal_prep' => $parameters['include_meal_prep'] ?? $userPreferences->meal_prep_friendly ?? false,
            'prioritize_seasonal' => $parameters['prioritize_seasonal'] ?? $userPreferences->seasonal_preference ?? false,
            'avoid_repetition' => $parameters['avoid_repetition'] ?? true,
            'max_recipe_reuse_days' => $parameters['max_reuse_days'] ?? 7,
            'generation_preferences' => $parameters['generation_preferences'] ?? [],
            'status' => MealPlan::STATUS_GENERATING
        ]);
    }

    /**
     * Generate meals for the meal plan
     */
    private function generateMeals(MealPlan $mealPlan, array $parameters): void
    {
        $user = $mealPlan->user;
        $preferences = $user->dietaryPreferences;
        $usedRecipes = collect();
        
        for ($day = 0; $day < $mealPlan->duration_days; $day++) {
            $currentDate = $mealPlan->start_date->copy()->addDays($day);
            
            foreach ($mealPlan->meal_types as $mealType) {
                $recipe = $this->selectRecipeForMeal(
                    $preferences,
                    $mealType,
                    $currentDate,
                    $mealPlan,
                    $usedRecipes
                );
                
                if ($recipe) {
                    $meal = $this->createMealPlanMeal(
                        $mealPlan,
                        $recipe,
                        $currentDate,
                        $mealType,
                        $parameters
                    );
                    
                    // Track used recipes for variety
                    $usedRecipes->push([
                        'recipe_id' => $recipe->id,
                        'date' => $currentDate,
                        'meal_type' => $mealType
                    ]);
                }
            }
        }
    }

    /**
     * Select appropriate recipe for a meal
     */
    private function selectRecipeForMeal(
        ?UserDietaryPreference $preferences,
        string $mealType,
        Carbon $date,
        MealPlan $mealPlan,
        Collection $usedRecipes
    ): ?Recipe {
        // Get suitable recipes
        $candidates = $this->findSuitableRecipes($preferences, $mealType, $date, $mealPlan);
        
        if ($candidates->isEmpty()) {
            return null;
        }
        
        // Apply variety constraints
        if ($mealPlan->avoid_repetition) {
            $candidates = $this->filterForVariety($candidates, $usedRecipes, $mealPlan->max_recipe_reuse_days);
        }
        
        // Score and select best recipe
        $scoredCandidates = $candidates->map(function ($recipe) use ($preferences, $mealType, $date) {
            $score = $this->calculateRecipeScore($recipe, $preferences, null, $mealType, $date);
            $recipe->selection_score = $score;
            return $recipe;
        });
        
        // Add some randomness to avoid always picking the same "best" recipes
        $topCandidates = $scoredCandidates->sortByDesc('selection_score')->take(5);
        
        return $topCandidates->random();
    }

    /**
     * Find suitable recipes based on preferences and constraints
     */
    private function findSuitableRecipes(
        ?UserDietaryPreference $preferences,
        string $mealType,
        Carbon $date,
        MealPlan $mealPlan,
        int $limit = 20
    ): Collection {
        $query = Recipe::query()
            ->public()
            ->verified()
            ->with(['ingredients.food']);
        
        // Filter by meal category
        $mealCategory = $this->mapMealTypeToCategory($mealType);
        if ($mealCategory) {
            $query->byMealCategory($mealCategory);
        }
        
        // Apply dietary restrictions
        if ($preferences && $preferences->dietary_restrictions) {
            foreach ($preferences->dietary_restrictions as $restriction) {
                $query->byDietaryPreference($restriction);
            }
        }
        
        // Apply allergen restrictions
        if ($preferences && $preferences->allergens) {
            $query->allergenFree($preferences->allergens);
        }
        
        // Apply cooking time constraint
        if ($preferences && $preferences->max_cooking_time) {
            $query->maxCookingTime($preferences->max_cooking_time);
        }
        
        // Apply difficulty constraint
        if ($preferences && $preferences->preferred_difficulty_max) {
            $query->byDifficulty($preferences->preferred_difficulty_max);
        }
        
        // Seasonal preference
        if ($mealPlan->prioritize_seasonal) {
            $query = $this->applySeasonalFilter($query, $date);
        }
        
        // Cuisine preferences
        if ($mealPlan->cuisine_preferences) {
            $query->whereIn('cuisine_type', $mealPlan->cuisine_preferences);
        }
        
        // Get recipes with good ratings
        $query->minRating(3.0);
        
        return $query->limit($limit * 2)->get(); // Get more than needed for better variety
    }

    /**
     * Apply seasonal filtering to recipe query
     */
    private function applySeasonalFilter($query, Carbon $date)
    {
        $month = $date->month;
        $season = $this->getSeason($month);
        
        // This is a simplified seasonal logic - in production you'd have more sophisticated seasonal ingredient tracking
        $seasonalTags = [
            'spring' => ['fresh', 'light', 'vegetables', 'asparagus', 'peas', 'strawberries'],
            'summer' => ['grilled', 'salad', 'berries', 'tomatoes', 'corn', 'melon'],
            'fall' => ['roasted', 'pumpkin', 'apple', 'squash', 'warming', 'hearty'],
            'winter' => ['stew', 'soup', 'comfort', 'root vegetables', 'warming', 'hearty']
        ];
        
        $tags = $seasonalTags[$season] ?? [];
        
        if (!empty($tags)) {
            $query->where(function ($q) use ($tags) {
                foreach ($tags as $tag) {
                    $q->orWhereJsonContains('tags', $tag);
                }
            });
        }
        
        return $query;
    }

    /**
     * Get season based on month
     */
    private function getSeason(int $month): string
    {
        return match (true) {
            $month >= 3 && $month <= 5 => 'spring',
            $month >= 6 && $month <= 8 => 'summer',
            $month >= 9 && $month <= 11 => 'fall',
            default => 'winter'
        };
    }

    /**
     * Filter recipes for variety
     */
    private function filterForVariety(Collection $candidates, Collection $usedRecipes, int $reuseDelay): Collection
    {
        if ($usedRecipes->isEmpty()) {
            return $candidates;
        }
        
        $recentlyUsed = $usedRecipes->where('date', '>=', Carbon::now()->subDays($reuseDelay))
                                   ->pluck('recipe_id')
                                   ->unique();
        
        $filtered = $candidates->filter(function ($recipe) use ($recentlyUsed) {
            return !$recentlyUsed->contains($recipe->id);
        });
        
        // If filtering removed all options, return original candidates
        return $filtered->isEmpty() ? $candidates : $filtered;
    }

    /**
     * Calculate recipe score for selection
     */
    private function calculateRecipeScore(
        Recipe $recipe,
        ?UserDietaryPreference $preferences,
        ?MealPlanMeal $currentMeal = null,
        ?string $mealType = null,
        ?Carbon $date = null
    ): float {
        $score = 50.0; // Base score
        
        // User preference score
        if ($preferences) {
            $score += $preferences->getRecipeScore($recipe);
        }
        
        // Recipe rating influence
        if ($recipe->average_rating > 0) {
            $score += ($recipe->average_rating - 2.5) * 5;
        }
        
        // Popularity influence
        if ($recipe->total_ratings > 10) {
            $score += min(10, $recipe->total_ratings / 10);
        }
        
        // Meal type appropriateness
        if ($mealType) {
            $score += $this->getMealTypeAppropriatenessScore($recipe, $mealType);
        }
        
        // Seasonal bonus
        if ($date && $this->isSeasonallyAppropriate($recipe, $date)) {
            $score += 5;
        }
        
        // Nutritional balance (prefer recipes that help meet daily targets)
        if ($currentMeal && $currentMeal->mealPlan) {
            $score += $this->getNutritionalBalanceScore($recipe, $currentMeal);
        }
        
        return max(0, min(100, $score));
    }

    /**
     * Get meal type appropriateness score
     */
    private function getMealTypeAppropriatenessScore(Recipe $recipe, string $mealType): float
    {
        $appropriateness = [
            'breakfast' => ['breakfast', 'snack'],
            'lunch' => ['lunch', 'salad', 'soup', 'side_dish'],
            'dinner' => ['dinner', 'main'],
            'snack_morning' => ['snack', 'appetizer'],
            'snack_afternoon' => ['snack', 'appetizer', 'dessert'],
            'snack_evening' => ['snack', 'dessert']
        ];
        
        $appropriateCategories = $appropriateness[$mealType] ?? [];
        
        if (in_array($recipe->meal_category, $appropriateCategories)) {
            return 15;
        }
        
        // Secondary appropriateness
        if ($mealType === 'breakfast' && $recipe->total_time_minutes <= 30) {
            return 10;
        }
        
        if (str_contains($mealType, 'snack') && $recipe->calories_per_serving <= 300) {
            return 10;
        }
        
        return 0;
    }

    /**
     * Check if recipe is seasonally appropriate
     */
    private function isSeasonallyAppropriate(Recipe $recipe, Carbon $date): bool
    {
        $season = $this->getSeason($date->month);
        $tags = $recipe->tags ?? [];
        
        $seasonalTags = [
            'spring' => ['fresh', 'light', 'vegetables'],
            'summer' => ['grilled', 'salad', 'fresh'],
            'fall' => ['roasted', 'warming', 'hearty'],
            'winter' => ['stew', 'soup', 'comfort', 'warming']
        ];
        
        $seasonTags = $seasonalTags[$season] ?? [];
        
        return !empty(array_intersect($tags, $seasonTags));
    }

    /**
     * Get nutritional balance score
     */
    private function getNutritionalBalanceScore(Recipe $recipe, MealPlanMeal $meal): float
    {
        $mealPlan = $meal->mealPlan;
        $targetCalories = $mealPlan->target_calories_per_day / count($mealPlan->meal_types);
        
        $score = 0;
        
        // Prefer recipes that match target calories for meal
        $caloriesDiff = abs($recipe->calories_per_serving - $targetCalories);
        if ($caloriesDiff <= $targetCalories * 0.2) { // Within 20%
            $score += 10;
        } elseif ($caloriesDiff <= $targetCalories * 0.4) { // Within 40%
            $score += 5;
        }
        
        // Bonus for high protein if user prioritizes protein
        if ($meal->mealPlan->user->dietaryPreferences?->prioritize_protein && 
            $recipe->protein_per_serving > 20) {
            $score += 5;
        }
        
        return $score;
    }

    /**
     * Create meal plan meal
     */
    private function createMealPlanMeal(
        MealPlan $mealPlan,
        Recipe $recipe,
        Carbon $date,
        string $mealType,
        array $parameters
    ): MealPlanMeal {
        $servings = $parameters['default_servings'] ?? 1;
        
        // Calculate nutrition for this serving size
        $nutrition = $recipe->getServingsScaled($servings);
        
        // Determine if this should be meal prepped
        $isMealPrep = $mealPlan->include_meal_prep && 
                     $this->shouldBeMealPrepped($recipe, $mealType, $date);
        
        $prepDate = $isMealPrep ? $this->calculatePrepDate($date, $recipe) : null;
        
        return MealPlanMeal::create([
            'meal_plan_id' => $mealPlan->id,
            'recipe_id' => $recipe->id,
            'date' => $date,
            'meal_type' => $mealType,
            'servings' => $servings,
            'planned_calories' => $nutrition['calories_per_serving'],
            'planned_protein' => $nutrition['protein_per_serving'],
            'planned_carbs' => $nutrition['carbs_per_serving'],
            'planned_fat' => $nutrition['fat_per_serving'],
            'is_meal_prep' => $isMealPrep,
            'prep_date' => $prepDate,
            'estimated_prep_time' => $isMealPrep ? $recipe->prep_time_minutes + $recipe->cook_time_minutes : null,
            'status' => MealPlanMeal::STATUS_PLANNED
        ]);
    }

    /**
     * Optimize the meal plan
     */
    private function optimizeMealPlan(MealPlan $mealPlan): void
    {
        // Balance daily nutrition
        $this->balanceDailyNutrition($mealPlan);
        
        // Optimize meal prep scheduling
        if ($mealPlan->include_meal_prep) {
            $this->optimizeMealPrepScheduling($mealPlan);
        }
        
        // Ensure variety
        $this->ensureVariety($mealPlan);
    }

    /**
     * Balance daily nutrition across meals
     */
    private function balanceDailyNutrition(MealPlan $mealPlan): void
    {
        $meals = $mealPlan->meals()->with('recipe')->get();
        
        // Group meals by date
        $mealsByDate = $meals->groupBy('date');
        
        foreach ($mealsByDate as $date => $dayMeals) {
            $dailyTotals = $this->calculateDailyTotals($dayMeals);
            
            // Check if adjustments are needed
            if ($this->needsNutritionalAdjustment($dailyTotals, $mealPlan)) {
                $this->adjustDayMeals($dayMeals, $mealPlan, $dailyTotals);
            }
        }
    }

    /**
     * Calculate daily nutrition totals
     */
    private function calculateDailyTotals(Collection $dayMeals): array
    {
        $totals = [
            'calories' => 0,
            'protein' => 0,
            'carbs' => 0,
            'fat' => 0
        ];
        
        foreach ($dayMeals as $meal) {
            $totals['calories'] += $meal->planned_calories ?? 0;
            $totals['protein'] += $meal->planned_protein ?? 0;
            $totals['carbs'] += $meal->planned_carbs ?? 0;
            $totals['fat'] += $meal->planned_fat ?? 0;
        }
        
        return $totals;
    }

    /**
     * Check if nutritional adjustment is needed
     */
    private function needsNutritionalAdjustment(array $dailyTotals, MealPlan $mealPlan): bool
    {
        $tolerance = 0.15; // 15% tolerance
        
        $targets = [
            'calories' => $mealPlan->target_calories_per_day,
            'protein' => $mealPlan->target_protein_per_day,
            'carbs' => $mealPlan->target_carbs_per_day,
            'fat' => $mealPlan->target_fat_per_day
        ];
        
        foreach ($targets as $nutrient => $target) {
            if ($target > 0) {
                $actual = $dailyTotals[$nutrient];
                $difference = abs($actual - $target) / $target;
                
                if ($difference > $tolerance) {
                    return true;
                }
            }
        }
        
        return false;
    }

    // Helper methods for calculations
    private function calculateProteinTarget(float $calories): float
    {
        return ($calories * 0.20) / 4; // 20% of calories from protein
    }
    
    private function calculateCarbsTarget(float $calories): float
    {
        return ($calories * 0.50) / 4; // 50% of calories from carbs
    }
    
    private function calculateFatTarget(float $calories): float
    {
        return ($calories * 0.30) / 9; // 30% of calories from fat
    }
    
    private function mapMealTypeToCategory(string $mealType): ?string
    {
        return match($mealType) {
            'breakfast' => 'breakfast',
            'lunch' => 'lunch',
            'dinner' => 'dinner',
            'snack_morning', 'snack_afternoon', 'snack_evening' => 'snack',
            default => null
        };
    }
    
    private function shouldBeMealPrepped(Recipe $recipe, string $mealType, Carbon $date): bool
    {
        // Meal prep logic - recipes that store well and save time
        return $recipe->storage_instructions !== null && 
               $recipe->total_time_minutes > 30 &&
               !in_array($mealType, ['snack_morning', 'snack_afternoon', 'snack_evening']);
    }
    
    private function calculatePrepDate(Carbon $mealDate, Recipe $recipe): Carbon
    {
        // Default to 1-2 days before consumption
        $daysBeforeMax = $recipe->storage_instructions ? 2 : 1;
        return $mealDate->copy()->subDays(rand(1, $daysBeforeMax));
    }
    
    private function processUserFeedback(MealPlan $mealPlan, array $feedback): void
    {
        // Process feedback and regenerate specific meals
        // This would be implemented based on specific feedback structure
        Log::info('Processing user feedback for meal plan regeneration', ['feedback' => $feedback]);
    }
    
    private function adjustDayMeals(Collection $dayMeals, MealPlan $mealPlan, array $dailyTotals): void
    {
        // Implement meal adjustment logic
        // This could involve portion adjustments or recipe substitutions
        Log::info('Adjusting meals for nutritional balance', ['totals' => $dailyTotals]);
    }
    
    private function optimizeMealPrepScheduling(MealPlan $mealPlan): void
    {
        // Optimize meal prep dates to minimize prep sessions
        $mealPrepMeals = $mealPlan->meals()->where('is_meal_prep', true)->get();
        
        // Group by prep date and optimize
        $prepGroups = $mealPrepMeals->groupBy('prep_date');
        
        foreach ($prepGroups as $prepDate => $meals) {
            if ($meals->count() > 1) {
                // Optimize prep order and timing
                $this->optimizePrepOrder($meals);
            }
        }
    }
    
    private function optimizePrepOrder(Collection $meals): void
    {
        // Sort meals by prep time and ingredient similarity for efficiency
        Log::info('Optimizing meal prep order for efficiency', ['meal_count' => $meals->count()]);
    }
    
    private function ensureVariety(MealPlan $mealPlan): void
    {
        // Check for excessive repetition and make substitutions if needed
        $meals = $mealPlan->meals()->with('recipe')->get();
        $recipeFrequency = $meals->countBy('recipe_id');
        
        foreach ($recipeFrequency as $recipeId => $frequency) {
            if ($frequency > $mealPlan->max_recipe_reuse_days) {
                // Find alternative recipes for some occurrences
                $this->substituteExcessiveRecipes($mealPlan, $recipeId, $frequency);
            }
        }
    }
    
    private function substituteExcessiveRecipes(MealPlan $mealPlan, int $recipeId, int $frequency): void
    {
        // Implement recipe substitution logic
        Log::info('Substituting excessive recipe occurrences', [
            'recipe_id' => $recipeId, 
            'frequency' => $frequency
        ]);
    }
}