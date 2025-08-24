<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class UserGoal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'goal_type',
        'target_value',
        'target_unit',
        'current_value',
        'start_date',
        'target_date',
        'status',
        'priority',
        'description',
        'milestones',
        'progress_percentage',
        'last_updated'
    ];

    protected $casts = [
        'start_date' => 'date',
        'target_date' => 'date',
        'last_updated' => 'datetime',
        'milestones' => 'array',
        'target_value' => 'decimal:2',
        'current_value' => 'decimal:2',
        'progress_percentage' => 'decimal:2'
    ];

    // Goal type constants
    public const GOAL_TYPES = [
        'weight_loss' => 'Weight Loss',
        'weight_gain' => 'Weight Gain',
        'muscle_gain' => 'Muscle Gain',
        'fat_loss' => 'Fat Loss',
        'maintain_weight' => 'Maintain Weight',
        'improve_fitness' => 'Improve Fitness',
        'increase_steps' => 'Increase Daily Steps',
        'improve_sleep' => 'Improve Sleep',
        'increase_water_intake' => 'Increase Water Intake',
        'reduce_body_fat' => 'Reduce Body Fat',
        'increase_muscle_mass' => 'Increase Muscle Mass'
    ];

    public const STATUS_TYPES = [
        'active' => 'Active',
        'completed' => 'Completed',
        'paused' => 'Paused',
        'cancelled' => 'Cancelled'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    public function scopeDueSoon($query, $days = 7)
    {
        return $query->where('target_date', '<=', Carbon::now()->addDays($days))
                    ->where('target_date', '>=', Carbon::now());
    }

    public function scopeOverdue($query)
    {
        return $query->where('target_date', '<', Carbon::now())
                    ->where('status', 'active');
    }

    // Helper methods
    public function getGoalDisplayNameAttribute()
    {
        return self::GOAL_TYPES[$this->goal_type] ?? $this->goal_type;
    }

    public function getStatusDisplayNameAttribute()
    {
        return self::STATUS_TYPES[$this->status] ?? $this->status;
    }

    public function getDaysRemainingAttribute()
    {
        return Carbon::now()->diffInDays($this->target_date, false);
    }

    public function getDaysElapsedAttribute()
    {
        return $this->start_date->diffInDays(Carbon::now());
    }

    public function getTotalDaysAttribute()
    {
        return $this->start_date->diffInDays($this->target_date);
    }

    public function getTimeProgressAttribute()
    {
        $totalDays = $this->total_days;
        $elapsedDays = $this->days_elapsed;
        
        if ($totalDays <= 0) return 100;
        
        return min(100, ($elapsedDays / $totalDays) * 100);
    }

    public function isOverdue()
    {
        return $this->target_date < Carbon::now() && $this->status === 'active';
    }

    public function isDueSoon($days = 7)
    {
        return $this->target_date <= Carbon::now()->addDays($days) && 
               $this->target_date >= Carbon::now() && 
               $this->status === 'active';
    }

    public function updateProgress()
    {
        if (!$this->target_value || !$this->current_value) {
            return;
        }

        // Calculate progress based on goal type
        $progress = 0;
        
        switch ($this->goal_type) {
            case 'weight_loss':
            case 'fat_loss':
            case 'reduce_body_fat':
                // For reduction goals, progress is based on how much has been lost
                $initialValue = $this->getInitialValue();
                if ($initialValue && $initialValue > $this->target_value) {
                    $totalToLose = $initialValue - $this->target_value;
                    $actuallyLost = $initialValue - $this->current_value;
                    $progress = min(100, ($actuallyLost / $totalToLose) * 100);
                }
                break;
                
            case 'weight_gain':
            case 'muscle_gain':
            case 'increase_muscle_mass':
            case 'increase_steps':
            case 'increase_water_intake':
                // For increase goals, progress is based on how much has been gained
                $initialValue = $this->getInitialValue();
                if ($initialValue && $this->target_value > $initialValue) {
                    $totalToGain = $this->target_value - $initialValue;
                    $actuallyGained = $this->current_value - $initialValue;
                    $progress = min(100, ($actuallyGained / $totalToGain) * 100);
                }
                break;
                
            case 'maintain_weight':
                // For maintenance goals, progress is based on staying within range
                $targetRange = $this->target_value * 0.05; // 5% range
                $difference = abs($this->current_value - $this->target_value);
                $progress = max(0, 100 - (($difference / $targetRange) * 100));
                break;
        }

        $this->update([
            'progress_percentage' => round(max(0, min(100, $progress)), 2),
            'last_updated' => now()
        ]);
    }

    private function getInitialValue()
    {
        // Get the metric type associated with this goal
        $metricType = $this->getAssociatedMetricType();
        
        if (!$metricType) return null;
        
        // Get the first metric recorded on or after the start date
        $metric = HealthMetric::forUser($this->user_id)
            ->ofType($metricType)
            ->actualMeasurements()
            ->where('recorded_date', '>=', $this->start_date)
            ->orderBy('recorded_date', 'asc')
            ->first();
            
        return $metric ? $metric->value : null;
    }

    private function getAssociatedMetricType()
    {
        $mapping = [
            'weight_loss' => 'weight',
            'weight_gain' => 'weight',
            'maintain_weight' => 'weight',
            'muscle_gain' => 'muscle_mass',
            'increase_muscle_mass' => 'muscle_mass',
            'fat_loss' => 'body_fat',
            'reduce_body_fat' => 'body_fat',
            'increase_steps' => 'steps',
            'increase_water_intake' => 'water_intake',
            'improve_sleep' => 'sleep_hours'
        ];
        
        return $mapping[$this->goal_type] ?? null;
    }

    public static function updateProgressForUser($userId)
    {
        $goals = self::forUser($userId)->active()->get();
        
        foreach ($goals as $goal) {
            $metricType = $goal->getAssociatedMetricType();
            if ($metricType) {
                $latestMetric = HealthMetric::getLatestForUser($userId, $metricType);
                if ($latestMetric) {
                    $goal->update(['current_value' => $latestMetric->value]);
                    $goal->updateProgress();
                }
            }
        }
    }
}