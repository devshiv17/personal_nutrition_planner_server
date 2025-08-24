<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeIngredient extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipe_id',
        'food_id',
        'ingredient_name',
        'amount',
        'unit',
        'amount_grams',
        'preparation_notes',
        'order',
        'is_optional',
        'group_name'
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'amount_grams' => 'decimal:2',
            'order' => 'integer',
            'is_optional' => 'boolean',
        ];
    }

    /**
     * Get the recipe this ingredient belongs to
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Get the food item for this ingredient
     */
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    /**
     * Get formatted amount display
     */
    public function getFormattedAmountAttribute(): string
    {
        $amount = $this->amount;
        
        // Convert decimals to fractions for common cooking amounts
        if ($amount == 0.25) return '¼';
        if ($amount == 0.33) return '⅓';
        if ($amount == 0.5) return '½';
        if ($amount == 0.67) return '⅔';
        if ($amount == 0.75) return '¾';
        
        // Round to reasonable precision
        if ($amount == floor($amount)) {
            return (string) intval($amount);
        }
        
        if ($amount < 10) {
            return number_format($amount, 2);
        }
        
        return number_format($amount, 1);
    }

    /**
     * Get display name (food name or custom ingredient name)
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->ingredient_name ?? $this->food?->name ?? 'Unknown ingredient';
    }

    /**
     * Scale ingredient amount for different serving sizes
     */
    public function getScaledAmount(float $scaleFactor): array
    {
        return [
            'amount' => round($this->amount * $scaleFactor, 3),
            'amount_grams' => round($this->amount_grams * $scaleFactor, 2),
            'formatted_amount' => $this->formatAmount($this->amount * $scaleFactor)
        ];
    }

    /**
     * Calculate nutrition for this ingredient
     */
    public function getNutrition(): array
    {
        if (!$this->food || !$this->amount_grams) {
            return [
                'calories' => 0,
                'protein' => 0,
                'carbs' => 0,
                'fat' => 0,
                'fiber' => 0,
                'sugar' => 0,
                'sodium' => 0
            ];
        }

        return $this->food->getNutritionForServing($this->amount_grams);
    }

    /**
     * Check if this ingredient contains an allergen
     */
    public function containsAllergen(string $allergen): bool
    {
        return $this->food?->containsAllergen($allergen) ?? false;
    }

    /**
     * Get ingredient cost estimate (if available)
     */
    public function getEstimatedCost(): ?float
    {
        // This would integrate with a cost database
        // For now, return null
        return null;
    }

    /**
     * Format amount for display
     */
    private function formatAmount(float $amount): string
    {
        // Convert decimals to fractions for common cooking amounts
        $fractions = [
            0.125 => '⅛',
            0.25 => '¼',
            0.33 => '⅓',
            0.375 => '⅜',
            0.5 => '½',
            0.625 => '⅝',
            0.67 => '⅔',
            0.75 => '¾',
            0.875 => '⅞'
        ];

        // Check for exact fraction matches
        if (isset($fractions[$amount])) {
            return $fractions[$amount];
        }

        // Check for mixed numbers (e.g., 1.5 = 1½)
        $whole = floor($amount);
        $decimal = $amount - $whole;
        
        if ($whole > 0 && isset($fractions[$decimal])) {
            return $whole . $fractions[$decimal];
        }

        // Regular formatting
        if ($amount == floor($amount)) {
            return (string) intval($amount);
        }
        
        if ($amount < 10) {
            return rtrim(rtrim(number_format($amount, 3), '0'), '.');
        }
        
        return number_format($amount, 1);
    }

    /**
     * Scope for specific recipe
     */
    public function scopeForRecipe($query, int $recipeId)
    {
        return $query->where('recipe_id', $recipeId);
    }

    /**
     * Scope for ingredient group
     */
    public function scopeInGroup($query, ?string $groupName)
    {
        if ($groupName) {
            return $query->where('group_name', $groupName);
        }
        return $query->whereNull('group_name');
    }

    /**
     * Scope for optional ingredients
     */
    public function scopeOptional($query)
    {
        return $query->where('is_optional', true);
    }

    /**
     * Scope for required ingredients
     */
    public function scopeRequired($query)
    {
        return $query->where('is_optional', false);
    }
}