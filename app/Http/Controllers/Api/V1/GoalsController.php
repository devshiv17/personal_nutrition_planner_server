<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\UserGoal;
use App\Models\HealthMetric;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class GoalsController extends BaseApiController
{
    /**
     * Get all goals for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $status = $request->query('status');
            
            $query = UserGoal::forUser($user->id);
            
            if ($status) {
                $query->where('status', $status);
            }
            
            $goals = $query->byPriority()
                          ->orderBy('created_at', 'desc')
                          ->get();

            return $this->success($goals, 'Goals retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve goals', 500, $e->getMessage());
        }
    }

    /**
     * Store a new goal
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $validatedData = $request->validate([
                'goal_type' => ['required', Rule::in(array_keys(UserGoal::GOAL_TYPES))],
                'target_value' => 'nullable|numeric|min:0',
                'target_unit' => 'nullable|string|max:20',
                'start_date' => 'required|date',
                'target_date' => 'required|date|after:start_date',
                'priority' => 'integer|min:1|max:5',
                'description' => 'nullable|string|max:1000',
                'milestones' => 'nullable|array'
            ]);

            $validatedData['user_id'] = $user->id;
            $validatedData['priority'] = $validatedData['priority'] ?? 1;

            // Set initial current_value based on latest metric if applicable
            $metricType = $this->getAssociatedMetricType($validatedData['goal_type']);
            if ($metricType) {
                $latestMetric = HealthMetric::getLatestForUser($user->id, $metricType);
                if ($latestMetric) {
                    $validatedData['current_value'] = $latestMetric->value;
                }
            }

            $goal = UserGoal::create($validatedData);
            
            // Calculate initial progress
            $goal->updateProgress();

            return $this->success($goal, 'Goal created successfully', 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create goal', 500, $e->getMessage());
        }
    }

    /**
     * Get a specific goal
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            
            $goal = UserGoal::forUser($user->id)->findOrFail($id);

            // Get related metrics history if applicable
            $metricType = $this->getAssociatedMetricType($goal->goal_type);
            $metricsHistory = null;
            
            if ($metricType) {
                $metricsHistory = HealthMetric::forUser($user->id)
                    ->ofType($metricType)
                    ->actualMeasurements()
                    ->where('recorded_date', '>=', $goal->start_date)
                    ->orderBy('recorded_date', 'asc')
                    ->get();
            }

            return $this->success([
                'goal' => $goal,
                'metrics_history' => $metricsHistory,
                'associated_metric_type' => $metricType
            ], 'Goal retrieved successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Goal not found', 404);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve goal', 500, $e->getMessage());
        }
    }

    /**
     * Update a goal
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            
            $goal = UserGoal::forUser($user->id)->findOrFail($id);

            $validatedData = $request->validate([
                'target_value' => 'nullable|numeric|min:0',
                'target_unit' => 'nullable|string|max:20',
                'target_date' => 'required|date',
                'status' => ['nullable', Rule::in(array_keys(UserGoal::STATUS_TYPES))],
                'priority' => 'integer|min:1|max:5',
                'description' => 'nullable|string|max:1000',
                'milestones' => 'nullable|array'
            ]);

            $goal->update($validatedData);
            
            // Recalculate progress if target changed
            if (isset($validatedData['target_value']) || isset($validatedData['target_date'])) {
                $goal->updateProgress();
            }

            return $this->success($goal, 'Goal updated successfully');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Goal not found', 404);
        } catch (\Exception $e) {
            return $this->error('Failed to update goal', 500, $e->getMessage());
        }
    }

    /**
     * Delete a goal
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            
            $goal = UserGoal::forUser($user->id)->findOrFail($id);
            $goal->delete();

            return $this->success(null, 'Goal deleted successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Goal not found', 404);
        } catch (\Exception $e) {
            return $this->error('Failed to delete goal', 500, $e->getMessage());
        }
    }

    /**
     * Update goal progress manually
     */
    public function updateProgress(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            
            $goal = UserGoal::forUser($user->id)->findOrFail($id);
            
            // Update current value from latest metric
            $metricType = $this->getAssociatedMetricType($goal->goal_type);
            if ($metricType) {
                $latestMetric = HealthMetric::getLatestForUser($user->id, $metricType);
                if ($latestMetric) {
                    $goal->update(['current_value' => $latestMetric->value]);
                }
            }
            
            $goal->updateProgress();

            return $this->success($goal, 'Goal progress updated successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Goal not found', 404);
        } catch (\Exception $e) {
            return $this->error('Failed to update goal progress', 500, $e->getMessage());
        }
    }

    /**
     * Get available goal types
     */
    public function types(): JsonResponse
    {
        try {
            $types = [];
            
            foreach (UserGoal::GOAL_TYPES as $key => $name) {
                $types[] = [
                    'key' => $key,
                    'name' => $name,
                    'associated_metric' => $this->getAssociatedMetricType($key)
                ];
            }

            return $this->success($types, 'Goal types retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve goal types', 500, $e->getMessage());
        }
    }

    /**
     * Get goal progress summary
     */
    public function progress(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $activeGoals = UserGoal::forUser($user->id)
                ->active()
                ->byPriority()
                ->get();

            $summary = [
                'total_active_goals' => $activeGoals->count(),
                'goals_on_track' => $activeGoals->where('progress_percentage', '>=', function($goal) {
                    return $goal->time_progress * 0.8; // At least 80% of expected progress
                })->count(),
                'goals_behind' => 0,
                'goals_ahead' => 0,
                'overdue_goals' => $activeGoals->filter(function($goal) {
                    return $goal->isOverdue();
                })->count(),
                'due_soon' => $activeGoals->filter(function($goal) {
                    return $goal->isDueSoon(7);
                })->count()
            ];

            // Calculate behind/ahead goals
            foreach ($activeGoals as $goal) {
                $expectedProgress = $goal->time_progress;
                $actualProgress = $goal->progress_percentage;
                
                if ($actualProgress < $expectedProgress * 0.8) {
                    $summary['goals_behind']++;
                } elseif ($actualProgress > $expectedProgress * 1.2) {
                    $summary['goals_ahead']++;
                }
            }

            return $this->success([
                'summary' => $summary,
                'active_goals' => $activeGoals
            ], 'Goal progress summary retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve goal progress', 500, $e->getMessage());
        }
    }

    /**
     * Get associated metric type for a goal type
     */
    private function getAssociatedMetricType($goalType): ?string
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
        
        return $mapping[$goalType] ?? null;
    }
}