<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileCompletion extends Model
{
    use HasFactory;

    protected $table = 'profile_completion';

    protected $fillable = [
        'user_id',
        'completed_sections',
        'completion_percentage',
        'pending_sections',
        'last_updated'
    ];

    protected $casts = [
        'completed_sections' => 'array',
        'pending_sections' => 'array',
        'last_updated' => 'datetime'
    ];

    // Profile sections that contribute to completion
    public const PROFILE_SECTIONS = [
        'basic_info' => [
            'name' => 'Basic Information',
            'weight' => 10,
            'fields' => ['first_name', 'last_name', 'email', 'date_of_birth']
        ],
        'physical_metrics' => [
            'name' => 'Physical Metrics',
            'weight' => 20,
            'fields' => ['height', 'weight', 'gender']
        ],
        'health_conditions' => [
            'name' => 'Health Conditions',
            'weight' => 15,
            'fields' => ['allergies', 'medical_conditions', 'medications']
        ],
        'dietary_preferences' => [
            'name' => 'Dietary Preferences',
            'weight' => 15,
            'fields' => ['dietary_restrictions', 'food_preferences', 'cuisine_preferences']
        ],
        'fitness_goals' => [
            'name' => 'Fitness Goals',
            'weight' => 20,
            'fields' => ['primary_goal', 'target_weight', 'activity_level']
        ],
        'lifestyle' => [
            'name' => 'Lifestyle Information',
            'weight' => 10,
            'fields' => ['sleep_hours', 'stress_level', 'smoking_status', 'alcohol_consumption']
        ],
        'preferences' => [
            'name' => 'App Preferences',
            'weight' => 5,
            'fields' => ['notification_preferences', 'measurement_units', 'privacy_settings']
        ],
        'initial_measurements' => [
            'name' => 'Initial Health Measurements',
            'weight' => 5,
            'fields' => ['initial_weight_logged', 'initial_body_fat_logged']
        ]
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function calculateCompletionForUser($userId)
    {
        $user = User::with('userHealthMetrics')->find($userId);
        
        if (!$user) {
            return null;
        }

        $completedSections = [];
        $pendingSections = [];
        $totalWeight = 0;
        $completedWeight = 0;

        foreach (self::PROFILE_SECTIONS as $sectionKey => $section) {
            $totalWeight += $section['weight'];
            $isCompleted = self::isSectionCompleted($user, $sectionKey, $section['fields']);
            
            if ($isCompleted) {
                $completedSections[] = $sectionKey;
                $completedWeight += $section['weight'];
            } else {
                $pendingSections[] = $sectionKey;
            }
        }

        $completionPercentage = $totalWeight > 0 ? round(($completedWeight / $totalWeight) * 100) : 0;

        // Update or create profile completion record
        $profileCompletion = self::updateOrCreate(
            ['user_id' => $userId],
            [
                'completed_sections' => $completedSections,
                'pending_sections' => $pendingSections,
                'completion_percentage' => $completionPercentage,
                'last_updated' => now()
            ]
        );

        return $profileCompletion;
    }

    private static function isSectionCompleted($user, $sectionKey, $fields)
    {
        switch ($sectionKey) {
            case 'basic_info':
                return !empty($user->first_name) && 
                       !empty($user->last_name) && 
                       !empty($user->email) && 
                       !empty($user->date_of_birth);

            case 'physical_metrics':
                $metrics = $user->userHealthMetrics;
                $hasHeight = $metrics->where('metric_type', 'height')->isNotEmpty();
                $hasWeight = $metrics->where('metric_type', 'weight')->isNotEmpty();
                return $hasHeight && $hasWeight && !empty($user->gender);

            case 'health_conditions':
                // Check if user has provided health information (even if "none")
                return !empty($user->allergies) || 
                       !empty($user->medical_conditions) || 
                       !empty($user->medications) ||
                       $user->health_info_completed === true;

            case 'dietary_preferences':
                return !empty($user->dietary_restrictions) || 
                       !empty($user->food_preferences) ||
                       $user->dietary_preferences_completed === true;

            case 'fitness_goals':
                return !empty($user->primary_goal) && 
                       !empty($user->activity_level);

            case 'lifestyle':
                return !empty($user->sleep_hours) || 
                       $user->lifestyle_info_completed === true;

            case 'preferences':
                return !empty($user->notification_preferences) || 
                       !empty($user->measurement_units) ||
                       $user->preferences_completed === true;

            case 'initial_measurements':
                $metrics = $user->userHealthMetrics;
                return $metrics->where('metric_type', 'weight')->isNotEmpty();

            default:
                return false;
        }
    }

    public function getCompletionDetails()
    {
        $details = [];
        
        foreach (self::PROFILE_SECTIONS as $sectionKey => $section) {
            $isCompleted = in_array($sectionKey, $this->completed_sections ?? []);
            $details[] = [
                'key' => $sectionKey,
                'name' => $section['name'],
                'weight' => $section['weight'],
                'completed' => $isCompleted,
                'required_fields' => $section['fields']
            ];
        }
        
        return $details;
    }

    public function getNextSection()
    {
        if (empty($this->pending_sections)) {
            return null;
        }

        $nextSectionKey = $this->pending_sections[0];
        return [
            'key' => $nextSectionKey,
            'name' => self::PROFILE_SECTIONS[$nextSectionKey]['name'] ?? $nextSectionKey,
            'weight' => self::PROFILE_SECTIONS[$nextSectionKey]['weight'] ?? 0
        ];
    }

    public function isProfileComplete()
    {
        return $this->completion_percentage >= 100;
    }

    public function getMissingCriticalSections()
    {
        $critical = ['basic_info', 'physical_metrics', 'fitness_goals'];
        return array_intersect($critical, $this->pending_sections ?? []);
    }
}