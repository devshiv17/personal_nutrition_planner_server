<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeRating extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipe_id',
        'user_id',
        'rating',
        'review',
        'helpful_votes',
        'total_votes'
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'helpful_votes' => 'integer',
            'total_votes' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the recipe this rating belongs to
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Get the user who made this rating
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get helpfulness ratio
     */
    public function getHelpfulnessRatioAttribute(): float
    {
        if ($this->total_votes == 0) {
            return 0;
        }
        
        return round(($this->helpful_votes / $this->total_votes) * 100, 1);
    }

    /**
     * Check if rating has review text
     */
    public function hasReview(): bool
    {
        return !empty(trim($this->review));
    }

    /**
     * Scope for ratings with reviews
     */
    public function scopeWithReviews($query)
    {
        return $query->whereNotNull('review')
                    ->where('review', '!=', '');
    }

    /**
     * Scope for recent ratings
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope for high ratings
     */
    public function scopeHighRating($query, int $minRating = 4)
    {
        return $query->where('rating', '>=', $minRating);
    }

    /**
     * Scope for specific user's ratings
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}