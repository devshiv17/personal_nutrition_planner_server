<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\UserHealthMetric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends BaseApiController
{
    /**
     * Get user profile
     */
    public function profile(): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            
            return $this->successResponse(
                $user->makeHidden(['password_hash']),
                'Profile retrieved successfully'
            );
        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to retrieve profile');
        }
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            $validatedData = $this->validateRequest($request, [
                'first_name' => 'sometimes|required|string|max:100',
                'last_name' => 'sometimes|required|string|max:100',
                'email' => [
                    'sometimes',
                    'required',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique('users')->ignore($user->id)
                ],
                'date_of_birth' => 'sometimes|nullable|date|before:today',
                'gender' => 'sometimes|nullable|in:male,female,other,prefer_not_to_say',
                'height_cm' => 'sometimes|nullable|numeric|min:50|max:300',
                'current_weight_kg' => 'sometimes|nullable|numeric|min:20|max:500',
                'target_weight_kg' => 'sometimes|nullable|numeric|min:20|max:500',
                'activity_level' => 'sometimes|nullable|in:sedentary,lightly_active,moderately_active,very_active',
                'primary_goal' => 'sometimes|nullable|in:weight_loss,weight_gain,maintenance,muscle_gain,health_management',
                'target_timeline_weeks' => 'sometimes|nullable|integer|min:1|max:104',
                'dietary_preference' => 'sometimes|nullable|in:keto,mediterranean,vegan,diabetic_friendly',
                'timezone' => 'sometimes|nullable|string|max:50',
                'locale' => 'sometimes|nullable|string|max:10',
                'email_notifications' => 'sometimes|boolean',
                'push_notifications' => 'sometimes|boolean',
                'current_password' => 'sometimes|required_with:new_password|string',
                'new_password' => 'sometimes|string|min:8|confirmed',
            ]);

            // Handle password change
            if (isset($validatedData['new_password'])) {
                if (!Hash::check($validatedData['current_password'], $user->password_hash)) {
                    return $this->errorResponse('Current password is incorrect', 400);
                }
                $validatedData['password_hash'] = Hash::make($validatedData['new_password']);
                unset($validatedData['current_password'], $validatedData['new_password'], $validatedData['new_password_confirmation']);
            }

            $user->update($validatedData);

            // Recalculate BMR/TDEE if relevant data changed
            if (isset($validatedData['height_cm']) || isset($validatedData['current_weight_kg']) || 
                isset($validatedData['date_of_birth']) || isset($validatedData['gender']) || 
                isset($validatedData['activity_level']) || isset($validatedData['primary_goal'])) {
                $this->calculateMetrics($user->fresh());
            }

            return $this->successResponse(
                $user->fresh()->makeHidden(['password_hash']),
                'Profile updated successfully'
            );

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to update profile');
        }
    }

    /**
     * Get user health metrics
     */
    public function getMetrics(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            $paginationParams = $this->getPaginationParams($request);

            $query = UserHealthMetric::where('user_id', $user->id);

            // Apply filters
            $allowedFilters = [
                'metric_type' => 'metric_type',
                'source' => 'source',
                'date_from' => ['method' => 'whereDate', 'column' => 'recorded_at'],
                'date_to' => ['method' => 'whereDate', 'column' => 'recorded_at'],
            ];
            $query = $this->applyFilters($query, $request, $allowedFilters);

            // Apply date range filtering
            if ($request->has('date_from')) {
                $query->whereDate('recorded_at', '>=', $request->get('date_from'));
            }
            if ($request->has('date_to')) {
                $query->whereDate('recorded_at', '<=', $request->get('date_to'));
            }

            // Apply sorting
            $allowedSortColumns = ['recorded_at', 'metric_type', 'value', 'created_at'];
            $query = $this->applySorting($query, $request, $allowedSortColumns, 'recorded_at', 'desc');

            $metrics = $query->paginate($paginationParams['per_page']);

            return $this->paginatedResponse($metrics, 'Health metrics retrieved successfully');

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to retrieve health metrics');
        }
    }

    /**
     * Store new health metric
     */
    public function storeMetrics(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            $validatedData = $this->validateRequest($request, [
                'metric_type' => 'required|in:weight,body_fat,muscle_mass,blood_pressure_systolic,blood_pressure_diastolic,glucose,cholesterol,heart_rate',
                'value' => 'required|numeric|min:0',
                'unit' => 'required|string|max:20',
                'recorded_at' => 'sometimes|date',
                'source' => 'sometimes|in:manual,device,api',
                'device_id' => 'sometimes|nullable|string|max:100',
                'notes' => 'sometimes|nullable|string|max:1000',
            ]);

            $validatedData['user_id'] = $user->id;
            $validatedData['recorded_at'] = $validatedData['recorded_at'] ?? now();
            $validatedData['source'] = $validatedData['source'] ?? 'manual';

            $metric = UserHealthMetric::create($validatedData);

            // Update user's current weight if this is a weight metric
            if ($validatedData['metric_type'] === 'weight') {
                $user->update(['current_weight_kg' => $validatedData['value']]);
                $this->calculateMetrics($user->fresh());
            }

            return $this->successResponse(
                $metric,
                'Health metric recorded successfully',
                201
            );

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to record health metric');
        }
    }

    /**
     * Get user statistics summary
     */
    public function getStats(): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            // Get latest metrics
            $latestWeight = UserHealthMetric::where('user_id', $user->id)
                ->where('metric_type', 'weight')
                ->latest('recorded_at')
                ->first();

            $latestBodyFat = UserHealthMetric::where('user_id', $user->id)
                ->where('metric_type', 'body_fat')
                ->latest('recorded_at')
                ->first();

            // Calculate progress toward goal
            $weightProgress = null;
            if ($user->target_weight_kg && $user->current_weight_kg) {
                $startWeight = $user->current_weight_kg;
                $targetWeight = $user->target_weight_kg;
                $currentWeight = $latestWeight ? $latestWeight->value : $user->current_weight_kg;
                
                $totalChange = abs($targetWeight - $startWeight);
                $currentChange = abs($currentWeight - $startWeight);
                $weightProgress = $totalChange > 0 ? ($currentChange / $totalChange) * 100 : 0;
            }

            $stats = [
                'profile_completion' => $this->calculateProfileCompletion($user),
                'current_metrics' => [
                    'weight' => $latestWeight ? [
                        'value' => $latestWeight->value,
                        'unit' => $latestWeight->unit,
                        'recorded_at' => $latestWeight->recorded_at,
                    ] : null,
                    'body_fat' => $latestBodyFat ? [
                        'value' => $latestBodyFat->value,
                        'unit' => $latestBodyFat->unit,
                        'recorded_at' => $latestBodyFat->recorded_at,
                    ] : null,
                ],
                'calculated_metrics' => [
                    'bmr_calories' => $user->bmr_calories,
                    'tdee_calories' => $user->tdee_calories,
                    'daily_calorie_target' => $user->daily_calorie_target,
                ],
                'goals' => [
                    'target_weight_kg' => $user->target_weight_kg,
                    'primary_goal' => $user->primary_goal,
                    'target_timeline_weeks' => $user->target_timeline_weeks,
                    'weight_progress_percentage' => $weightProgress,
                ],
            ];

            return $this->successResponse($stats, 'User statistics retrieved successfully');

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to retrieve user statistics');
        }
    }

    /**
     * Calculate BMR and TDEE for user
     */
    private function calculateMetrics(User $user): void
    {
        if (!$user->height_cm || !$user->current_weight_kg || !$user->date_of_birth || !$user->gender) {
            return;
        }

        $age = now()->diffInYears($user->date_of_birth);
        $weight = $user->current_weight_kg;
        $height = $user->height_cm;

        // Mifflin-St Jeor Equation
        if ($user->gender === 'male') {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) + 5;
        } else {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) - 161;
        }

        // Activity multipliers
        $activityMultipliers = [
            'sedentary' => 1.2,
            'lightly_active' => 1.375,
            'moderately_active' => 1.55,
            'very_active' => 1.725,
        ];

        $tdee = $bmr * ($activityMultipliers[$user->activity_level] ?? 1.2);

        // Set daily calorie target based on goal
        $calorieAdjustments = [
            'weight_loss' => -500,
            'weight_gain' => 500,
            'muscle_gain' => 300,
            'maintenance' => 0,
            'health_management' => 0,
        ];

        $dailyTarget = $tdee + ($calorieAdjustments[$user->primary_goal] ?? 0);

        $user->update([
            'bmr_calories' => round($bmr, 2),
            'tdee_calories' => round($tdee, 2),
            'daily_calorie_target' => round($dailyTarget),
        ]);
    }

    /**
     * Calculate profile completion percentage
     */
    private function calculateProfileCompletion(User $user): int
    {
        $requiredFields = [
            'first_name', 'last_name', 'email', 'date_of_birth', 'gender',
            'height_cm', 'current_weight_kg', 'activity_level', 'primary_goal',
            'dietary_preference'
        ];

        $completedFields = 0;
        foreach ($requiredFields as $field) {
            if (!empty($user->{$field})) {
                $completedFields++;
            }
        }

        return round(($completedFields / count($requiredFields)) * 100);
    }
}