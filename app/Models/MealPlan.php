<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class MealPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'start_date',
        'end_date',
        'duration_days',
        'target_calories_per_day',
        'target_protein_per_day',
        'target_carbs_per_day',
        'target_fat_per_day',
        'dietary_preferences',
        'allergen_restrictions',
        'cuisine_preferences',
        'budget_limit_per_day',
        'max_cooking_time_minutes',
        'preferred_difficulty_level',
        'meal_types',
        'include_meal_prep',
        'prioritize_seasonal',
        'avoid_repetition',
        'max_recipe_reuse_days',
        'generation_preferences',
        'feedback_data',
        'status',
        'actual_calories_avg',
        'actual_protein_avg',
        'actual_carbs_avg',
        'actual_fat_avg',
        'adherence_score'
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'dietary_preferences' => 'array',
            'allergen_restrictions' => 'array',
            'cuisine_preferences' => 'array',
            'meal_types' => 'array',
            'generation_preferences' => 'array',
            'feedback_data' => 'array',
            'include_meal_prep' => 'boolean',
            'prioritize_seasonal' => 'boolean',
            'avoid_repetition' => 'boolean',
            'target_calories_per_day' => 'decimal:2',
            'target_protein_per_day' => 'decimal:2',
            'target_carbs_per_day' => 'decimal:2',
            'target_fat_per_day' => 'decimal:2',
            'budget_limit_per_day' => 'decimal:2',
            'actual_calories_avg' => 'decimal:2',
            'actual_protein_avg' => 'decimal:2',
            'actual_carbs_avg' => 'decimal:2',
            'actual_fat_avg' => 'decimal:2',
            'adherence_score' => 'decimal:2',
            'duration_days' => 'integer',
            'max_cooking_time_minutes' => 'integer',
            'preferred_difficulty_level' => 'integer',
            'max_recipe_reuse_days' => 'integer',
        ];
    }

    // Status constants
    public const STATUS_DRAFT = 'draft';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_GENERATING,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_ARCHIVED,
    ];

    // Meal types constants
    public const MEAL_TYPES = [
        'breakfast',
        'lunch',
        'dinner',
        'snack_morning',
        'snack_afternoon',
        'snack_evening'
    ];

    /**
     * Get the user who owns this meal plan
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all meals in this meal plan
     */
    public function meals(): HasMany
    {
        return $this->hasMany(MealPlanMeal::class)->orderBy('date')->orderBy('meal_type');
    }

    /**
     * Get meals for a specific date
     */
    public function getMealsForDate(Carbon $date): \Illuminate\Database\Eloquent\Collection
    {
        return $this->meals()->where('date', $date->format('Y-m-d'))->get();
    }

    /**
     * Get meals by type across all days
     */
    public function getMealsByType(string $mealType): \Illuminate\Database\Eloquent\Collection
    {
        return $this->meals()->where('meal_type', $mealType)->get();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeCurrent($query)
    {
        $today = Carbon::now()->format('Y-m-d');
        return $query->where('start_date', '<=', $today)
                    ->where('end_date', '>=', $today);
    }

    public function scopeUpcoming($query)
    {
        $today = Carbon::now()->format('Y-m-d');
        return $query->where('start_date', '>', $today);
    }

    public function scopePast($query)
    {
        $today = Carbon::now()->format('Y-m-d');
        return $query->where('end_date', '<', $today);
    }

    // Helper methods
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isCurrent(): bool
    {
        $today = Carbon::now();
        return $this->start_date <= $today && $this->end_date >= $today;
    }

    public function getDaysRemaining(): int
    {
        if ($this->end_date < Carbon::now()) {
            return 0;
        }
        return Carbon::now()->diffInDays($this->end_date, false);
    }

    public function getProgressPercentage(): float
    {
        $totalDays = $this->duration_days;
        $daysElapsed = Carbon::now()->diffInDays($this->start_date);
        
        if ($daysElapsed >= $totalDays) {
            return 100.0;
        }
        
        if ($daysElapsed <= 0) {
            return 0.0;
        }
        
        return round(($daysElapsed / $totalDays) * 100, 1);
    }

    public function calculateActualNutrition(): array
    {
        $completedMeals = $this->meals()
            ->where('status', 'completed')
            ->with('recipe')
            ->get();

        if ($completedMeals->isEmpty()) {
            return [
                'calories' => 0,
                'protein' => 0,
                'carbs' => 0,
                'fat' => 0,
                'days_tracked' => 0
            ];
        }

        $totalCalories = 0;
        $totalProtein = 0;
        $totalCarbs = 0;
        $totalFat = 0;
        $daysTracked = $completedMeals->groupBy('date')->count();

        foreach ($completedMeals as $meal) {
            if ($meal->recipe) {
                $nutrition = $meal->recipe->getServingsScaled($meal->servings);
                $totalCalories += $nutrition['calories_per_serving'];
                $totalProtein += $nutrition['protein_per_serving'];
                $totalCarbs += $nutrition['carbs_per_serving'];
                $totalFat += $nutrition['fat_per_serving'];
            } else {
                $totalCalories += $meal->planned_calories ?? 0;
                $totalProtein += $meal->planned_protein ?? 0;
                $totalCarbs += $meal->planned_carbs ?? 0;
                $totalFat += $meal->planned_fat ?? 0;
            }
        }

        return [
            'calories' => $daysTracked > 0 ? round($totalCalories / $daysTracked, 2) : 0,
            'protein' => $daysTracked > 0 ? round($totalProtein / $daysTracked, 2) : 0,
            'carbs' => $daysTracked > 0 ? round($totalCarbs / $daysTracked, 2) : 0,
            'fat' => $daysTracked > 0 ? round($totalFat / $daysTracked, 2) : 0,
            'days_tracked' => $daysTracked
        ];
    }

    public function updateActualNutrition(): bool
    {
        $actualNutrition = $this->calculateActualNutrition();
        
        return $this->update([
            'actual_calories_avg' => $actualNutrition['calories'],
            'actual_protein_avg' => $actualNutrition['protein'],
            'actual_carbs_avg' => $actualNutrition['carbs'],
            'actual_fat_avg' => $actualNutrition['fat'],
        ]);
    }

    public function calculateAdherenceScore(): float
    {
        $totalMeals = $this->meals()->count();
        if ($totalMeals === 0) {
            return 0.0;
        }

        $completedMeals = $this->meals()->where('status', 'completed')->count();
        $adherenceScore = ($completedMeals / $totalMeals) * 100;

        // Update the adherence score
        $this->update(['adherence_score' => round($adherenceScore, 2)]);

        return round($adherenceScore, 2);
    }

    public function getTargetMacroDistribution(): array
    {
        $totalCalories = $this->target_calories_per_day ?? 2000;
        
        if ($totalCalories == 0) {
            return ['protein' => 0, 'carbs' => 0, 'fat' => 0];
        }
        
        $proteinCals = ($this->target_protein_per_day ?? 0) * 4;
        $carbsCals = ($this->target_carbs_per_day ?? 0) * 4;
        $fatCals = ($this->target_fat_per_day ?? 0) * 9;
        
        return [
            'protein' => round(($proteinCals / $totalCalories) * 100, 1),
            'carbs' => round(($carbsCals / $totalCalories) * 100, 1),
            'fat' => round(($fatCals / $totalCalories) * 100, 1)
        ];
    }

    public function getActualMacroDistribution(): array
    {
        $totalCalories = $this->actual_calories_avg ?? 0;
        
        if ($totalCalories == 0) {
            return ['protein' => 0, 'carbs' => 0, 'fat' => 0];
        }
        
        $proteinCals = ($this->actual_protein_avg ?? 0) * 4;
        $carbsCals = ($this->actual_carbs_avg ?? 0) * 4;
        $fatCals = ($this->actual_fat_avg ?? 0) * 9;
        
        return [
            'protein' => round(($proteinCals / $totalCalories) * 100, 1),
            'carbs' => round(($carbsCals / $totalCalories) * 100, 1),
            'fat' => round(($fatCals / $totalCalories) * 100, 1)
        ];
    }

    public function generateShoppingList(): array
    {
        $ingredients = [];
        $meals = $this->meals()->with(['recipe.ingredients.food'])->get();

        foreach ($meals as $meal) {
            if ($meal->recipe && $meal->recipe->ingredients) {
                foreach ($meal->recipe->ingredients as $ingredient) {
                    $scaledAmount = $ingredient->amount * $meal->servings;
                    $key = $ingredient->food_id ? 
                        $ingredient->food->name : 
                        $ingredient->ingredient_name;
                    
                    if (isset($ingredients[$key])) {
                        // Aggregate if same unit
                        if ($ingredients[$key]['unit'] === $ingredient->unit) {
                            $ingredients[$key]['total_amount'] += $scaledAmount;
                        } else {
                            // Keep separate if different units
                            $ingredients[$key . ' (' . $ingredient->unit . ')'] = [
                                'ingredient_name' => $ingredient->ingredient_name,
                                'total_amount' => $scaledAmount,
                                'unit' => $ingredient->unit,
                                'food' => $ingredient->food
                            ];
                        }
                    } else {
                        $ingredients[$key] = [
                            'ingredient_name' => $ingredient->ingredient_name,
                            'total_amount' => $scaledAmount,
                            'unit' => $ingredient->unit,
                            'food' => $ingredient->food
                        ];
                    }
                }
            }
        }

        return $ingredients;
    }

    public function getMealPrepSuggestions(): array
    {
        $mealPrepMeals = $this->meals()
            ->where('is_meal_prep', true)
            ->with('recipe')
            ->get()
            ->groupBy('prep_date');

        $suggestions = [];
        foreach ($mealPrepMeals as $prepDate => $meals) {
            $totalPrepTime = $meals->sum('estimated_prep_time');
            $recipes = $meals->pluck('recipe.name')->unique()->values()->toArray();
            
            $suggestions[] = [
                'prep_date' => $prepDate,
                'total_prep_time' => $totalPrepTime,
                'recipes' => $recipes,
                'meal_count' => $meals->count(),
                'tips' => $this->generateMealPrepTips($meals)
            ];
        }

        return $suggestions;
    }

    private function generateMealPrepTips($meals): array
    {
        $tips = [];
        
        if ($meals->count() > 3) {
            $tips[] = "Consider batch cooking similar ingredients to save time";
        }
        
        if ($meals->sum('estimated_prep_time') > 180) {
            $tips[] = "This is a long prep session - consider splitting across multiple days";
        }
        
        $cuisineTypes = $meals->pluck('recipe.cuisine_type')->filter()->unique();
        if ($cuisineTypes->count() > 2) {
            $tips[] = "Group similar cuisines together for efficient seasoning and cleanup";
        }
        
        return $tips;
    }
}