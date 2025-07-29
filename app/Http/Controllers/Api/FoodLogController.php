<?php

namespace App\Http\Controllers\Api;

use App\Models\FoodLog;
use App\Models\Food;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class FoodLogController extends BaseApiController
{
    /**
     * Store a new food log entry
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            $validatedData = $this->validateRequest($request, [
                'food_id' => 'required|exists:foods,id',
                'quantity' => 'required|numeric|min:0.1|max:10000',
                'unit' => 'required|string|max:20',
                'meal_type' => 'required|in:breakfast,lunch,dinner,snack',
                'consumed_at' => 'sometimes|date',
                'notes' => 'sometimes|nullable|string|max:500',
            ]);

            $food = Food::findOrFail($validatedData['food_id']);
            $consumedAt = isset($validatedData['consumed_at']) 
                ? Carbon::parse($validatedData['consumed_at']) 
                : now();

            // Convert quantity to grams for consistent storage
            $quantityInGrams = $this->convertToGrams($validatedData['quantity'], $validatedData['unit'], $food);

            // Calculate nutritional values
            $nutrition = $food->getNutritionForServing($quantityInGrams);

            $foodLog = FoodLog::create([
                'user_id' => $user->id,
                'food_id' => $validatedData['food_id'],
                'food_name' => $food->name,
                'quantity' => $validatedData['quantity'],
                'unit' => $validatedData['unit'],
                'quantity_grams' => $quantityInGrams,
                'meal_type' => $validatedData['meal_type'],
                'calories' => $nutrition['calories'],
                'protein' => $nutrition['protein_g'],
                'carbs' => $nutrition['carbs_g'],
                'fat' => $nutrition['fat_g'],
                'fiber' => $nutrition['fiber_g'],
                'sugar' => $nutrition['sugar_g'],
                'sodium' => $nutrition['sodium_mg'],
                'consumed_at' => $consumedAt,
                'notes' => $validatedData['notes'] ?? null,
            ]);

            // Load the food relationship for the response
            $foodLog->load('food');

            return $this->successResponse(
                $foodLog,
                'Food logged successfully',
                201
            );

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to log food');
        }
    }

    /**
     * Get daily food logs for authenticated user
     */
    public function daily(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            $date = $request->get('date', now()->toDateString());

            $logs = FoodLog::where('user_id', $user->id)
                          ->whereDate('consumed_at', $date)
                          ->with('food')
                          ->orderBy('consumed_at')
                          ->get();

            // Group by meal type and calculate totals
            $groupedLogs = $logs->groupBy('meal_type');
            $dailyTotals = $this->calculateDailyTotals($logs);

            $response = [
                'date' => $date,
                'meals' => [
                    'breakfast' => $groupedLogs->get('breakfast', collect())->values(),
                    'lunch' => $groupedLogs->get('lunch', collect())->values(),
                    'dinner' => $groupedLogs->get('dinner', collect())->values(),
                    'snack' => $groupedLogs->get('snack', collect())->values(),
                ],
                'daily_totals' => $dailyTotals,
                'target_calories' => $user->daily_calorie_target,
                'remaining_calories' => $user->daily_calorie_target ? 
                    ($user->daily_calorie_target - $dailyTotals['calories']) : null,
            ];

            return $this->successResponse($response, 'Daily food logs retrieved successfully');

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to retrieve daily food logs');
        }
    }

    /**
     * Get food logs for a specific date
     */
    public function dailyByDate(Request $request, string $date): JsonResponse
    {
        try {
            // Validate date format
            if (!Carbon::createFromFormat('Y-m-d', $date)) {
                return $this->errorResponse('Invalid date format. Use YYYY-MM-DD');
            }

            $request->merge(['date' => $date]);
            return $this->daily($request);

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to retrieve food logs for date');
        }
    }

    /**
     * Update a food log entry
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            $foodLog = FoodLog::find($id);

            if (!$foodLog) {
                return $this->notFoundResponse('Food log entry not found');
            }

            if (!$this->userOwnsResource($foodLog)) {
                return $this->forbiddenResponse('You can only update your own food logs');
            }

            $validatedData = $this->validateRequest($request, [
                'quantity' => 'sometimes|required|numeric|min:0.1|max:10000',
                'unit' => 'sometimes|required|string|max:20',
                'meal_type' => 'sometimes|required|in:breakfast,lunch,dinner,snack',
                'consumed_at' => 'sometimes|date',
                'notes' => 'sometimes|nullable|string|max:500',
            ]);

            // If quantity or unit changed, recalculate nutrition
            if (isset($validatedData['quantity']) || isset($validatedData['unit'])) {
                $food = $foodLog->food;
                $quantity = $validatedData['quantity'] ?? $foodLog->quantity;
                $unit = $validatedData['unit'] ?? $foodLog->unit;
                
                $quantityInGrams = $this->convertToGrams($quantity, $unit, $food);
                $nutrition = $food->getNutritionForServing($quantityInGrams);

                $validatedData['quantity_grams'] = $quantityInGrams;
                $validatedData['calories'] = $nutrition['calories'];
                $validatedData['protein'] = $nutrition['protein_g'];
                $validatedData['carbs'] = $nutrition['carbs_g'];
                $validatedData['fat'] = $nutrition['fat_g'];
                $validatedData['fiber'] = $nutrition['fiber_g'];
                $validatedData['sugar'] = $nutrition['sugar_g'];
                $validatedData['sodium'] = $nutrition['sodium_mg'];
            }

            $foodLog->update($validatedData);

            return $this->successResponse(
                $foodLog->fresh(['food']),
                'Food log updated successfully'
            );

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to update food log');
        }
    }

    /**
     * Delete a food log entry
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            $foodLog = FoodLog::find($id);

            if (!$foodLog) {
                return $this->notFoundResponse('Food log entry not found');
            }

            if (!$this->userOwnsResource($foodLog)) {
                return $this->forbiddenResponse('You can only delete your own food logs');
            }

            $foodLog->delete();

            return $this->successResponse(null, 'Food log deleted successfully');

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to delete food log');
        }
    }

    /**
     * Get nutrition summary for a date range
     */
    public function nutritionSummary(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            $validatedData = $this->validateRequest($request, [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);

            $logs = FoodLog::where('user_id', $user->id)
                          ->whereBetween('consumed_at', [$validatedData['start_date'], $validatedData['end_date']])
                          ->get();

            $summary = [
                'period' => [
                    'start_date' => $validatedData['start_date'],
                    'end_date' => $validatedData['end_date'],
                    'days' => Carbon::parse($validatedData['start_date'])->diffInDays($validatedData['end_date']) + 1,
                ],
                'totals' => $this->calculateDailyTotals($logs),
                'averages' => $this->calculateAverages($logs, $validatedData['start_date'], $validatedData['end_date']),
                'by_meal_type' => $this->calculateMealTypeTotals($logs),
            ];

            return $this->successResponse($summary, 'Nutrition summary retrieved successfully');

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to retrieve nutrition summary');
        }
    }

    /**
     * Convert quantity to grams based on unit and food
     */
    private function convertToGrams(float $quantity, string $unit, Food $food): float
    {
        // Standard conversions
        $conversions = [
            'g' => 1,
            'kg' => 1000,
            'ml' => 1, // Assuming 1ml = 1g for simplicity
            'cup' => 240,
            'tbsp' => 15,
            'tsp' => 5,
            'oz' => 28.35,
            'lb' => 453.59,
        ];

        // If it's a serving size, convert based on food's common serving
        if ($unit === 'serving' && $food->getCommonServingInGrams()) {
            return $quantity * $food->getCommonServingInGrams();
        }

        $conversionFactor = $conversions[strtolower($unit)] ?? 1;
        return $quantity * $conversionFactor;
    }

    /**
     * Calculate daily nutritional totals
     */
    private function calculateDailyTotals($logs): array
    {
        return [
            'calories' => round($logs->sum('calories'), 2),
            'protein' => round($logs->sum('protein'), 2),
            'carbs' => round($logs->sum('carbs'), 2),
            'fat' => round($logs->sum('fat'), 2),
            'fiber' => round($logs->sum('fiber'), 2),
            'sugar' => round($logs->sum('sugar'), 2),
            'sodium' => round($logs->sum('sodium'), 2),
        ];
    }

    /**
     * Calculate averages for a period
     */
    private function calculateAverages($logs, string $startDate, string $endDate): array
    {
        $days = Carbon::parse($startDate)->diffInDays($endDate) + 1;
        $totals = $this->calculateDailyTotals($logs);

        return [
            'calories_per_day' => round($totals['calories'] / $days, 2),
            'protein_per_day' => round($totals['protein'] / $days, 2),
            'carbs_per_day' => round($totals['carbs'] / $days, 2),
            'fat_per_day' => round($totals['fat'] / $days, 2),
            'fiber_per_day' => round($totals['fiber'] / $days, 2),
            'sugar_per_day' => round($totals['sugar'] / $days, 2),
            'sodium_per_day' => round($totals['sodium'] / $days, 2),
        ];
    }

    /**
     * Calculate totals by meal type
     */
    private function calculateMealTypeTotals($logs): array
    {
        $mealTypes = ['breakfast', 'lunch', 'dinner', 'snack'];
        $result = [];

        foreach ($mealTypes as $mealType) {
            $mealLogs = $logs->where('meal_type', $mealType);
            $result[$mealType] = $this->calculateDailyTotals($mealLogs);
        }

        return $result;
    }
}