<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\HealthMetric;
use App\Models\UserGoal;
use App\Services\OutlierDetectionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class HealthMetricsController extends BaseApiController
{
    protected OutlierDetectionService $outlierDetectionService;

    public function __construct(OutlierDetectionService $outlierDetectionService)
    {
        $this->outlierDetectionService = $outlierDetectionService;
    }
    /**
     * Get all health metrics for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $metricType = $request->query('type');
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');
            $isGoal = $request->query('is_goal', false);
            $limit = $request->query('limit', 100);

            $query = HealthMetric::forUser($user->id);

            if ($metricType) {
                $query->ofType($metricType);
            }

            if ($startDate && $endDate) {
                $query->inDateRange($startDate, $endDate);
            }

            if ($isGoal === 'true') {
                $query->goals();
            } else {
                $query->actualMeasurements();
            }

            $metrics = $query->orderBy('recorded_date', 'desc')
                           ->orderBy('recorded_time', 'desc')
                           ->limit($limit)
                           ->get();

            return $this->success([
                'metrics' => $metrics,
                'total' => $metrics->count()
            ], 'Health metrics retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve health metrics', 500, $e->getMessage());
        }
    }

    /**
     * Store a new health metric
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $validatedData = $request->validate([
                'metric_type' => ['required', Rule::in(array_keys(HealthMetric::METRIC_TYPES))],
                'value' => 'required|numeric|min:0',
                'unit' => 'required|string|max:20',
                'recorded_date' => 'required|date',
                'recorded_time' => 'nullable|date_format:H:i:s',
                'notes' => 'nullable|string|max:1000',
                'metadata' => 'nullable|array',
                'is_goal' => 'boolean'
            ]);

            // Set default unit if not provided
            if (!isset($validatedData['unit']) || empty($validatedData['unit'])) {
                $validatedData['unit'] = HealthMetric::DEFAULT_UNITS[$validatedData['metric_type']] ?? 'unit';
            }

            // Check for duplicate metric on the same date
            $existingMetric = HealthMetric::forUser($user->id)
                ->ofType($validatedData['metric_type'])
                ->where('recorded_date', $validatedData['recorded_date'])
                ->where('is_goal', $validatedData['is_goal'] ?? false)
                ->first();

            if ($existingMetric) {
                // Update existing metric instead of creating duplicate
                $existingMetric->update($validatedData);
                $metric = $existingMetric;
            } else {
                // Create new metric
                $validatedData['user_id'] = $user->id;
                $metric = HealthMetric::create($validatedData);
            }

            // Update related goals progress if this is an actual measurement
            if (!($validatedData['is_goal'] ?? false)) {
                UserGoal::updateProgressForUser($user->id);
            }

            // Update profile completion
            $user->updateProfileCompletion();

            // Check for outliers in recent data
            $outlierAnalysis = $this->outlierDetectionService->analyzeUserMetrics(
                $user->id, 
                $validatedData['metric_type'], 
                30
            );

            $response = [
                'metric' => $metric,
                'outlier_detected' => !empty($outlierAnalysis['outliers']),
                'outlier_analysis' => $outlierAnalysis
            ];

            return $this->success($response, 'Health metric saved successfully', 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to save health metric', 500, $e->getMessage());
        }
    }

    /**
     * Get a specific health metric
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            
            $metric = HealthMetric::forUser($user->id)->findOrFail($id);

            return $this->success($metric, 'Health metric retrieved successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Health metric not found', 404);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve health metric', 500, $e->getMessage());
        }
    }

    /**
     * Update a health metric
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            
            $metric = HealthMetric::forUser($user->id)->findOrFail($id);

            $validatedData = $request->validate([
                'value' => 'required|numeric|min:0',
                'unit' => 'required|string|max:20',
                'recorded_date' => 'required|date',
                'recorded_time' => 'nullable|date_format:H:i:s',
                'notes' => 'nullable|string|max:1000',
                'metadata' => 'nullable|array'
            ]);

            $metric->update($validatedData);

            // Update related goals progress if this is an actual measurement
            if (!$metric->is_goal) {
                UserGoal::updateProgressForUser($user->id);
            }

            return $this->success($metric, 'Health metric updated successfully');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Health metric not found', 404);
        } catch (\Exception $e) {
            return $this->error('Failed to update health metric', 500, $e->getMessage());
        }
    }

    /**
     * Delete a health metric
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            
            $metric = HealthMetric::forUser($user->id)->findOrFail($id);
            $metric->delete();

            // Update related goals progress
            UserGoal::updateProgressForUser($user->id);

            return $this->success(null, 'Health metric deleted successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Health metric not found', 404);
        } catch (\Exception $e) {
            return $this->error('Failed to delete health metric', 500, $e->getMessage());
        }
    }

    /**
     * Get metric history and trends
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $validatedData = $request->validate([
                'metric_type' => ['required', Rule::in(array_keys(HealthMetric::METRIC_TYPES))],
                'days' => 'integer|min:1|max:365'
            ]);

            $metricType = $validatedData['metric_type'];
            $days = $validatedData['days'] ?? 30;

            // Get historical data
            $history = HealthMetric::getHistoryForUser($user->id, $metricType, $days);
            
            // Calculate trend
            $trend = HealthMetric::calculateTrend($user->id, $metricType, $days);
            
            // Get latest value
            $latest = HealthMetric::getLatestForUser($user->id, $metricType);

            // Get goal if exists
            $goal = HealthMetric::forUser($user->id)
                ->ofType($metricType)
                ->goals()
                ->orderBy('recorded_date', 'desc')
                ->first();

            return $this->success([
                'metric_type' => $metricType,
                'display_name' => HealthMetric::METRIC_TYPES[$metricType],
                'unit' => HealthMetric::DEFAULT_UNITS[$metricType] ?? 'unit',
                'history' => $history,
                'trend' => $trend,
                'latest_value' => $latest,
                'goal' => $goal,
                'period_days' => $days
            ], 'Metric history retrieved successfully');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve metric history', 500, $e->getMessage());
        }
    }

    /**
     * Get available metric types
     */
    public function types(): JsonResponse
    {
        try {
            $types = [];
            
            foreach (HealthMetric::METRIC_TYPES as $key => $name) {
                $types[] = [
                    'key' => $key,
                    'name' => $name,
                    'default_unit' => HealthMetric::DEFAULT_UNITS[$key] ?? 'unit'
                ];
            }

            return $this->success($types, 'Metric types retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve metric types', 500, $e->getMessage());
        }
    }

    /**
     * Get dashboard summary of key metrics
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $keyMetrics = ['weight', 'body_fat', 'muscle_mass', 'steps', 'sleep_hours', 'water_intake'];
            $summary = [];

            foreach ($keyMetrics as $metricType) {
                $latest = HealthMetric::getLatestForUser($user->id, $metricType);
                $trend = HealthMetric::calculateTrend($user->id, $metricType, 7); // 7-day trend
                
                if ($latest || $trend['trend'] !== 'insufficient_data') {
                    $summary[] = [
                        'type' => $metricType,
                        'display_name' => HealthMetric::METRIC_TYPES[$metricType] ?? $metricType,
                        'latest_value' => $latest ? $latest->value : null,
                        'unit' => $latest ? $latest->unit : (HealthMetric::DEFAULT_UNITS[$metricType] ?? 'unit'),
                        'trend' => $trend,
                        'last_recorded' => $latest ? $latest->recorded_date : null
                    ];
                }
            }

            // Get active goals progress
            $activeGoals = $user->activeGoals()->with(['user'])->get()->map(function ($goal) {
                return [
                    'id' => $goal->id,
                    'type' => $goal->goal_type,
                    'display_name' => $goal->goal_display_name,
                    'progress_percentage' => $goal->progress_percentage,
                    'target_value' => $goal->target_value,
                    'current_value' => $goal->current_value,
                    'target_date' => $goal->target_date,
                    'days_remaining' => $goal->days_remaining,
                    'is_overdue' => $goal->isOverdue()
                ];
            });

            return $this->success([
                'metrics_summary' => $summary,
                'active_goals' => $activeGoals,
                'insights' => $user->getHealthInsights()
            ], 'Dashboard data retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve dashboard data', 500, $e->getMessage());
        }
    }

    /**
     * Analyze outliers for a specific metric type
     */
    public function analyzeOutliers(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $validatedData = $request->validate([
                'metric_type' => ['required', Rule::in(array_keys(HealthMetric::METRIC_TYPES))],
                'days' => 'integer|min:7|max:365',
                'methods' => 'array',
                'methods.*' => Rule::in(['z_score', 'iqr', 'mad', 'isolation_forest', 'data_quality'])
            ]);

            $metricType = $validatedData['metric_type'];
            $days = $validatedData['days'] ?? 90;
            $methods = $validatedData['methods'] ?? ['z_score', 'iqr', 'mad', 'data_quality'];

            $analysis = $this->outlierDetectionService->analyzeUserMetrics($user->id, $metricType, $days);

            return $this->success($analysis, 'Outlier analysis completed successfully');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to analyze outliers', 500, $e->getMessage());
        }
    }

    /**
     * Get outlier detection methods and thresholds
     */
    public function outlierMethods(): JsonResponse
    {
        try {
            $methods = [];
            
            foreach (OutlierDetectionService::METHODS as $key => $name) {
                $methods[] = [
                    'key' => $key,
                    'name' => $name,
                    'default_threshold' => OutlierDetectionService::DEFAULT_THRESHOLDS[$key],
                    'description' => $this->getMethodDescription($key)
                ];
            }

            return $this->success([
                'methods' => $methods,
                'data_quality_rules' => OutlierDetectionService::DATA_QUALITY_RULES
            ], 'Outlier detection methods retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve outlier methods', 500, $e->getMessage());
        }
    }

    /**
     * Validate a single metric value against outlier detection
     */
    public function validateMetric(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $validatedData = $request->validate([
                'metric_type' => ['required', Rule::in(array_keys(HealthMetric::METRIC_TYPES))],
                'value' => 'required|numeric|min:0',
                'unit' => 'required|string|max:20',
                'recorded_date' => 'required|date'
            ]);

            // Get recent metrics for comparison
            $recentMetrics = HealthMetric::forUser($user->id)
                ->ofType($validatedData['metric_type'])
                ->actualMeasurements()
                ->recent(90)
                ->orderBy('recorded_date', 'asc')
                ->get();

            // Create a temporary metric for validation
            $tempMetric = new HealthMetric($validatedData);
            $tempMetric->user_id = $user->id;
            $tempMetric->id = 'temp_' . time();
            
            // Add the new value to the collection
            $metricsForAnalysis = $recentMetrics->push($tempMetric);
            
            // Analyze with the new value included
            $results = $this->outlierDetectionService->detectOutliers(
                $metricsForAnalysis, 
                ['z_score', 'iqr', 'data_quality']
            );
            
            // Check if the new value is flagged as an outlier
            $isOutlier = collect($results['outliers'])->contains('id', 'temp_' . time());
            $newValueOutlier = collect($results['outliers'])->firstWhere('id', 'temp_' . time());
            
            return $this->success([
                'is_outlier' => $isOutlier,
                'outlier_details' => $newValueOutlier,
                'validation_result' => $isOutlier ? 'warning' : 'normal',
                'message' => $isOutlier ? 'This value appears unusual compared to your recent measurements' : 'Value appears normal',
                'suggestions' => $isOutlier ? [
                    'Double-check the measurement',
                    'Ensure consistent measurement conditions',
                    'Consider factors that might affect this metric'
                ] : []
            ], 'Metric validation completed');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to validate metric', 500, $e->getMessage());
        }
    }

    private function getMethodDescription(string $method): string
    {
        $descriptions = [
            'z_score' => 'Identifies values more than a certain number of standard deviations from the mean',
            'iqr' => 'Uses quartiles to identify outliers, more robust to extreme values',
            'mad' => 'Modified Z-Score using median absolute deviation, robust for skewed data',
            'isolation_forest' => 'Machine learning approach that isolates anomalies',
            'data_quality' => 'Domain-specific rules for physiologically reasonable values'
        ];
        
        return $descriptions[$method] ?? 'No description available';
    }
}