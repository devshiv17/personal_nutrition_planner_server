<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Recipe extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'instructions',
        'prep_time_minutes',
        'cook_time_minutes',
        'total_time_minutes',
        'servings',
        'difficulty_level',
        'cuisine_type',
        'meal_category',
        'dietary_preferences',
        'allergens',
        'image_url',
        'images',
        'video_url',
        'source_url',
        'created_by',
        'is_public',
        'is_verified',
        'average_rating',
        'total_ratings',
        'total_reviews',
        'calories_per_serving',
        'protein_per_serving',
        'carbs_per_serving',
        'fat_per_serving',
        'fiber_per_serving',
        'sugar_per_serving',
        'sodium_per_serving',
        'tags',
        'equipment_needed',
        'storage_instructions',
        'nutritional_notes'
    ];

    protected function casts(): array
    {
        return [
            'instructions' => 'array',
            'dietary_preferences' => 'array',
            'allergens' => 'array',
            'tags' => 'array',
            'equipment_needed' => 'array',
            'images' => 'array',
            'prep_time_minutes' => 'integer',
            'cook_time_minutes' => 'integer',
            'total_time_minutes' => 'integer',
            'servings' => 'integer',
            'difficulty_level' => 'integer',
            'is_public' => 'boolean',
            'is_verified' => 'boolean',
            'average_rating' => 'decimal:2',
            'total_ratings' => 'integer',
            'total_reviews' => 'integer',
            'calories_per_serving' => 'decimal:2',
            'protein_per_serving' => 'decimal:2',
            'carbs_per_serving' => 'decimal:2',
            'fat_per_serving' => 'decimal:2',
            'fiber_per_serving' => 'decimal:2',
            'sugar_per_serving' => 'decimal:2',
            'sodium_per_serving' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    // Difficulty levels
    public const DIFFICULTY_LEVELS = [
        1 => 'Easy',
        2 => 'Medium', 
        3 => 'Hard',
        4 => 'Expert'
    ];

    // Meal categories
    public const MEAL_CATEGORIES = [
        'breakfast',
        'lunch', 
        'dinner',
        'snack',
        'dessert',
        'appetizer',
        'side_dish',
        'beverage',
        'sauce',
        'salad',
        'soup'
    ];

    // Cuisine types
    public const CUISINE_TYPES = [
        'american',
        'italian',
        'mexican',
        'chinese',
        'indian',
        'french',
        'japanese',
        'thai',
        'mediterranean',
        'greek',
        'spanish',
        'korean',
        'vietnamese',
        'middle_eastern',
        'african',
        'caribbean',
        'fusion'
    ];

    /**
     * Get the user who created this recipe
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get recipe ingredients
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('order');
    }

    /**
     * Get recipe ratings and reviews
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(RecipeRating::class);
    }

    /**
     * Get recipe collections this recipe belongs to
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(RecipeCollection::class, 'recipe_collection_items')
                    ->withTimestamps();
    }

    /**
     * Get users who have favorited this recipe
     */
    public function favoritedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_favorite_recipes')
                    ->withTimestamps();
    }

    /**
     * Get recipe cooking logs
     */
    public function cookingLogs(): HasMany
    {
        return $this->hasMany(RecipeCookingLog::class);
    }

    // Scopes
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopeByMealCategory($query, string $category)
    {
        return $query->where('meal_category', $category);
    }

    public function scopeByCuisine($query, string $cuisine)
    {
        return $query->where('cuisine_type', $cuisine);
    }

    public function scopeByDifficulty($query, int $difficulty)
    {
        return $query->where('difficulty_level', $difficulty);
    }

    public function scopeByDietaryPreference($query, string $preference)
    {
        return $query->whereJsonContains('dietary_preferences', $preference);
    }

    public function scopeAllergenFree($query, array $allergens)
    {
        foreach ($allergens as $allergen) {
            $query->whereNotJsonContains('allergens', $allergen);
        }
        return $query;
    }

    public function scopeMaxCookingTime($query, int $minutes)
    {
        return $query->where('total_time_minutes', '<=', $minutes);
    }

    public function scopeMinRating($query, float $rating)
    {
        return $query->where('average_rating', '>=', $rating);
    }

    public function scopeRecentlyAdded($query, int $days = 7)
    {
        return $query->where('created_at', '>=', Carbon::now()->subDays($days));
    }

    public function scopePopular($query, int $limit = 10)
    {
        return $query->orderByDesc('total_ratings')
                    ->orderByDesc('average_rating')
                    ->limit($limit);
    }

    public function scopeSearchByName($query, string $searchTerm)
    {
        return $query->where(function($q) use ($searchTerm) {
            $q->where('name', 'ILIKE', "%{$searchTerm}%")
              ->orWhere('description', 'ILIKE', "%{$searchTerm}%")
              ->orWhereJsonContains('tags', $searchTerm);
        });
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where(function($q) use ($userId) {
            $q->where('created_by', $userId)
              ->orWhere('is_public', true);
        });
    }

    // Helper methods
    public function getDifficultyLabelAttribute(): string
    {
        return self::DIFFICULTY_LEVELS[$this->difficulty_level] ?? 'Unknown';
    }

    public function getTotalTimeFormatted(): string
    {
        if ($this->total_time_minutes < 60) {
            return $this->total_time_minutes . ' mins';
        }

        $hours = floor($this->total_time_minutes / 60);
        $mins = $this->total_time_minutes % 60;
        
        if ($mins === 0) {
            return $hours . 'h';
        }
        
        return $hours . 'h ' . $mins . 'm';
    }

    public function getServingsScaled(int $newServings): array
    {
        if ($this->servings == 0) {
            return [];
        }

        $scaleFactor = $newServings / $this->servings;
        
        return [
            'scale_factor' => $scaleFactor,
            'servings' => $newServings,
            'calories_per_serving' => round($this->calories_per_serving * $scaleFactor, 2),
            'protein_per_serving' => round($this->protein_per_serving * $scaleFactor, 2),
            'carbs_per_serving' => round($this->carbs_per_serving * $scaleFactor, 2),
            'fat_per_serving' => round($this->fat_per_serving * $scaleFactor, 2),
            'fiber_per_serving' => round($this->fiber_per_serving * $scaleFactor, 2),
            'sugar_per_serving' => round($this->sugar_per_serving * $scaleFactor, 2),
            'sodium_per_serving' => round($this->sodium_per_serving * $scaleFactor, 2),
        ];
    }

    public function calculateNutritionFromIngredients(): array
    {
        $totalNutrition = [
            'calories' => 0,
            'protein' => 0,
            'carbs' => 0,
            'fat' => 0,
            'fiber' => 0,
            'sugar' => 0,
            'sodium' => 0
        ];

        foreach ($this->ingredients as $ingredient) {
            if ($ingredient->food) {
                $nutrition = $ingredient->food->getNutritionForServing($ingredient->amount_grams);
                
                $totalNutrition['calories'] += $nutrition['calories'];
                $totalNutrition['protein'] += $nutrition['protein_g'];
                $totalNutrition['carbs'] += $nutrition['carbs_g'];
                $totalNutrition['fat'] += $nutrition['fat_g'];
                $totalNutrition['fiber'] += $nutrition['fiber_g'];
                $totalNutrition['sugar'] += $nutrition['sugar_g'];
                $totalNutrition['sodium'] += $nutrition['sodium_mg'] / 1000; // Convert to grams
            }
        }

        // Calculate per serving
        if ($this->servings > 0) {
            foreach ($totalNutrition as $key => $value) {
                $totalNutrition[$key] = round($value / $this->servings, 2);
            }
        }

        return $totalNutrition;
    }

    public function updateNutritionFromIngredients(): bool
    {
        $nutrition = $this->calculateNutritionFromIngredients();
        
        return $this->update([
            'calories_per_serving' => $nutrition['calories'],
            'protein_per_serving' => $nutrition['protein'],
            'carbs_per_serving' => $nutrition['carbs'],
            'fat_per_serving' => $nutrition['fat'],
            'fiber_per_serving' => $nutrition['fiber'],
            'sugar_per_serving' => $nutrition['sugar'],
            'sodium_per_serving' => $nutrition['sodium'],
        ]);
    }

    public function isSuitableForDiet(string $diet): bool
    {
        return in_array($diet, $this->dietary_preferences ?? []);
    }

    public function containsAllergen(string $allergen): bool
    {
        return in_array(strtolower($allergen), array_map('strtolower', $this->allergens ?? []));
    }

    public function getMacroDistribution(): array
    {
        $proteinCals = $this->protein_per_serving * 4;
        $carbsCals = $this->carbs_per_serving * 4;
        $fatCals = $this->fat_per_serving * 9;
        $totalCals = $this->calories_per_serving ?: ($proteinCals + $carbsCals + $fatCals);
        
        if ($totalCals == 0) {
            return ['protein' => 0, 'carbs' => 0, 'fat' => 0];
        }
        
        return [
            'protein' => round(($proteinCals / $totalCals) * 100, 1),
            'carbs' => round(($carbsCals / $totalCals) * 100, 1),
            'fat' => round(($fatCals / $totalCals) * 100, 1)
        ];
    }

    public function addRating(int $userId, int $rating, ?string $review = null): bool
    {
        // Check if user already rated this recipe
        $existingRating = $this->ratings()->where('user_id', $userId)->first();
        
        if ($existingRating) {
            // Update existing rating
            $existingRating->update([
                'rating' => $rating,
                'review' => $review
            ]);
        } else {
            // Create new rating
            $this->ratings()->create([
                'user_id' => $userId,
                'rating' => $rating,
                'review' => $review
            ]);
        }

        // Update recipe aggregated ratings
        $this->updateAggregatedRating();
        
        return true;
    }

    private function updateAggregatedRating(): void
    {
        $ratings = $this->ratings;
        
        $this->update([
            'total_ratings' => $ratings->count(),
            'total_reviews' => $ratings->whereNotNull('review')->count(),
            'average_rating' => $ratings->count() > 0 ? round($ratings->avg('rating'), 2) : 0
        ]);
    }

    public function duplicate(int $newUserId): Recipe
    {
        $newRecipe = $this->replicate();
        $newRecipe->created_by = $newUserId;
        $newRecipe->name = $this->name . ' (Copy)';
        $newRecipe->is_public = false;
        $newRecipe->average_rating = 0;
        $newRecipe->total_ratings = 0;
        $newRecipe->total_reviews = 0;
        $newRecipe->save();

        // Copy ingredients
        foreach ($this->ingredients as $ingredient) {
            $newIngredient = $ingredient->replicate();
            $newIngredient->recipe_id = $newRecipe->id;
            $newIngredient->save();
        }

        return $newRecipe;
    }

    public function getMainImageUrlAttribute(): ?string
    {
        if ($this->image_url) {
            return $this->image_url;
        }
        
        if (!empty($this->images) && is_array($this->images)) {
            return $this->images[0]['url'] ?? null;
        }
        
        return null;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (!empty($this->images) && is_array($this->images)) {
            return $this->images[0]['thumbnail_url'] ?? $this->images[0]['url'] ?? null;
        }
        
        return $this->image_url;
    }

    public function getAllImages(): array
    {
        $images = [];
        
        if ($this->image_url) {
            $images[] = [
                'url' => $this->image_url,
                'type' => 'main',
                'is_primary' => true
            ];
        }
        
        if (!empty($this->images) && is_array($this->images)) {
            foreach ($this->images as $index => $image) {
                $images[] = [
                    'url' => $image['url'],
                    'thumbnail_url' => $image['thumbnail_url'] ?? null,
                    'alt_text' => $image['alt_text'] ?? null,
                    'caption' => $image['caption'] ?? null,
                    'type' => $image['type'] ?? 'additional',
                    'is_primary' => ($index === 0 && !$this->image_url),
                    'order' => $image['order'] ?? $index
                ];
            }
        }
        
        return $images;
    }

    public function addImage(string $url, array $metadata = []): bool
    {
        $currentImages = $this->images ?? [];
        
        $imageData = array_merge([
            'url' => $url,
            'type' => 'additional',
            'order' => count($currentImages),
            'uploaded_at' => now()->toISOString()
        ], $metadata);
        
        $currentImages[] = $imageData;
        
        return $this->update(['images' => $currentImages]);
    }

    public function removeImage(string $url): bool
    {
        $currentImages = $this->images ?? [];
        
        $filteredImages = array_filter($currentImages, function($image) use ($url) {
            return $image['url'] !== $url;
        });
        
        // Reorder remaining images
        $reorderedImages = array_values($filteredImages);
        foreach ($reorderedImages as $index => &$image) {
            $image['order'] = $index;
        }
        
        return $this->update(['images' => $reorderedImages]);
    }

    public function reorderImages(array $orderedUrls): bool
    {
        $currentImages = $this->images ?? [];
        $reorderedImages = [];
        
        foreach ($orderedUrls as $index => $url) {
            foreach ($currentImages as $image) {
                if ($image['url'] === $url) {
                    $image['order'] = $index;
                    $reorderedImages[] = $image;
                    break;
                }
            }
        }
        
        return $this->update(['images' => $reorderedImages]);
    }

    public function updateImageMetadata(string $url, array $metadata): bool
    {
        $currentImages = $this->images ?? [];
        
        foreach ($currentImages as &$image) {
            if ($image['url'] === $url) {
                $image = array_merge($image, $metadata);
                break;
            }
        }
        
        return $this->update(['images' => $currentImages]);
    }
}