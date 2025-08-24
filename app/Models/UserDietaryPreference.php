<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDietaryPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'dietary_restrictions',
        'allergens',
        'cuisine_preferences',
        'disliked_ingredients',
        'preferred_ingredients',
        'max_cooking_time',
        'preferred_difficulty_max',
        'target_calories_per_day',
        'macro_targets',
        'prioritize_protein',
        'low_sodium',
        'low_sugar',
        'high_fiber',
        'meal_frequency',
        'meal_prep_friendly',
        'budget_per_meal',
        'seasonal_preference',
        'region',
        'equipment_available',
        'shopping_preferences'
    ];

    protected function casts(): array
    {
        return [
            'dietary_restrictions' => 'array',
            'allergens' => 'array',
            'cuisine_preferences' => 'array',
            'disliked_ingredients' => 'array',
            'preferred_ingredients' => 'array',
            'macro_targets' => 'array',
            'meal_frequency' => 'array',
            'equipment_available' => 'array',
            'shopping_preferences' => 'array',
            'prioritize_protein' => 'boolean',
            'low_sodium' => 'boolean',
            'low_sugar' => 'boolean',
            'high_fiber' => 'boolean',
            'meal_prep_friendly' => 'boolean',
            'seasonal_preference' => 'boolean',
            'max_cooking_time' => 'integer',
            'preferred_difficulty_max' => 'integer',
            'target_calories_per_day' => 'decimal:2',
            'budget_per_meal' => 'decimal:2',
        ];
    }

    // Dietary restriction constants
    public const DIETARY_RESTRICTIONS = [
        'vegetarian',
        'vegan',
        'pescatarian',
        'keto',
        'paleo',
        'mediterranean',
        'low_carb',
        'low_fat',
        'diabetic_friendly',
        'heart_healthy',
        'gluten_free',
        'dairy_free',
        'whole30',
        'intermittent_fasting'
    ];

    // Common allergens
    public const ALLERGENS = [
        'nuts',
        'peanuts',
        'tree_nuts',
        'dairy',
        'eggs',
        'fish',
        'shellfish',
        'soy',
        'gluten',
        'wheat',
        'sesame',
        'sulfites'
    ];

    // Equipment types
    public const EQUIPMENT = [
        'stovetop',
        'oven',
        'microwave',
        'slow_cooker',
        'pressure_cooker',
        'air_fryer',
        'grill',
        'food_processor',
        'blender',
        'stand_mixer',
        'bread_maker',
        'rice_cooker',
        'steamer',
        'dehydrator'
    ];

    /**
     * Get the user this preference belongs to
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Helper methods
    public function hasRestriction(string $restriction): bool
    {
        return in_array($restriction, $this->dietary_restrictions ?? []);
    }

    public function hasAllergen(string $allergen): bool
    {
        return in_array($allergen, $this->allergens ?? []);
    }

    public function prefersEquipment(string $equipment): bool
    {
        return in_array($equipment, $this->equipment_available ?? []);
    }

    public function dislikesIngredient(string $ingredient): bool
    {
        return in_array(strtolower($ingredient), 
            array_map('strtolower', $this->disliked_ingredients ?? []));
    }

    public function prefersIngredient(string $ingredient): bool
    {
        return in_array(strtolower($ingredient), 
            array_map('strtolower', $this->preferred_ingredients ?? []));
    }

    public function prefersCuisine(string $cuisine): bool
    {
        return in_array($cuisine, $this->cuisine_preferences ?? []);
    }

    public function getMacroTargets(): array
    {
        $defaults = [
            'protein_percentage' => 20,
            'carbs_percentage' => 50,
            'fat_percentage' => 30
        ];

        return array_merge($defaults, $this->macro_targets ?? []);
    }

    public function getMealFrequency(): array
    {
        $defaults = [
            'meals_per_day' => 3,
            'snacks_per_day' => 2,
            'include_breakfast' => true,
            'include_lunch' => true,
            'include_dinner' => true
        ];

        return array_merge($defaults, $this->meal_frequency ?? []);
    }

    public function getShoppingPreferences(): array
    {
        $defaults = [
            'prefer_organic' => false,
            'prefer_local' => false,
            'bulk_buying' => false,
            'frozen_acceptable' => true,
            'canned_acceptable' => true,
            'preferred_stores' => []
        ];

        return array_merge($defaults, $this->shopping_preferences ?? []);
    }

    public function isRecipeSuitable(Recipe $recipe): array
    {
        $suitability = [
            'suitable' => true,
            'reasons' => []
        ];

        // Check dietary restrictions
        if ($this->dietary_restrictions) {
            foreach ($this->dietary_restrictions as $restriction) {
                if (!$recipe->isSuitableForDiet($restriction)) {
                    $suitability['suitable'] = false;
                    $suitability['reasons'][] = "Recipe doesn't meet {$restriction} requirements";
                }
            }
        }

        // Check allergens
        if ($this->allergens) {
            foreach ($this->allergens as $allergen) {
                if ($recipe->containsAllergen($allergen)) {
                    $suitability['suitable'] = false;
                    $suitability['reasons'][] = "Recipe contains {$allergen}";
                }
            }
        }

        // Check cooking time
        if ($this->max_cooking_time && $recipe->total_time_minutes > $this->max_cooking_time) {
            $suitability['suitable'] = false;
            $suitability['reasons'][] = "Cooking time ({$recipe->total_time_minutes}min) exceeds limit ({$this->max_cooking_time}min)";
        }

        // Check difficulty
        if ($this->preferred_difficulty_max && $recipe->difficulty_level > $this->preferred_difficulty_max) {
            $suitability['suitable'] = false;
            $suitability['reasons'][] = "Recipe difficulty ({$recipe->difficulty_level}) exceeds preference ({$this->preferred_difficulty_max})";
        }

        // Check disliked ingredients
        if ($this->disliked_ingredients && $recipe->ingredients) {
            foreach ($recipe->ingredients as $ingredient) {
                if ($this->dislikesIngredient($ingredient->ingredient_name)) {
                    $suitability['suitable'] = false;
                    $suitability['reasons'][] = "Recipe contains disliked ingredient: {$ingredient->ingredient_name}";
                }
            }
        }

        // Check nutritional preferences
        if ($this->low_sodium && $recipe->sodium_per_serving > 400) { // mg per serving
            $suitability['suitable'] = false;
            $suitability['reasons'][] = "Recipe is too high in sodium";
        }

        if ($this->low_sugar && $recipe->sugar_per_serving > 10) { // g per serving
            $suitability['suitable'] = false;
            $suitability['reasons'][] = "Recipe is too high in sugar";
        }

        return $suitability;
    }

    public function getRecipeScore(Recipe $recipe): float
    {
        $score = 50.0; // Base score
        
        // Bonus points for preferred cuisines
        if ($this->prefersCuisine($recipe->cuisine_type)) {
            $score += 15;
        }

        // Bonus for preferred ingredients
        if ($recipe->ingredients && $this->preferred_ingredients) {
            foreach ($recipe->ingredients as $ingredient) {
                if ($this->prefersIngredient($ingredient->ingredient_name)) {
                    $score += 5;
                }
            }
        }

        // Penalty for longer cooking times if user has time constraints
        if ($this->max_cooking_time) {
            $timeRatio = $recipe->total_time_minutes / $this->max_cooking_time;
            if ($timeRatio > 0.8) {
                $score -= ($timeRatio - 0.8) * 20;
            }
        }

        // Bonus for nutritional preferences
        if ($this->prioritize_protein && $recipe->protein_per_serving > 20) {
            $score += 10;
        }

        if ($this->high_fiber && $recipe->fiber_per_serving > 5) {
            $score += 10;
        }

        // Recipe rating influence
        if ($recipe->average_rating > 0) {
            $score += ($recipe->average_rating - 2.5) * 8; // -12 to +20 points based on rating
        }

        // Popular recipes get a small boost
        if ($recipe->total_ratings > 50) {
            $score += 5;
        }

        return max(0, min(100, $score)); // Clamp between 0 and 100
    }

    public function updateFromUserGoals(array $goals): bool
    {
        $updates = [];
        
        foreach ($goals as $goal) {
            if ($goal['type'] === 'caloric_intake') {
                $updates['target_calories_per_day'] = $goal['target_value'];
            }
            
            if ($goal['type'] === 'weight_loss' || $goal['type'] === 'weight_gain') {
                // Adjust macro targets based on goal
                $macroTargets = $this->getMacroTargets();
                if ($goal['type'] === 'weight_loss') {
                    $macroTargets['protein_percentage'] = 25; // Higher protein for weight loss
                    $macroTargets['carbs_percentage'] = 40;
                    $macroTargets['fat_percentage'] = 35;
                }
                $updates['macro_targets'] = $macroTargets;
            }
        }
        
        return empty($updates) ? true : $this->update($updates);
    }
}