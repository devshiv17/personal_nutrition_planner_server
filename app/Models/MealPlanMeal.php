<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlanMeal extends Model
{
    use HasFactory;

    protected $fillable = [
        'meal_plan_id',
        'recipe_id',
        'date',
        'meal_type',
        'custom_meal_name',
        'custom_meal_description',
        'custom_ingredients',
        'servings',
        'planned_calories',
        'planned_protein',
        'planned_carbs',
        'planned_fat',
        'preparation_notes',
        'is_meal_prep',
        'prep_date',
        'estimated_prep_time',
        'status',
        'completion_notes',
        'user_rating',
        'user_feedback',
        'substitution_reason',
        'substituted_with_recipe_id'
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'prep_date' => 'date',
            'custom_ingredients' => 'array',
            'substitution_reason' => 'array',
            'servings' => 'decimal:2',
            'planned_calories' => 'decimal:2',
            'planned_protein' => 'decimal:2',
            'planned_carbs' => 'decimal:2',
            'planned_fat' => 'decimal:2',
            'is_meal_prep' => 'boolean',
            'estimated_prep_time' => 'integer',
            'user_rating' => 'integer',
        ];
    }

    // Status constants
    public const STATUS_PLANNED = 'planned';
    public const STATUS_PREPPED = 'prepped';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_SUBSTITUTED = 'substituted';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_PREPPED,
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
        self::STATUS_SUBSTITUTED,
    ];

    // Meal type constants
    public const MEAL_TYPE_BREAKFAST = 'breakfast';
    public const MEAL_TYPE_LUNCH = 'lunch';
    public const MEAL_TYPE_DINNER = 'dinner';
    public const MEAL_TYPE_SNACK_MORNING = 'snack_morning';
    public const MEAL_TYPE_SNACK_AFTERNOON = 'snack_afternoon';
    public const MEAL_TYPE_SNACK_EVENING = 'snack_evening';

    public const MEAL_TYPES = [
        self::MEAL_TYPE_BREAKFAST,
        self::MEAL_TYPE_LUNCH,
        self::MEAL_TYPE_DINNER,
        self::MEAL_TYPE_SNACK_MORNING,
        self::MEAL_TYPE_SNACK_AFTERNOON,
        self::MEAL_TYPE_SNACK_EVENING,
    ];

    /**
     * Get the meal plan this meal belongs to
     */
    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    /**
     * Get the recipe for this meal
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Get the substituted recipe if this meal was substituted
     */
    public function substitutedWithRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'substituted_with_recipe_id');
    }

    // Scopes
    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeByMealType($query, string $mealType)
    {
        return $query->where('meal_type', $mealType);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopePlanned($query)
    {
        return $query->where('status', self::STATUS_PLANNED);
    }

    public function scopeMealPrep($query)
    {
        return $query->where('is_meal_prep', true);
    }

    public function scopeForPrep($query, $prepDate)
    {
        return $query->where('prep_date', $prepDate);
    }

    // Helper methods
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    public function isSubstituted(): bool
    {
        return $this->status === self::STATUS_SUBSTITUTED;
    }

    public function isMealPrep(): bool
    {
        return $this->is_meal_prep === true;
    }

    public function getMealTypeLabel(): string
    {
        return match($this->meal_type) {
            self::MEAL_TYPE_BREAKFAST => 'Breakfast',
            self::MEAL_TYPE_LUNCH => 'Lunch',
            self::MEAL_TYPE_DINNER => 'Dinner',
            self::MEAL_TYPE_SNACK_MORNING => 'Morning Snack',
            self::MEAL_TYPE_SNACK_AFTERNOON => 'Afternoon Snack',
            self::MEAL_TYPE_SNACK_EVENING => 'Evening Snack',
            default => 'Unknown'
        };
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_PLANNED => 'Planned',
            self::STATUS_PREPPED => 'Prepped',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_SKIPPED => 'Skipped',
            self::STATUS_SUBSTITUTED => 'Substituted',
            default => 'Unknown'
        };
    }

    public function getActualNutrition(): array
    {
        if ($this->recipe) {
            return $this->recipe->getServingsScaled($this->servings);
        }

        return [
            'calories_per_serving' => $this->planned_calories ?? 0,
            'protein_per_serving' => $this->planned_protein ?? 0,
            'carbs_per_serving' => $this->planned_carbs ?? 0,
            'fat_per_serving' => $this->planned_fat ?? 0,
            'servings' => $this->servings ?? 1
        ];
    }

    public function getScaledIngredients(): array
    {
        if (!$this->recipe || !$this->recipe->ingredients) {
            if ($this->custom_ingredients) {
                return $this->custom_ingredients;
            }
            return [];
        }

        $scaledIngredients = [];
        foreach ($this->recipe->ingredients as $ingredient) {
            $scaledAmount = $ingredient->amount * $this->servings;
            
            $scaledIngredients[] = [
                'name' => $ingredient->ingredient_name,
                'amount' => $scaledAmount,
                'unit' => $ingredient->unit,
                'preparation_notes' => $ingredient->preparation_notes,
                'is_optional' => $ingredient->is_optional,
                'food' => $ingredient->food
            ];
        }

        return $scaledIngredients;
    }

    public function markCompleted(?string $notes = null, ?int $rating = null, ?string $feedback = null): bool
    {
        return $this->update([
            'status' => self::STATUS_COMPLETED,
            'completion_notes' => $notes,
            'user_rating' => $rating,
            'user_feedback' => $feedback
        ]);
    }

    public function markSkipped(?string $reason = null): bool
    {
        return $this->update([
            'status' => self::STATUS_SKIPPED,
            'completion_notes' => $reason
        ]);
    }

    public function substitute(int $newRecipeId, ?array $reason = null): bool
    {
        return $this->update([
            'status' => self::STATUS_SUBSTITUTED,
            'substituted_with_recipe_id' => $newRecipeId,
            'substitution_reason' => $reason
        ]);
    }

    public function getEstimatedCookTime(): int
    {
        if ($this->recipe) {
            return $this->recipe->total_time_minutes ?? 0;
        }
        
        return $this->estimated_prep_time ?? 0;
    }

    public function canBePrepped(): bool
    {
        return $this->recipe && 
               $this->recipe->storage_instructions && 
               $this->prep_date && 
               $this->prep_date <= $this->date;
    }

    public function getDaysUntilConsumption(): int
    {
        if (!$this->prep_date) {
            return 0;
        }
        
        return $this->prep_date->diffInDays($this->date);
    }
}