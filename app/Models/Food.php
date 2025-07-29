<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Food extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'name',
        'brand',
        'category',
        'subcategory',
        'calories_per_100g',
        'protein_per_100g',
        'carbs_per_100g',
        'fat_per_100g',
        'fiber_per_100g',
        'sugar_per_100g',
        'sodium_per_100g',
        'additional_nutrients',
        'common_serving_size',
        'common_serving_unit',
        'barcode',
        'image_url',
        'data_source',
        'is_verified',
        'verification_level',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'calories_per_100g' => 'decimal:2',
            'protein_per_100g' => 'decimal:2',
            'carbs_per_100g' => 'decimal:2',
            'fat_per_100g' => 'decimal:2',
            'fiber_per_100g' => 'decimal:2',
            'sugar_per_100g' => 'decimal:2',
            'sodium_per_100g' => 'decimal:2',
            'common_serving_size' => 'decimal:2',
            'additional_nutrients' => 'json',
            'is_verified' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the user who created this food
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope for verified foods only
     */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * Scope for specific category
     */
    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope for search by name
     */
    public function scopeSearchByName($query, string $searchTerm)
    {
        return $query->where('name', 'ILIKE', "%{$searchTerm}%");
    }

    /**
     * Scope for popular foods (most used)
     */
    public function scopePopular($query, int $limit = 10)
    {
        return $query->orderByDesc('usage_count')->limit($limit);
    }

    /**
     * Calculate nutrition for a specific serving size
     */
    public function getNutritionForServing(float $servingGrams): array
    {
        $multiplier = $servingGrams / 100;

        return [
            'serving_size_g' => $servingGrams,
            'calories' => round($this->calories_per_100g * $multiplier, 2),
            'protein_g' => round($this->protein_per_100g * $multiplier, 2),
            'carbs_g' => round($this->carbs_per_100g * $multiplier, 2),
            'fat_g' => round($this->fat_per_100g * $multiplier, 2),
            'fiber_g' => round($this->fiber_per_100g * $multiplier, 2),
            'sugar_g' => round($this->sugar_per_100g * $multiplier, 2),
            'sodium_mg' => round($this->sodium_per_100g * $multiplier, 2),
        ];
    }

    /**
     * Get common serving size in grams
     */
    public function getCommonServingInGrams(): ?float
    {
        if (!$this->common_serving_size || !$this->common_serving_unit) {
            return null;
        }

        // Convert common units to grams (simplified conversion)
        $conversions = [
            'g' => 1,
            'kg' => 1000,
            'ml' => 1, // Assuming 1ml = 1g for liquids
            'cup' => 240,
            'tbsp' => 15,
            'tsp' => 5,
            'oz' => 28.35,
            'lb' => 453.59,
            'piece' => $this->common_serving_size, // Use the serving size as-is
        ];

        $unit = strtolower($this->common_serving_unit);
        $conversionFactor = $conversions[$unit] ?? 1;

        return $this->common_serving_size * $conversionFactor;
    }

    /**
     * Check if food is suitable for dietary preference
     */
    public function isSuitableForDiet(string $dietaryPreference): bool
    {
        // This would typically involve more complex logic
        // based on ingredients, nutritional values, etc.
        // For now, we'll use simple heuristics

        switch ($dietaryPreference) {
            case 'keto':
                return $this->carbs_per_100g <= 5; // Low carb
            case 'vegan':
                // Would need ingredient analysis or tags
                return !str_contains(strtolower($this->name), 'meat') &&
                       !str_contains(strtolower($this->name), 'dairy') &&
                       !str_contains(strtolower($this->name), 'egg');
            case 'diabetic_friendly':
                return $this->sugar_per_100g <= 5; // Low sugar
            case 'mediterranean':
                return true; // Most foods can fit Mediterranean diet
            default:
                return true;
        }
    }
}