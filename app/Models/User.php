<?php

namespace App\Models;

use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password_hash',
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'height_cm',
        'current_weight_kg',
        'target_weight_kg',
        'activity_level',
        'primary_goal',
        'target_timeline_weeks',
        'dietary_preference',
        'timezone',
        'locale',
        'email_notifications',
        'push_notifications',
        'is_active',
        'last_login_at',
        'last_login_ip',
        'last_activity_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_hash' => 'hashed',
            'date_of_birth' => 'date',
            'height_cm' => 'decimal:2',
            'current_weight_kg' => 'decimal:2',
            'target_weight_kg' => 'decimal:2',
            'bmr_calories' => 'decimal:2',
            'tdee_calories' => 'decimal:2',
            'is_active' => 'boolean',
            'email_notifications' => 'boolean',
            'push_notifications' => 'boolean',
            'profile_completed' => 'boolean',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the user's sessions.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * Get the user's active sessions.
     */
    public function activeSessions(): HasMany
    {
        return $this->sessions()->active();
    }

    /**
     * Get the user's health metrics.
     */
    public function healthMetrics(): HasMany
    {
        return $this->hasMany(HealthMetric::class);
    }

    /**
     * Get the user's health metrics for a specific type.
     */
    public function userHealthMetrics(): HasMany
    {
        return $this->hasMany(UserHealthMetric::class);
    }

    /**
     * Get the user's goals.
     */
    public function goals(): HasMany
    {
        return $this->hasMany(UserGoal::class);
    }

    /**
     * Get the user's active goals.
     */
    public function activeGoals(): HasMany
    {
        return $this->goals()->active();
    }

    /**
     * Get the user's profile completion.
     */
    public function profileCompletion(): HasOne
    {
        return $this->hasOne(ProfileCompletion::class);
    }

    /**
     * Get the user's dietary preferences.
     */
    public function dietaryPreferences(): HasOne
    {
        return $this->hasOne(UserDietaryPreference::class);
    }

    /**
     * Get the user's meal plans.
     */
    public function mealPlans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }

    /**
     * Get the user's active meal plans.
     */
    public function activeMealPlans(): HasMany
    {
        return $this->mealPlans()->active();
    }

    /**
     * Get the user's current meal plans.
     */
    public function currentMealPlans(): HasMany
    {
        return $this->mealPlans()->current();
    }

    /**
     * Get the user's favorite recipes.
     */
    public function favoriteRecipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'user_favorite_recipes')->withTimestamps();
    }

    /**
     * Get the user's favorite foods.
     */
    public function favoriteFoods(): BelongsToMany
    {
        return $this->belongsToMany(Food::class, 'user_favorite_foods')
                    ->withTimestamps();
    }

    /**
     * Get the user's favorite recipes.
     */
    public function favoriteRecipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'user_favorite_recipes')
                    ->withTimestamps();
    }

    /**
     * Get the user's favorite recipe collections.
     */
    public function favoriteCollections(): BelongsToMany
    {
        return $this->belongsToMany(RecipeCollection::class, 'user_favorite_collections')
                    ->withTimestamps();
    }

    /**
     * Get recipes created by this user.
     */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'created_by');
    }

    /**
     * Get recipe collections created by this user.
     */
    public function recipeCollections(): HasMany
    {
        return $this->hasMany(RecipeCollection::class, 'created_by');
    }

    /**
     * Get foods created by this user.
     */
    public function createdFoods(): HasMany
    {
        return $this->hasMany(Food::class, 'created_by');
    }

    /**
     * Get food logs for this user.
     */
    public function foodLogs(): HasMany
    {
        return $this->hasMany(FoodLog::class);
    }

    /**
     * Send the email verification notification.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Calculate and update profile completion percentage.
     */
    public function updateProfileCompletion()
    {
        return ProfileCompletion::calculateCompletionForUser($this->id);
    }

    /**
     * Get the user's BMI.
     */
    public function getBmiAttribute()
    {
        if ($this->height_cm && $this->current_weight_kg) {
            $heightM = $this->height_cm / 100;
            return round($this->current_weight_kg / ($heightM * $heightM), 1);
        }
        return null;
    }

    /**
     * Get the latest weight metric.
     */
    public function getLatestWeight()
    {
        return $this->healthMetrics()
            ->where('metric_type', 'weight')
            ->where('is_goal', false)
            ->orderBy('recorded_date', 'desc')
            ->first();
    }

    /**
     * Get health insights for the user.
     */
    public function getHealthInsights()
    {
        $insights = [];
        
        // Weight trend analysis
        $weightTrend = HealthMetric::calculateTrend($this->id, 'weight', 30);
        if ($weightTrend['trend'] !== 'insufficient_data') {
            $insights[] = [
                'type' => 'weight_trend',
                'title' => 'Weight Trend (30 days)',
                'message' => $this->generateWeightTrendMessage($weightTrend),
                'trend' => $weightTrend['trend'],
                'value' => $weightTrend['percentage_change']
            ];
        }

        // Goal progress insights
        $activeGoals = $this->activeGoals()->get();
        foreach ($activeGoals as $goal) {
            if ($goal->progress_percentage > 75) {
                $insights[] = [
                    'type' => 'goal_progress',
                    'title' => 'Goal Almost Complete!',
                    'message' => "You're {$goal->progress_percentage}% towards your {$goal->goal_display_name} goal!",
                    'goal_id' => $goal->id,
                    'progress' => $goal->progress_percentage
                ];
            } elseif ($goal->isOverdue()) {
                $insights[] = [
                    'type' => 'goal_overdue',
                    'title' => 'Goal Past Due Date',
                    'message' => "Your {$goal->goal_display_name} goal was due {$goal->target_date->diffForHumans()}",
                    'goal_id' => $goal->id,
                    'days_overdue' => abs($goal->days_remaining)
                ];
            }
        }

        // Profile completion insight
        $profileCompletion = $this->profileCompletion;
        if ($profileCompletion && $profileCompletion->completion_percentage < 80) {
            $nextSection = $profileCompletion->getNextSection();
            if ($nextSection) {
                $insights[] = [
                    'type' => 'profile_completion',
                    'title' => 'Complete Your Profile',
                    'message' => "Complete your {$nextSection['name']} to get better recommendations",
                    'completion_percentage' => $profileCompletion->completion_percentage,
                    'next_section' => $nextSection
                ];
            }
        }

        return $insights;
    }

    private function generateWeightTrendMessage($trend)
    {
        $direction = $trend['trend'];
        $change = abs($trend['percentage_change']);
        $unit = 'kg'; // Could be dynamic based on user preference
        
        switch ($direction) {
            case 'increasing':
                return "Your weight has increased by {$change}% ({$trend['absolute_change']} {$unit}) over the last 30 days";
            case 'decreasing':
                return "Your weight has decreased by {$change}% ({$trend['absolute_change']} {$unit}) over the last 30 days";
            case 'stable':
                return "Your weight has remained stable over the last 30 days";
            default:
                return "Not enough data to determine weight trend";
        }
    }
}
