<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class FoodLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'food_id',
        'food_name',
        'quantity',
        'unit',
        'quantity_grams',
        'meal_type',
        'calories',
        'protein',
        'carbs',
        'fat',
        'fiber',
        'sugar',
        'sodium',
        'consumed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'quantity_grams' => 'decimal:2',
            'calories' => 'decimal:2',
            'protein' => 'decimal:2',
            'carbs' => 'decimal:2',
            'fat' => 'decimal:2',
            'fiber' => 'decimal:2',
            'sugar' => 'decimal:2',
            'sodium' => 'decimal:2',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the user who logged this food
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the food that was logged
     */
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    /**
     * Scope for specific date
     */
    public function scopeForDate($query, string $date)
    {
        return $query->whereDate('consumed_at', $date);
    }

    /**
     * Scope for date range
     */
    public function scopeInDateRange($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('consumed_at', [$startDate, $endDate]);
    }

    /**
     * Scope for specific meal type
     */
    public function scopeForMealType($query, string $mealType)
    {
        return $query->where('meal_type', $mealType);
    }

    /**
     * Scope for today's logs
     */
    public function scopeToday($query)
    {
        return $query->whereDate('consumed_at', Carbon::today());
    }

    /**
     * Scope for this week's logs
     */
    public function scopeThisWeek($query)
    {
        return $query->whereBetween('consumed_at', [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek(),
        ]);
    }

    /**
     * Get formatted consumed time
     */
    public function getConsumedTimeAttribute(): string
    {
        return $this->consumed_at->format('H:i');
    }

    /**
     * Get consumed date
     */
    public function getConsumedDateAttribute(): string
    {
        return $this->consumed_at->format('Y-m-d');
    }

    /**
     * Check if this log is from today
     */
    public function isTodayAttribute(): bool
    {
        return $this->consumed_at->isToday();
    }

    /**
     * Get macronutrient breakdown as percentages
     */
    public function getMacroBreakdownAttribute(): array
    {
        $totalCalories = $this->calories;
        
        if ($totalCalories <= 0) {
            return ['protein' => 0, 'carbs' => 0, 'fat' => 0];
        }

        return [
            'protein' => round(($this->protein * 4 / $totalCalories) * 100, 1),
            'carbs' => round(($this->carbs * 4 / $totalCalories) * 100, 1),
            'fat' => round(($this->fat * 9 / $totalCalories) * 100, 1),
        ];
    }
}