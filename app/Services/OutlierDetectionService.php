<?php

namespace App\Services;

use App\Models\HealthMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OutlierDetectionService
{
    /**
     * Outlier detection methods
     */
    public const METHODS = [
        'z_score' => 'Z-Score Method',
        'iqr' => 'Interquartile Range (IQR)',
        'mad' => 'Modified Z-Score (MAD)',
        'isolation_forest' => 'Isolation Forest',
        'data_quality' => 'Data Quality Rules'
    ];

    /**
     * Default thresholds for each method
     */
    public const DEFAULT_THRESHOLDS = [
        'z_score' => 2.5,      // Standard deviations
        'iqr' => 1.5,          // IQR multiplier
        'mad' => 3.5,          // MAD multiplier
        'isolation_forest' => 0.1, // Contamination rate
        'data_quality' => null  // Uses predefined rules
    ];

    /**
     * Data quality rules for different metric types
     */
    public const DATA_QUALITY_RULES = [
        'weight' => [
            'min' => 20,    // kg
            'max' => 300,   // kg
            'daily_change_max' => 5,  // kg per day
            'weekly_change_max' => 10 // kg per week
        ],
        'height' => [
            'min' => 50,    // cm
            'max' => 250,   // cm
            'daily_change_max' => 0,  // Height shouldn't change daily
            'weekly_change_max' => 1  // cm per week (measurement error tolerance)
        ],
        'body_fat' => [
            'min' => 3,     // %
            'max' => 60,    // %
            'daily_change_max' => 5,  // % per day
            'weekly_change_max' => 10 // % per week
        ],
        'muscle_mass' => [
            'min' => 10,    // kg
            'max' => 100,   // kg
            'daily_change_max' => 2,  // kg per day
            'weekly_change_max' => 5  // kg per week
        ],
        'blood_pressure_systolic' => [
            'min' => 70,    // mmHg
            'max' => 250,   // mmHg
            'daily_change_max' => 50, // mmHg per day
            'weekly_change_max' => 80 // mmHg per week
        ],
        'blood_pressure_diastolic' => [
            'min' => 40,    // mmHg
            'max' => 150,   // mmHg
            'daily_change_max' => 30, // mmHg per day
            'weekly_change_max' => 50 // mmHg per week
        ],
        'heart_rate' => [
            'min' => 40,    // bpm
            'max' => 200,   // bpm
            'daily_change_max' => 50, // bpm per day
            'weekly_change_max' => 80 // bpm per week
        ],
        'steps' => [
            'min' => 0,     // steps
            'max' => 50000, // steps
            'daily_change_max' => null, // Steps can vary widely
            'weekly_change_max' => null
        ],
        'sleep_hours' => [
            'min' => 1,     // hours
            'max' => 16,    // hours
            'daily_change_max' => 8,  // hours per day
            'weekly_change_max' => 12 // hours per week
        ],
        'water_intake' => [
            'min' => 200,   // ml
            'max' => 8000,  // ml
            'daily_change_max' => null, // Water intake can vary
            'weekly_change_max' => null
        ]
    ];

    /**
     * Detect outliers in a dataset using multiple methods
     */
    public function detectOutliers(Collection $data, array $methods = ['z_score', 'iqr', 'mad'], array $thresholds = []): array
    {
        if ($data->isEmpty() || $data->count() < 3) {
            return [
                'outliers' => [],
                'method_results' => [],
                'statistics' => ['count' => $data->count(), 'message' => 'Insufficient data for outlier detection']
            ];
        }

        $values = $data->pluck('value')->toArray();
        $outliers = [];
        $methodResults = [];

        foreach ($methods as $method) {
            $threshold = $thresholds[$method] ?? self::DEFAULT_THRESHOLDS[$method];
            $result = $this->applyMethod($values, $data, $method, $threshold);
            
            $methodResults[$method] = $result;
            
            // Merge outliers from this method
            foreach ($result['outliers'] as $outlier) {
                $key = $outlier['id'] ?? $outlier['index'];
                if (!isset($outliers[$key])) {
                    $outliers[$key] = $outlier;
                    $outliers[$key]['detected_by'] = [$method];
                    $outliers[$key]['confidence'] = $result['confidence'][$outlier['index']] ?? 0.5;
                } else {
                    $outliers[$key]['detected_by'][] = $method;
                    // Increase confidence if detected by multiple methods
                    $outliers[$key]['confidence'] = min(1.0, $outliers[$key]['confidence'] + 0.3);
                }
            }
        }

        return [
            'outliers' => array_values($outliers),
            'method_results' => $methodResults,
            'statistics' => $this->calculateStatistics($values),
            'total_outliers' => count($outliers),
            'data_points' => count($values)
        ];
    }

    /**
     * Apply specific outlier detection method
     */
    private function applyMethod(array $values, Collection $data, string $method, ?float $threshold): array
    {
        switch ($method) {
            case 'z_score':
                return $this->zScoreMethod($values, $data, $threshold);
            case 'iqr':
                return $this->iqrMethod($values, $data, $threshold);
            case 'mad':
                return $this->madMethod($values, $data, $threshold);
            case 'isolation_forest':
                return $this->isolationForestMethod($values, $data, $threshold);
            case 'data_quality':
                return $this->dataQualityMethod($values, $data);
            default:
                return ['outliers' => [], 'confidence' => [], 'statistics' => []];
        }
    }

    /**
     * Z-Score outlier detection
     * Identifies values that are more than threshold standard deviations from the mean
     */
    private function zScoreMethod(array $values, Collection $data, float $threshold): array
    {
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / count($values);
        $stdDev = sqrt($variance);
        
        if ($stdDev == 0) {
            return ['outliers' => [], 'confidence' => [], 'statistics' => ['mean' => $mean, 'std_dev' => 0]];
        }

        $outliers = [];
        $confidence = [];

        foreach ($values as $index => $value) {
            $zScore = abs(($value - $mean) / $stdDev);
            $confidence[$index] = min(1.0, $zScore / ($threshold * 2)); // Normalize confidence
            
            if ($zScore > $threshold) {
                $dataPoint = $data->get($index);
                $outliers[] = [
                    'index' => $index,
                    'id' => $dataPoint->id ?? null,
                    'value' => $value,
                    'z_score' => $zScore,
                    'threshold' => $threshold,
                    'date' => $dataPoint->recorded_date ?? null,
                    'type' => 'statistical',
                    'severity' => $this->getSeverity($zScore, $threshold)
                ];
            }
        }

        return [
            'outliers' => $outliers,
            'confidence' => $confidence,
            'statistics' => [
                'mean' => $mean,
                'std_dev' => $stdDev,
                'threshold' => $threshold
            ]
        ];
    }

    /**
     * IQR (Interquartile Range) outlier detection
     * More robust to extreme values than Z-score
     */
    private function iqrMethod(array $values, Collection $data, float $threshold): array
    {
        $sortedValues = $values;
        sort($sortedValues);
        $count = count($sortedValues);
        
        $q1Index = floor($count * 0.25);
        $q3Index = floor($count * 0.75);
        
        $q1 = $sortedValues[$q1Index];
        $q3 = $sortedValues[$q3Index];
        $iqr = $q3 - $q1;
        
        if ($iqr == 0) {
            return ['outliers' => [], 'confidence' => [], 'statistics' => ['q1' => $q1, 'q3' => $q3, 'iqr' => 0]];
        }

        $lowerFence = $q1 - ($threshold * $iqr);
        $upperFence = $q3 + ($threshold * $iqr);
        
        $outliers = [];
        $confidence = [];

        foreach ($values as $index => $value) {
            $distance = 0;
            if ($value < $lowerFence) {
                $distance = ($lowerFence - $value) / $iqr;
            } elseif ($value > $upperFence) {
                $distance = ($value - $upperFence) / $iqr;
            }
            
            $confidence[$index] = min(1.0, $distance / ($threshold * 2));
            
            if ($value < $lowerFence || $value > $upperFence) {
                $dataPoint = $data->get($index);
                $outliers[] = [
                    'index' => $index,
                    'id' => $dataPoint->id ?? null,
                    'value' => $value,
                    'distance_from_fence' => $distance,
                    'lower_fence' => $lowerFence,
                    'upper_fence' => $upperFence,
                    'date' => $dataPoint->recorded_date ?? null,
                    'type' => 'statistical',
                    'severity' => $this->getSeverity($distance, $threshold)
                ];
            }
        }

        return [
            'outliers' => $outliers,
            'confidence' => $confidence,
            'statistics' => [
                'q1' => $q1,
                'q3' => $q3,
                'iqr' => $iqr,
                'lower_fence' => $lowerFence,
                'upper_fence' => $upperFence,
                'threshold' => $threshold
            ]
        ];
    }

    /**
     * Modified Z-Score using MAD (Median Absolute Deviation)
     * More robust than standard Z-score for skewed distributions
     */
    private function madMethod(array $values, Collection $data, float $threshold): array
    {
        $median = $this->calculateMedian($values);
        $deviations = array_map(fn($x) => abs($x - $median), $values);
        $mad = $this->calculateMedian($deviations);
        
        if ($mad == 0) {
            return ['outliers' => [], 'confidence' => [], 'statistics' => ['median' => $median, 'mad' => 0]];
        }

        $outliers = [];
        $confidence = [];

        foreach ($values as $index => $value) {
            $modifiedZScore = 0.6745 * (($value - $median) / $mad);
            $absModifiedZScore = abs($modifiedZScore);
            $confidence[$index] = min(1.0, $absModifiedZScore / ($threshold * 2));
            
            if ($absModifiedZScore > $threshold) {
                $dataPoint = $data->get($index);
                $outliers[] = [
                    'index' => $index,
                    'id' => $dataPoint->id ?? null,
                    'value' => $value,
                    'modified_z_score' => $modifiedZScore,
                    'threshold' => $threshold,
                    'date' => $dataPoint->recorded_date ?? null,
                    'type' => 'statistical',
                    'severity' => $this->getSeverity($absModifiedZScore, $threshold)
                ];
            }
        }

        return [
            'outliers' => $outliers,
            'confidence' => $confidence,
            'statistics' => [
                'median' => $median,
                'mad' => $mad,
                'threshold' => $threshold
            ]
        ];
    }

    /**
     * Simple Isolation Forest implementation
     * Based on the principle that outliers are easier to isolate
     */
    private function isolationForestMethod(array $values, Collection $data, float $contamination): array
    {
        // Simplified isolation forest - in production, use a proper ML library
        $scores = [];
        $outliers = [];
        $confidence = [];

        // Calculate isolation scores based on distance from neighbors
        foreach ($values as $index => $value) {
            $distances = [];
            foreach ($values as $otherValue) {
                if ($value !== $otherValue) {
                    $distances[] = abs($value - $otherValue);
                }
            }
            
            if (!empty($distances)) {
                sort($distances);
                $avgNearestDistance = array_sum(array_slice($distances, 0, min(3, count($distances)))) / min(3, count($distances));
                $scores[$index] = $avgNearestDistance;
            } else {
                $scores[$index] = 0;
            }
        }

        // Identify outliers based on contamination rate
        arsort($scores);
        $outlierCount = max(1, floor(count($scores) * $contamination));
        $outlierIndices = array_slice(array_keys($scores), 0, $outlierCount, true);

        $maxScore = max($scores) ?: 1;
        foreach ($scores as $index => $score) {
            $confidence[$index] = $score / $maxScore;
            
            if (in_array($index, $outlierIndices)) {
                $dataPoint = $data->get($index);
                $outliers[] = [
                    'index' => $index,
                    'id' => $dataPoint->id ?? null,
                    'value' => $values[$index],
                    'isolation_score' => $score,
                    'date' => $dataPoint->recorded_date ?? null,
                    'type' => 'isolation',
                    'severity' => $score > ($maxScore * 0.7) ? 'high' : ($score > ($maxScore * 0.4) ? 'medium' : 'low')
                ];
            }
        }

        return [
            'outliers' => $outliers,
            'confidence' => $confidence,
            'statistics' => [
                'contamination' => $contamination,
                'max_score' => $maxScore,
                'outlier_count' => $outlierCount
            ]
        ];
    }

    /**
     * Data quality rules validation
     * Domain-specific validation for health metrics
     */
    private function dataQualityMethod(array $values, Collection $data): array
    {
        if ($data->isEmpty()) {
            return ['outliers' => [], 'confidence' => [], 'statistics' => []];
        }

        $metricType = $data->first()->metric_type ?? null;
        if (!$metricType || !isset(self::DATA_QUALITY_RULES[$metricType])) {
            return ['outliers' => [], 'confidence' => [], 'statistics' => ['message' => 'No quality rules defined for metric type']];
        }

        $rules = self::DATA_QUALITY_RULES[$metricType];
        $outliers = [];
        $confidence = [];

        foreach ($data as $index => $dataPoint) {
            $value = $dataPoint->value;
            $violations = [];
            $confidenceScore = 0;

            // Check absolute value bounds
            if (isset($rules['min']) && $value < $rules['min']) {
                $violations[] = "Value {$value} is below minimum {$rules['min']}";
                $confidenceScore += 0.8;
            }
            
            if (isset($rules['max']) && $value > $rules['max']) {
                $violations[] = "Value {$value} is above maximum {$rules['max']}";
                $confidenceScore += 0.8;
            }

            // Check rate of change if we have previous data
            if ($index > 0) {
                $previousPoint = $data->get($index - 1);
                if ($previousPoint) {
                    $daysDiff = Carbon::parse($dataPoint->recorded_date)->diffInDays(Carbon::parse($previousPoint->recorded_date));
                    $change = abs($value - $previousPoint->value);
                    
                    if ($daysDiff == 1 && isset($rules['daily_change_max']) && $rules['daily_change_max'] !== null && $change > $rules['daily_change_max']) {
                        $violations[] = "Daily change of {$change} exceeds maximum {$rules['daily_change_max']}";
                        $confidenceScore += 0.6;
                    }
                    
                    if ($daysDiff <= 7 && isset($rules['weekly_change_max']) && $rules['weekly_change_max'] !== null) {
                        $weeklyChange = $change * (7 / max($daysDiff, 1));
                        if ($weeklyChange > $rules['weekly_change_max']) {
                            $violations[] = "Projected weekly change of {$weeklyChange} exceeds maximum {$rules['weekly_change_max']}";
                            $confidenceScore += 0.4;
                        }
                    }
                }
            }

            $confidence[$index] = min(1.0, $confidenceScore);

            if (!empty($violations)) {
                $outliers[] = [
                    'index' => $index,
                    'id' => $dataPoint->id ?? null,
                    'value' => $value,
                    'violations' => $violations,
                    'date' => $dataPoint->recorded_date ?? null,
                    'type' => 'data_quality',
                    'severity' => $confidenceScore > 0.7 ? 'high' : ($confidenceScore > 0.4 ? 'medium' : 'low')
                ];
            }
        }

        return [
            'outliers' => $outliers,
            'confidence' => $confidence,
            'statistics' => [
                'rules_applied' => $rules,
                'metric_type' => $metricType
            ]
        ];
    }

    /**
     * Analyze health metrics for a specific user and metric type
     */
    public function analyzeUserMetrics(int $userId, string $metricType, int $days = 90): array
    {
        $metrics = HealthMetric::forUser($userId)
            ->ofType($metricType)
            ->actualMeasurements()
            ->recent($days)
            ->orderBy('recorded_date', 'asc')
            ->get();

        if ($metrics->isEmpty()) {
            return [
                'outliers' => [],
                'analysis' => ['message' => 'No data available for analysis'],
                'recommendations' => []
            ];
        }

        // Apply all detection methods
        $results = $this->detectOutliers($metrics, ['z_score', 'iqr', 'mad', 'data_quality']);
        
        // Generate recommendations
        $recommendations = $this->generateRecommendations($results, $metricType, $metrics);

        return [
            'user_id' => $userId,
            'metric_type' => $metricType,
            'analysis_period_days' => $days,
            'data_points_analyzed' => $metrics->count(),
            'outliers' => $results['outliers'],
            'method_results' => $results['method_results'],
            'statistics' => $results['statistics'],
            'recommendations' => $recommendations,
            'analysis_date' => Carbon::now()->toDateTimeString()
        ];
    }

    /**
     * Generate recommendations based on outlier analysis
     */
    private function generateRecommendations(array $results, string $metricType, Collection $metrics): array
    {
        $recommendations = [];
        $outlierCount = count($results['outliers']);
        $totalPoints = $metrics->count();
        $outlierRate = $totalPoints > 0 ? ($outlierCount / $totalPoints) * 100 : 0;

        if ($outlierCount == 0) {
            $recommendations[] = [
                'type' => 'positive',
                'message' => 'Your measurements appear consistent and within normal ranges.',
                'priority' => 'low'
            ];
            return $recommendations;
        }

        // High outlier rate recommendations
        if ($outlierRate > 20) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => "High variability detected in your {$metricType} measurements ({$outlierRate}% outliers). Consider reviewing your measurement technique or timing.",
                'priority' => 'high',
                'action' => 'review_measurement_process'
            ];
        }

        // Data quality issues
        $dataQualityOutliers = collect($results['outliers'])->where('type', 'data_quality');
        if ($dataQualityOutliers->isNotEmpty()) {
            $recommendations[] = [
                'type' => 'error',
                'message' => "Some measurements appear to be outside normal physiological ranges. Please verify the accuracy of these readings.",
                'priority' => 'high',
                'action' => 'verify_measurements',
                'affected_dates' => $dataQualityOutliers->pluck('date')->toArray()
            ];
        }

        // Sudden changes
        $recentOutliers = collect($results['outliers'])
            ->filter(fn($outlier) => Carbon::parse($outlier['date'])->isAfter(Carbon::now()->subDays(7)))
            ->where('severity', 'high');

        if ($recentOutliers->isNotEmpty()) {
            $recommendations[] = [
                'type' => 'alert',
                'message' => "Significant recent changes detected in your {$metricType}. Consider consulting with a healthcare provider if this trend continues.",
                'priority' => 'medium',
                'action' => 'monitor_closely'
            ];
        }

        // Pattern recognition
        if ($outlierCount > 3) {
            $recommendations[] = [
                'type' => 'info',
                'message' => "Consider tracking additional factors that might influence your {$metricType} measurements (stress, sleep, medication, etc.).",
                'priority' => 'low',
                'action' => 'track_influencing_factors'
            ];
        }

        return $recommendations;
    }

    /**
     * Helper methods
     */
    private function calculateMedian(array $values): float
    {
        $sorted = $values;
        sort($sorted);
        $count = count($sorted);
        
        if ($count % 2 == 0) {
            return ($sorted[$count / 2 - 1] + $sorted[$count / 2]) / 2;
        } else {
            return $sorted[floor($count / 2)];
        }
    }

    private function calculateStatistics(array $values): array
    {
        if (empty($values)) {
            return [];
        }

        $mean = array_sum($values) / count($values);
        $median = $this->calculateMedian($values);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / count($values);
        $stdDev = sqrt($variance);

        return [
            'count' => count($values),
            'mean' => round($mean, 2),
            'median' => round($median, 2),
            'std_dev' => round($stdDev, 2),
            'min' => min($values),
            'max' => max($values),
            'range' => max($values) - min($values)
        ];
    }

    private function getSeverity(float $score, float $threshold): string
    {
        if ($score > $threshold * 2) {
            return 'high';
        } elseif ($score > $threshold * 1.5) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Batch analyze all users' metrics (for scheduled analysis)
     */
    public function batchAnalyzeUsers(array $userIds = null, int $days = 30): array
    {
        $users = $userIds ? User::whereIn('id', $userIds)->get() : User::all();
        $results = [];

        foreach ($users as $user) {
            $userResults = [];
            
            foreach (array_keys(HealthMetric::METRIC_TYPES) as $metricType) {
                $analysis = $this->analyzeUserMetrics($user->id, $metricType, $days);
                
                if (!empty($analysis['outliers'])) {
                    $userResults[$metricType] = $analysis;
                }
            }
            
            if (!empty($userResults)) {
                $results[$user->id] = $userResults;
            }
        }

        return $results;
    }
}