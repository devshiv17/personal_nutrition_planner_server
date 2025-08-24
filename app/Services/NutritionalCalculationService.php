<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class NutritionalCalculationService
{
    /**
     * Unit conversion factors to grams
     */
    private const WEIGHT_CONVERSIONS = [
        // Base unit
        'g' => 1.0,
        'gram' => 1.0,
        'grams' => 1.0,
        
        // Metric
        'kg' => 1000.0,
        'kilogram' => 1000.0,
        'kilograms' => 1000.0,
        'mg' => 0.001,
        'milligram' => 0.001,
        'milligrams' => 0.001,
        'mcg' => 0.000001,
        'microgram' => 0.000001,
        'micrograms' => 0.000001,
        'μg' => 0.000001,
        
        // Imperial
        'oz' => 28.3495,
        'ounce' => 28.3495,
        'ounces' => 28.3495,
        'lb' => 453.592,
        'pound' => 453.592,
        'pounds' => 453.592,
        'lbs' => 453.592,
        
        // Common cooking units (approximate)
        'tsp' => 5.0,      // teaspoon (varies by ingredient)
        'teaspoon' => 5.0,
        'teaspoons' => 5.0,
        'tbsp' => 15.0,    // tablespoon
        'tablespoon' => 15.0,
        'tablespoons' => 15.0,
        'cup' => 240.0,    // varies significantly by ingredient
        'cups' => 240.0,
    ];

    /**
     * Volume conversion factors to milliliters
     */
    private const VOLUME_CONVERSIONS = [
        // Base unit
        'ml' => 1.0,
        'milliliter' => 1.0,
        'milliliters' => 1.0,
        
        // Metric
        'l' => 1000.0,
        'liter' => 1000.0,
        'liters' => 1000.0,
        'cl' => 10.0,
        'centiliter' => 10.0,
        'centiliters' => 10.0,
        
        // Imperial
        'fl oz' => 29.5735,
        'fluid ounce' => 29.5735,
        'fluid ounces' => 29.5735,
        'fl. oz.' => 29.5735,
        'cup' => 236.588,
        'cups' => 236.588,
        'pint' => 473.176,
        'pints' => 473.176,
        'pt' => 473.176,
        'quart' => 946.353,
        'quarts' => 946.353,
        'qt' => 946.353,
        'gallon' => 3785.41,
        'gallons' => 3785.41,
        'gal' => 3785.41,
        
        // Common cooking units
        'tsp' => 4.92892,
        'teaspoon' => 4.92892,
        'teaspoons' => 4.92892,
        'tbsp' => 14.7868,
        'tablespoon' => 14.7868,
        'tablespoons' => 14.7868,
    ];

    /**
     * Common ingredient density factors (g/ml) for volume to weight conversion
     */
    private const INGREDIENT_DENSITIES = [
        // Liquids
        'water' => 1.0,
        'milk' => 1.03,
        'cream' => 0.98,
        'oil' => 0.92,
        'olive oil' => 0.915,
        'vegetable oil' => 0.92,
        'honey' => 1.42,
        'syrup' => 1.37,
        'juice' => 1.05,
        
        // Powders and grains
        'flour' => 0.57,
        'all-purpose flour' => 0.57,
        'wheat flour' => 0.57,
        'sugar' => 0.85,
        'brown sugar' => 0.96,
        'powdered sugar' => 0.56,
        'salt' => 1.22,
        'baking powder' => 0.95,
        'cocoa powder' => 0.51,
        'rice' => 0.75,
        'oats' => 0.41,
        
        // Semi-solids
        'butter' => 0.91,
        'margarine' => 0.91,
        'peanut butter' => 0.95,
        'yogurt' => 1.04,
        'sour cream' => 0.96,
    ];

    /**
     * Standard serving size references
     */
    private const STANDARD_SERVINGS = [
        'apple' => ['amount' => 182, 'unit' => 'g', 'description' => '1 medium apple'],
        'banana' => ['amount' => 118, 'unit' => 'g', 'description' => '1 medium banana'],
        'orange' => ['amount' => 154, 'unit' => 'g', 'description' => '1 medium orange'],
        'egg' => ['amount' => 50, 'unit' => 'g', 'description' => '1 large egg'],
        'bread' => ['amount' => 28, 'unit' => 'g', 'description' => '1 slice bread'],
        'rice' => ['amount' => 158, 'unit' => 'g', 'description' => '1 cup cooked rice'],
        'pasta' => ['amount' => 140, 'unit' => 'g', 'description' => '1 cup cooked pasta'],
        'chicken breast' => ['amount' => 85, 'unit' => 'g', 'description' => '3 oz portion'],
        'salmon' => ['amount' => 85, 'unit' => 'g', 'description' => '3 oz portion'],
    ];

    /**
     * Calculate nutrition for a given amount of food
     */
    public function calculateNutrition(
        array $foodData, 
        float $amount, 
        string $unit = 'g',
        ?string $foodName = null
    ): array {
        try {
            // Convert amount to grams
            $amountInGrams = $this->convertToGrams($amount, $unit, $foodName);
            
            // Calculate scaling factor (nutrition is typically per 100g)
            $scaleFactor = $amountInGrams / 100.0;
            
            // Scale all nutritional values
            $calculatedNutrition = [];
            
            foreach ($foodData as $nutrient => $value) {
                if (is_numeric($value)) {
                    $calculatedNutrition[$nutrient] = round($value * $scaleFactor, 2);
                } else {
                    $calculatedNutrition[$nutrient] = $value;
                }
            }
            
            // Add serving information
            $calculatedNutrition['serving_amount'] = $amount;
            $calculatedNutrition['serving_unit'] = $unit;
            $calculatedNutrition['serving_grams'] = round($amountInGrams, 2);
            $calculatedNutrition['scale_factor'] = round($scaleFactor, 4);
            
            return $calculatedNutrition;

        } catch (\Exception $e) {
            Log::error('Nutrition calculation failed', [
                'amount' => $amount,
                'unit' => $unit,
                'food_name' => $foodName,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception("Failed to calculate nutrition: " . $e->getMessage());
        }
    }

    /**
     * Convert various units to grams
     */
    public function convertToGrams(float $amount, string $unit, ?string $foodName = null): float
    {
        $unit = trim(strtolower($unit));
        
        // Direct weight conversions
        if (isset(self::WEIGHT_CONVERSIONS[$unit])) {
            return $amount * self::WEIGHT_CONVERSIONS[$unit];
        }
        
        // Volume to weight conversion
        if (isset(self::VOLUME_CONVERSIONS[$unit])) {
            $volumeInMl = $amount * self::VOLUME_CONVERSIONS[$unit];
            $density = $this->getIngredientDensity($foodName);
            return $volumeInMl * $density;
        }
        
        // Standard serving sizes
        if ($unit === 'serving' || $unit === 'servings') {
            $standardServing = $this->getStandardServing($foodName);
            return $amount * $standardServing;
        }
        
        // Handle common piece/count units
        if (in_array($unit, ['piece', 'pieces', 'item', 'items', 'each', 'whole'])) {
            $standardServing = $this->getStandardServing($foodName);
            return $amount * $standardServing;
        }
        
        // If unit is not recognized, assume grams
        Log::warning('Unknown unit, assuming grams', [
            'unit' => $unit,
            'food_name' => $foodName,
            'amount' => $amount
        ]);
        
        return $amount;
    }

    /**
     * Get ingredient density for volume to weight conversion
     */
    private function getIngredientDensity(?string $foodName): float
    {
        if (!$foodName) {
            return 1.0; // Default to water density
        }
        
        $foodName = strtolower(trim($foodName));
        
        // Check for exact matches
        if (isset(self::INGREDIENT_DENSITIES[$foodName])) {
            return self::INGREDIENT_DENSITIES[$foodName];
        }
        
        // Check for partial matches
        foreach (self::INGREDIENT_DENSITIES as $ingredient => $density) {
            if (str_contains($foodName, $ingredient) || str_contains($ingredient, $foodName)) {
                return $density;
            }
        }
        
        // Default density (water)
        return 1.0;
    }

    /**
     * Get standard serving size in grams
     */
    private function getStandardServing(?string $foodName): float
    {
        if (!$foodName) {
            return 100.0; // Default serving
        }
        
        $foodName = strtolower(trim($foodName));
        
        // Check for exact matches
        if (isset(self::STANDARD_SERVINGS[$foodName])) {
            return (float) self::STANDARD_SERVINGS[$foodName]['amount'];
        }
        
        // Check for partial matches
        foreach (self::STANDARD_SERVINGS as $food => $serving) {
            if (str_contains($foodName, $food) || str_contains($food, $foodName)) {
                return (float) $serving['amount'];
            }
        }
        
        // Default serving size
        return 100.0;
    }

    /**
     * Calculate total nutrition from multiple food items
     */
    public function calculateTotalNutrition(array $foodItems): array
    {
        $totals = [];
        $totalCalories = 0;
        
        foreach ($foodItems as $item) {
            $nutrition = $this->calculateNutrition(
                $item['nutrition_data'],
                $item['amount'],
                $item['unit'] ?? 'g',
                $item['food_name'] ?? null
            );
            
            foreach ($nutrition as $nutrient => $value) {
                if (is_numeric($value) && !in_array($nutrient, ['serving_amount', 'serving_unit', 'serving_grams', 'scale_factor'])) {
                    $totals[$nutrient] = ($totals[$nutrient] ?? 0) + $value;
                }
            }
        }
        
        return $totals;
    }

    /**
     * Calculate macro percentages (protein, fat, carbs)
     */
    public function calculateMacroPercentages(array $nutrition): array
    {
        $calories = $nutrition['calories'] ?? $nutrition['calories_per_100g'] ?? 0;
        $protein = $nutrition['protein'] ?? $nutrition['protein_per_100g'] ?? 0;
        $fat = $nutrition['fat'] ?? $nutrition['fat_per_100g'] ?? 0;
        $carbs = $nutrition['carbs'] ?? $nutrition['carbohydrates_per_100g'] ?? 0;
        
        if ($calories == 0) {
            return [
                'protein_percentage' => 0,
                'fat_percentage' => 0,
                'carbs_percentage' => 0
            ];
        }
        
        $proteinCalories = $protein * 4; // 4 calories per gram
        $fatCalories = $fat * 9; // 9 calories per gram  
        $carbCalories = $carbs * 4; // 4 calories per gram
        
        return [
            'protein_percentage' => round(($proteinCalories / $calories) * 100, 1),
            'fat_percentage' => round(($fatCalories / $calories) * 100, 1),
            'carbs_percentage' => round(($carbCalories / $calories) * 100, 1)
        ];
    }

    /**
     * Get all supported units grouped by type
     */
    public function getSupportedUnits(): array
    {
        return [
            'weight' => [
                'metric' => ['g', 'kg', 'mg', 'mcg'],
                'imperial' => ['oz', 'lb'],
                'cooking' => ['tsp', 'tbsp']
            ],
            'volume' => [
                'metric' => ['ml', 'l', 'cl'],
                'imperial' => ['fl oz', 'cup', 'pint', 'quart', 'gallon'],
                'cooking' => ['tsp', 'tbsp', 'cup']
            ],
            'count' => ['piece', 'serving', 'item', 'each', 'whole']
        ];
    }

    /**
     * Convert between units
     */
    public function convertUnits(
        float $amount, 
        string $fromUnit, 
        string $toUnit, 
        ?string $foodName = null
    ): float {
        // Convert to grams first, then to target unit
        $amountInGrams = $this->convertToGrams($amount, $fromUnit, $foodName);
        
        $toUnit = trim(strtolower($toUnit));
        
        // Convert from grams to target unit
        if (isset(self::WEIGHT_CONVERSIONS[$toUnit])) {
            return $amountInGrams / self::WEIGHT_CONVERSIONS[$toUnit];
        }
        
        // Volume conversion (grams to volume)
        if (isset(self::VOLUME_CONVERSIONS[$toUnit])) {
            $density = $this->getIngredientDensity($foodName);
            $volumeInMl = $amountInGrams / $density;
            return $volumeInMl / self::VOLUME_CONVERSIONS[$toUnit];
        }
        
        // Standard serving conversion
        if ($toUnit === 'serving' || $toUnit === 'servings') {
            $standardServing = $this->getStandardServing($foodName);
            return $amountInGrams / $standardServing;
        }
        
        // If target unit is not recognized, return grams
        return $amountInGrams;
    }

    /**
     * Get nutrition density (nutrition per calorie)
     */
    public function getNutritionDensity(array $nutrition): array
    {
        $calories = $nutrition['calories'] ?? $nutrition['calories_per_100g'] ?? 1;
        
        if ($calories == 0) {
            $calories = 1; // Avoid division by zero
        }
        
        $density = [];
        
        foreach ($nutrition as $nutrient => $value) {
            if (is_numeric($value) && $nutrient !== 'calories' && !str_contains($nutrient, 'calories')) {
                $density["{$nutrient}_per_calorie"] = round($value / $calories, 4);
            }
        }
        
        return $density;
    }

    /**
     * Compare nutritional values between foods
     */
    public function compareNutrition(array $food1, array $food2, array $nutrients = null): array
    {
        $nutrients = $nutrients ?? ['calories', 'protein', 'fat', 'carbs', 'fiber', 'sugar', 'sodium'];
        $comparison = [];
        
        foreach ($nutrients as $nutrient) {
            $value1 = $food1[$nutrient] ?? $food1["{$nutrient}_per_100g"] ?? 0;
            $value2 = $food2[$nutrient] ?? $food2["{$nutrient}_per_100g"] ?? 0;
            
            $difference = $value1 - $value2;
            $percentageDiff = $value2 != 0 ? (($difference / $value2) * 100) : 0;
            
            $comparison[$nutrient] = [
                'food1_value' => $value1,
                'food2_value' => $value2,
                'difference' => round($difference, 2),
                'percentage_difference' => round($percentageDiff, 1),
                'better_choice' => $this->determineBetterChoice($nutrient, $value1, $value2)
            ];
        }
        
        return $comparison;
    }

    /**
     * Determine which food is better for a specific nutrient
     */
    private function determineBetterChoice(string $nutrient, float $value1, float $value2): ?string
    {
        // Nutrients where higher is generally better
        $higherIsBetter = ['protein', 'fiber', 'vitamin_c', 'vitamin_a', 'calcium', 'iron'];
        
        // Nutrients where lower is generally better  
        $lowerIsBetter = ['calories', 'fat', 'sugar', 'sodium'];
        
        if (in_array($nutrient, $higherIsBetter)) {
            return $value1 > $value2 ? 'food1' : ($value1 < $value2 ? 'food2' : null);
        }
        
        if (in_array($nutrient, $lowerIsBetter)) {
            return $value1 < $value2 ? 'food1' : ($value1 > $value2 ? 'food2' : null);
        }
        
        // For other nutrients, no clear preference
        return null;
    }

    /**
     * Calculate daily value percentages
     */
    public function calculateDailyValues(array $nutrition, array $dailyValues = null): array
    {
        // Default daily values for adults (can be customized)
        $defaultDailyValues = [
            'calories' => 2000,
            'protein' => 50,
            'fat' => 65,
            'carbs' => 300,
            'fiber' => 25,
            'sugar' => 50,
            'sodium' => 2.3, // grams
            'calcium' => 1000, // mg
            'iron' => 18, // mg
            'vitamin_c' => 90, // mg
            'vitamin_a' => 900 // mcg
        ];
        
        $dailyValues = $dailyValues ?? $defaultDailyValues;
        $percentages = [];
        
        foreach ($nutrition as $nutrient => $value) {
            if (isset($dailyValues[$nutrient]) && is_numeric($value)) {
                $percentage = ($value / $dailyValues[$nutrient]) * 100;
                $percentages["{$nutrient}_dv_percentage"] = round($percentage, 1);
            }
        }
        
        return $percentages;
    }

    /**
     * Validate nutritional data
     */
    public function validateNutritionalData(array $nutrition): array
    {
        $errors = [];
        $warnings = [];
        
        // Check for required nutrients
        $requiredNutrients = ['calories', 'protein', 'fat', 'carbs'];
        foreach ($requiredNutrients as $nutrient) {
            if (!isset($nutrition[$nutrient]) && !isset($nutrition["{$nutrient}_per_100g"])) {
                $errors[] = "Missing required nutrient: {$nutrient}";
            }
        }
        
        // Check for realistic values
        $calories = $nutrition['calories'] ?? $nutrition['calories_per_100g'] ?? 0;
        $protein = $nutrition['protein'] ?? $nutrition['protein_per_100g'] ?? 0;
        $fat = $nutrition['fat'] ?? $nutrition['fat_per_100g'] ?? 0;
        $carbs = $nutrition['carbs'] ?? $nutrition['carbohydrates_per_100g'] ?? 0;
        
        // Calculate calories from macros
        $calculatedCalories = ($protein * 4) + ($fat * 9) + ($carbs * 4);
        $calorieDiscrepancy = abs($calories - $calculatedCalories);
        
        if ($calorieDiscrepancy > ($calories * 0.1)) { // More than 10% difference
            $warnings[] = "Calorie count may be inaccurate. Calculated: {$calculatedCalories}, Provided: {$calories}";
        }
        
        // Check for unrealistic values
        if ($calories < 0 || $calories > 900) { // per 100g
            $warnings[] = "Unusual calorie content: {$calories} per 100g";
        }
        
        if ($protein < 0 || $protein > 100) {
            $warnings[] = "Unusual protein content: {$protein}g per 100g";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
}