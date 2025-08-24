<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecipeCollection extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'image_url',
        'created_by',
        'is_public',
        'is_featured',
        'tags',
        'color_theme'
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'tags' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the user who created this collection
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get recipes in this collection
     */
    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'recipe_collection_items')
                    ->withPivot(['order', 'notes'])
                    ->withTimestamps()
                    ->orderBy('pivot_order');
    }

    /**
     * Get users who have favorited this collection
     */
    public function favoritedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_favorite_collections')
                    ->withTimestamps();
    }

    // Scopes
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('created_by', $userId);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where(function($q) use ($userId) {
            $q->where('created_by', $userId)
              ->orWhere('is_public', true);
        });
    }

    public function scopeWithTag($query, string $tag)
    {
        return $query->whereJsonContains('tags', $tag);
    }

    // Helper methods
    public function addRecipe(Recipe $recipe, int $order = null, string $notes = null): void
    {
        if (!$this->recipes->contains($recipe->id)) {
            $order = $order ?? ($this->recipes()->count() + 1);
            
            $this->recipes()->attach($recipe->id, [
                'order' => $order,
                'notes' => $notes
            ]);
        }
    }

    public function removeRecipe(Recipe $recipe): void
    {
        $this->recipes()->detach($recipe->id);
        
        // Reorder remaining recipes
        $this->reorderRecipes();
    }

    public function reorderRecipes(): void
    {
        $recipes = $this->recipes()->orderBy('pivot_order')->get();
        
        foreach ($recipes as $index => $recipe) {
            $this->recipes()->updateExistingPivot($recipe->id, [
                'order' => $index + 1
            ]);
        }
    }

    public function getTotalNutrition(): array
    {
        $totals = [
            'calories' => 0,
            'protein' => 0,
            'carbs' => 0,
            'fat' => 0,
            'fiber' => 0,
            'sugar' => 0,
            'sodium' => 0
        ];

        foreach ($this->recipes as $recipe) {
            $totals['calories'] += $recipe->calories_per_serving ?? 0;
            $totals['protein'] += $recipe->protein_per_serving ?? 0;
            $totals['carbs'] += $recipe->carbs_per_serving ?? 0;
            $totals['fat'] += $recipe->fat_per_serving ?? 0;
            $totals['fiber'] += $recipe->fiber_per_serving ?? 0;
            $totals['sugar'] += $recipe->sugar_per_serving ?? 0;
            $totals['sodium'] += $recipe->sodium_per_serving ?? 0;
        }

        return $totals;
    }

    public function getAverageRating(): float
    {
        $recipes = $this->recipes;
        
        if ($recipes->isEmpty()) {
            return 0;
        }

        $totalRating = $recipes->sum('average_rating');
        return round($totalRating / $recipes->count(), 2);
    }

    public function getTotalCookingTime(): int
    {
        return $this->recipes->sum('total_time_minutes');
    }

    public function getDietaryPreferences(): array
    {
        $allPreferences = [];
        
        foreach ($this->recipes as $recipe) {
            $preferences = $recipe->dietary_preferences ?? [];
            $allPreferences = array_merge($allPreferences, $preferences);
        }

        return array_values(array_unique($allPreferences));
    }

    public function getAllergens(): array
    {
        $allAllergens = [];
        
        foreach ($this->recipes as $recipe) {
            $allergens = $recipe->allergens ?? [];
            $allAllergens = array_merge($allAllergens, $allergens);
        }

        return array_values(array_unique($allAllergens));
    }

    public function duplicate(int $newUserId): RecipeCollection
    {
        $newCollection = $this->replicate();
        $newCollection->created_by = $newUserId;
        $newCollection->name = $this->name . ' (Copy)';
        $newCollection->is_public = false;
        $newCollection->is_featured = false;
        $newCollection->save();

        // Copy recipe associations
        foreach ($this->recipes as $recipe) {
            $newCollection->addRecipe(
                $recipe, 
                $recipe->pivot->order, 
                $recipe->pivot->notes
            );
        }

        return $newCollection;
    }
}