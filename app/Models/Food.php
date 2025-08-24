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
        'brand_name',
        'description',
        'category',
        'subcategory',
        'calories_per_100g',
        'protein_per_100g',
        'carbohydrates_per_100g',
        'fat_per_100g',
        'fiber_per_100g',
        'sugar_per_100g',
        'sodium_per_100g',
        'additional_nutrients',
        'serving_size',
        'serving_unit',
        'barcode',
        'image_url',
        'source',
        'is_verified',
        'verification_level',
        'created_by',
        'usage_count',
        'last_updated',
        'ingredients',
        'allergens'
    ];

    protected function casts(): array
    {
        return [
            'calories_per_100g' => 'decimal:2',
            'protein_per_100g' => 'decimal:2',
            'carbohydrates_per_100g' => 'decimal:2',
            'fat_per_100g' => 'decimal:2',
            'fiber_per_100g' => 'decimal:2',
            'sugar_per_100g' => 'decimal:2',
            'sodium_per_100g' => 'decimal:2',
            'serving_size' => 'decimal:2',
            'additional_nutrients' => 'json',
            'ingredients' => 'array',
            'allergens' => 'array',
            'is_verified' => 'boolean',
            'usage_count' => 'integer',
            'last_updated' => 'datetime',
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
     * Get food logs for this food
     */
    public function foodLogs()
    {
        return $this->hasMany(FoodLog::class);
    }

    /**
     * Get users who have favorited this food
     */
    public function favoritedByUsers()
    {
        return $this->belongsToMany(User::class, 'user_favorite_foods')
                    ->withTimestamps();
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

        return $this->serving_size * $conversionFactor;
    }

    /**
     * Get macro distribution
     */
    public function getMacroDistribution(): array
    {
        $proteinCals = $this->protein_per_100g * 4;
        $carbsCals = $this->carbohydrates_per_100g * 4;
        $fatCals = $this->fat_per_100g * 9;
        $totalCals = $this->calories_per_100g ?: ($proteinCals + $carbsCals + $fatCals);
        
        if ($totalCals == 0) {
            return ['protein' => 0, 'carbs' => 0, 'fat' => 0];
        }
        
        return [
            'protein' => round(($proteinCals / $totalCals) * 100, 1),
            'carbs' => round(($carbsCals / $totalCals) * 100, 1),
            'fat' => round(($fatCals / $totalCals) * 100, 1)
        ];
    }

    /**
     * Increment usage count
     */
    public function incrementUsage()
    {
        $this->increment('usage_count');
        $this->touch();
    }

    /**
     * Check if food contains allergen
     */
    public function containsAllergen(string $allergen): bool
    {
        return in_array(strtolower($allergen), array_map('strtolower', $this->allergens ?? []));
    }

    /**
     * Search scope with multiple filters
     */
    public function scopeAdvancedSearch($query, array $filters)
    {
        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->where('name', 'ILIKE', '%' . $filters['search'] . '%')
                  ->orWhere('brand_name', 'ILIKE', '%' . $filters['search'] . '%')
                  ->orWhere('description', 'ILIKE', '%' . $filters['search'] . '%');
            });
        }
        
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        
        if (!empty($filters['brand'])) {
            $query->where('brand_name', 'ILIKE', '%' . $filters['brand'] . '%');
        }
        
        if (!empty($filters['verified_only'])) {
            $query->where('is_verified', true);
        }
        
        if (!empty($filters['max_calories'])) {
            $query->where('calories_per_100g', '<=', $filters['max_calories']);
        }
        
        if (!empty($filters['min_protein'])) {
            $query->where('protein_per_100g', '>=', $filters['min_protein']);
        }
        
        if (!empty($filters['max_carbs'])) {
            $query->where('carbohydrates_per_100g', '<=', $filters['max_carbs']);
        }
        
        if (!empty($filters['max_fat'])) {
            $query->where('fat_per_100g', '<=', $filters['max_fat']);
        }
        
        if (!empty($filters['allergen_free'])) {
            foreach ($filters['allergen_free'] as $allergen) {
                $query->whereNotJsonContains('allergens', $allergen);
            }
        }
        
        return $query;
    }

    /**
     * Get recent foods for a user
     */
    public function scopeRecentForUser($query, int $userId, int $days = 30)
    {
        return $query->whereHas('foodLogs', function($q) use ($userId, $days) {
            $q->where('user_id', $userId)
              ->where('logged_at', '>=', now()->subDays($days));
        })->withCount(['foodLogs' => function($q) use ($userId, $days) {
            $q->where('user_id', $userId)
              ->where('logged_at', '>=', now()->subDays($days));
        }])->orderBy('food_logs_count', 'desc');
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
                return $this->carbohydrates_per_100g <= 5; // Low carb
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