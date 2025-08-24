<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class HealthMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'metric_type',
        'value',
        'unit',
        'recorded_date',
        'recorded_time',
        'notes',
        'metadata',
        'is_goal'
    ];

    protected $casts = [
        'recorded_date' => 'date',
        'recorded_time' => 'datetime:H:i:s',
        'metadata' => 'array',
        'is_goal' => 'boolean',
        'value' => 'decimal:2'
    ];

    // Metric type constants
    public const METRIC_TYPES = [
        'weight' => 'Weight',
        'height' => 'Height',
        'body_fat' => 'Body Fat Percentage',
        'muscle_mass' => 'Muscle Mass',
        'bmi' => 'BMI',
        'waist_circumference' => 'Waist Circumference',
        'hip_circumference' => 'Hip Circumference',
        'chest_circumference' => 'Chest Circumference',
        'arm_circumference' => 'Arm Circumference',
        'thigh_circumference' => 'Thigh Circumference',
        'neck_circumference' => 'Neck Circumference',
        'blood_pressure_systolic' => 'Blood Pressure (Systolic)',
        'blood_pressure_diastolic' => 'Blood Pressure (Diastolic)',
        'heart_rate' => 'Heart Rate',
        'steps' => 'Daily Steps',
        'sleep_hours' => 'Sleep Hours',
        'water_intake' => 'Water Intake'
    ];

    // Default units for each metric type
    public const DEFAULT_UNITS = [
        'weight' => 'kg',
        'height' => 'cm',
        'body_fat' => '%',
        'muscle_mass' => 'kg',
        'bmi' => 'kg/m²',
        'waist_circumference' => 'cm',
        'hip_circumference' => 'cm',
        'chest_circumference' => 'cm',
        'arm_circumference' => 'cm',
        'thigh_circumference' => 'cm',
        'neck_circumference' => 'cm',
        'blood_pressure_systolic' => 'mmHg',
        'blood_pressure_diastolic' => 'mmHg',
        'heart_rate' => 'bpm',
        'steps' => 'steps',
        'sleep_hours' => 'hours',
        'water_intake' => 'ml'
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

    public function scopeOfType($query, $type)
    {
        return $query->where('metric_type', $type);
    }

    public function scopeActualMeasurements($query)
    {
        return $query->where('is_goal', false);
    }

    public function scopeGoals($query)
    {
        return $query->where('is_goal', true);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('recorded_date', [$startDate, $endDate]);
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('recorded_date', '>=', Carbon::now()->subDays($days));
    }

    // Helper methods
    public function getFormattedValueAttribute()
    {
        return number_format($this->value, 2) . ' ' . $this->unit;
    }

    public function getMetricDisplayNameAttribute()
    {
        return self::METRIC_TYPES[$this->metric_type] ?? $this->metric_type;
    }

    public static function getLatestForUser($userId, $metricType)
    {
        return self::forUser($userId)
            ->ofType($metricType)
            ->actualMeasurements()
            ->orderBy('recorded_date', 'desc')
            ->orderBy('recorded_time', 'desc')
            ->first();
    }

    public static function getHistoryForUser($userId, $metricType, $days = 30)
    {
        return self::forUser($userId)
            ->ofType($metricType)
            ->actualMeasurements()
            ->recent($days)
            ->orderBy('recorded_date', 'asc')
            ->get();
    }

    public static function calculateTrend($userId, $metricType, $days = 30)
    {
        $metrics = self::getHistoryForUser($userId, $metricType, $days);
        
        if ($metrics->count() < 2) {
            return [
                'trend' => 'insufficient_data',
                'percentage_change' => 0,
                'absolute_change' => 0
            ];
        }

        $first = $metrics->first();
        $last = $metrics->last();
        
        $absoluteChange = $last->value - $first->value;
        $percentageChange = ($first->value != 0) ? (($absoluteChange / $first->value) * 100) : 0;
        
        $trend = 'stable';
        if (abs($percentageChange) > 1) {
            $trend = $percentageChange > 0 ? 'increasing' : 'decreasing';
        }
        
        return [
            'trend' => $trend,
            'percentage_change' => round($percentageChange, 2),
            'absolute_change' => round($absoluteChange, 2),
            'first_value' => $first->value,
            'last_value' => $last->value,
            'first_date' => $first->recorded_date,
            'last_date' => $last->recorded_date
        ];
    }
}