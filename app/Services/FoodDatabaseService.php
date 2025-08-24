<?php

namespace App\Services;

use App\Models\Food;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FoodDatabaseService
{
    // USDA FoodData Central API
    private const USDA_BASE_URL = 'https://api.nal.usda.gov/fdc/v1';
    private const USDA_API_KEY_ENV = 'USDA_API_KEY';
    
    // Edamam Nutrition API (fallback)
    private const EDAMAM_BASE_URL = 'https://api.edamam.com/api/food-database/v2';
    private const EDAMAM_APP_ID_ENV = 'EDAMAM_APP_ID';
    private const EDAMAM_APP_KEY_ENV = 'EDAMAM_APP_KEY';
    
    // Cache configuration
    private const CACHE_TTL_HOURS = 24;
    private const SEARCH_CACHE_TTL_MINUTES = 30;
    private const DETAILS_CACHE_TTL_HOURS = 48;
    
    // Food data types
    private const USDA_DATA_TYPES = [
        'Foundation' => 'foundation_food',
        'SR Legacy' => 'sr_legacy_food', 
        'Survey (FNDDS)' => 'survey_fndds_food',
        'Branded' => 'branded_food'
    ];

    /**
     * Search foods using USDA API with Edamam fallback
     */
    public function searchFoods(
        string $query, 
        int $limit = 50, 
        array $filters = [],
        bool $useCache = true
    ): array {
        $cacheKey = $this->generateSearchCacheKey($query, $limit, $filters);
        
        if ($useCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            // Try USDA API first
            $results = $this->searchUSDA($query, $limit, $filters);
            
            if (empty($results['foods'])) {
                // Fallback to Edamam if USDA returns no results
                Log::info('USDA returned no results, falling back to Edamam', ['query' => $query]);
                $results = $this->searchEdamam($query, $limit, $filters);
            }

        } catch (\Exception $e) {
            Log::error('USDA API failed, falling back to Edamam', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            
            // Fallback to Edamam
            try {
                $results = $this->searchEdamam($query, $limit, $filters);
            } catch (\Exception $fallbackError) {
                Log::error('Both USDA and Edamam APIs failed', [
                    'query' => $query,
                    'usda_error' => $e->getMessage(),
                    'edamam_error' => $fallbackError->getMessage()
                ]);
                
                // Return local database results as final fallback
                $results = $this->searchLocal($query, $limit, $filters);
            }
        }

        // Cache successful results
        if (!empty($results['foods'])) {
            Cache::put($cacheKey, $results, now()->addMinutes(self::SEARCH_CACHE_TTL_MINUTES));
        }

        return $results;
    }

    /**
     * Get detailed food information by FDC ID
     */
    public function getFoodDetails(string $fdcId, string $source = 'usda'): ?array
    {
        $cacheKey = "food_details_{$source}_{$fdcId}";
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $details = null;
            
            switch ($source) {
                case 'usda':
                    $details = $this->getUSDAFoodDetails($fdcId);
                    break;
                case 'edamam':
                    $details = $this->getEdamamFoodDetails($fdcId);
                    break;
                default:
                    $details = $this->getLocalFoodDetails($fdcId);
            }

            if ($details) {
                Cache::put($cacheKey, $details, now()->addHours(self::DETAILS_CACHE_TTL_HOURS));
            }

            return $details;

        } catch (\Exception $e) {
            Log::error("Failed to get {$source} food details", [
                'fdc_id' => $fdcId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Search USDA FoodData Central API
     */
    private function searchUSDA(string $query, int $limit, array $filters): array
    {
        $apiKey = config('services.usda.api_key', env(self::USDA_API_KEY_ENV));
        
        if (!$apiKey) {
            throw new \Exception('USDA API key not configured');
        }

        $params = [
            'query' => $query,
            'pageSize' => min($limit, 200), // USDA max is 200
            'api_key' => $apiKey,
            'sortBy' => 'dataType.keyword',
            'sortOrder' => 'asc'
        ];

        // Apply filters
        if (!empty($filters['dataType'])) {
            $params['dataType'] = $filters['dataType'];
        }

        if (!empty($filters['brandOwner'])) {
            $params['brandOwner'] = $filters['brandOwner'];
        }

        $response = Http::timeout(10)->get(self::USDA_BASE_URL . '/foods/search', $params);

        if (!$response->successful()) {
            throw new \Exception("USDA API error: " . $response->status());
        }

        $data = $response->json();
        
        return [
            'foods' => $this->formatUSDAResults($data['foods'] ?? []),
            'total_hits' => $data['totalHits'] ?? 0,
            'current_page' => $data['currentPage'] ?? 1,
            'total_pages' => $data['totalPages'] ?? 1,
            'source' => 'usda'
        ];
    }

    /**
     * Search Edamam Food Database API
     */
    private function searchEdamam(string $query, int $limit, array $filters): array
    {
        $appId = config('services.edamam.app_id', env(self::EDAMAM_APP_ID_ENV));
        $appKey = config('services.edamam.app_key', env(self::EDAMAM_APP_KEY_ENV));
        
        if (!$appId || !$appKey) {
            throw new \Exception('Edamam API credentials not configured');
        }

        $params = [
            'app_id' => $appId,
            'app_key' => $appKey,
            'ingr' => $query,
            'nutrition-type' => 'cooking'
        ];

        // Apply category filter if specified
        if (!empty($filters['category'])) {
            $params['category'] = $filters['category'];
        }

        $response = Http::timeout(10)->get(self::EDAMAM_BASE_URL . '/parser', $params);

        if (!$response->successful()) {
            throw new \Exception("Edamam API error: " . $response->status());
        }

        $data = $response->json();
        
        $foods = array_slice($data['hints'] ?? [], 0, $limit);

        return [
            'foods' => $this->formatEdamamResults($foods),
            'total_hits' => count($foods),
            'current_page' => 1,
            'total_pages' => 1,
            'source' => 'edamam'
        ];
    }

    /**
     * Search local database
     */
    private function searchLocal(string $query, int $limit, array $filters): array
    {
        $queryBuilder = Food::where('name', 'LIKE', "%{$query}%")
            ->orWhere('description', 'LIKE', "%{$query}%")
            ->orWhere('brand_name', 'LIKE', "%{$query}%");

        // Apply filters
        if (!empty($filters['category'])) {
            $queryBuilder->where('category', $filters['category']);
        }

        if (!empty($filters['brand'])) {
            $queryBuilder->where('brand_name', 'LIKE', "%{$filters['brand']}%");
        }

        $foods = $queryBuilder->limit($limit)->get();

        return [
            'foods' => $this->formatLocalResults($foods->toArray()),
            'total_hits' => $foods->count(),
            'current_page' => 1,
            'total_pages' => 1,
            'source' => 'local'
        ];
    }

    /**
     * Get USDA food details
     */
    private function getUSDAFoodDetails(string $fdcId): ?array
    {
        $apiKey = config('services.usda.api_key', env(self::USDA_API_KEY_ENV));
        
        if (!$apiKey) {
            return null;
        }

        $response = Http::timeout(10)->get(self::USDA_BASE_URL . "/food/{$fdcId}", [
            'api_key' => $apiKey,
            'format' => 'full'
        ]);

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        return $this->formatUSDAFoodDetails($data);
    }

    /**
     * Get Edamam food details
     */
    private function getEdamamFoodDetails(string $foodId): ?array
    {
        $appId = config('services.edamam.app_id', env(self::EDAMAM_APP_ID_ENV));
        $appKey = config('services.edamam.app_key', env(self::EDAMAM_APP_KEY_ENV));
        
        if (!$appId || !$appKey) {
            return null;
        }

        $response = Http::timeout(10)->post(self::EDAMAM_BASE_URL . '/nutrients', [
            'ingredients' => [
                [
                    'quantity' => 100,
                    'measureURI' => 'http://www.edamam.com/ontologies/edamam.owl#Measure_gram',
                    'foodId' => $foodId
                ]
            ]
        ], [
            'app_id' => $appId,
            'app_key' => $appKey
        ]);

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        return $this->formatEdamamFoodDetails($data);
    }

    /**
     * Get local food details
     */
    private function getLocalFoodDetails(string $id): ?array
    {
        $food = Food::find($id);
        return $food ? $this->formatLocalFoodDetails($food->toArray()) : null;
    }

    /**
     * Format USDA search results
     */
    private function formatUSDAResults(array $foods): array
    {
        return array_map(function ($food) {
            return [
                'id' => $food['fdcId'],
                'source' => 'usda',
                'name' => $food['description'] ?? '',
                'brand_name' => $food['brandName'] ?? $food['brandOwner'] ?? null,
                'category' => $food['foodCategory'] ?? null,
                'data_type' => $food['dataType'] ?? null,
                'published_date' => $food['publishedDate'] ?? null,
                'ingredients' => $food['ingredients'] ?? null,
                'serving_size' => $food['servingSize'] ?? null,
                'serving_size_unit' => $food['servingSizeUnit'] ?? null,
                'household_serving_fulltext' => $food['householdServingFullText'] ?? null,
                'nutrients_preview' => $this->extractNutrientsPreview($food['foodNutrients'] ?? [])
            ];
        }, $foods);
    }

    /**
     * Format Edamam search results
     */
    private function formatEdamamResults(array $hints): array
    {
        return array_map(function ($hint) {
            $food = $hint['food'];
            return [
                'id' => $food['foodId'],
                'source' => 'edamam',
                'name' => $food['label'] ?? '',
                'brand_name' => $food['brand'] ?? null,
                'category' => $food['category'] ?? null,
                'category_label' => $food['categoryLabel'] ?? null,
                'image' => $food['image'] ?? null,
                'nutrients_preview' => [
                    'calories' => $food['nutrients']['ENERC_KCAL'] ?? null,
                    'protein' => $food['nutrients']['PROCNT'] ?? null,
                    'fat' => $food['nutrients']['FAT'] ?? null,
                    'carbs' => $food['nutrients']['CHOCDF'] ?? null,
                    'fiber' => $food['nutrients']['FIBTG'] ?? null
                ]
            ];
        }, $hints);
    }

    /**
     * Format local database results
     */
    private function formatLocalResults(array $foods): array
    {
        return array_map(function ($food) {
            return [
                'id' => $food['id'],
                'source' => 'local',
                'name' => $food['name'],
                'brand_name' => $food['brand_name'],
                'category' => $food['category'],
                'description' => $food['description'],
                'nutrients_preview' => [
                    'calories' => $food['calories_per_100g'],
                    'protein' => $food['protein_per_100g'],
                    'fat' => $food['fat_per_100g'],
                    'carbs' => $food['carbohydrates_per_100g'],
                    'fiber' => $food['fiber_per_100g']
                ]
            ];
        }, $foods);
    }

    /**
     * Format USDA food details
     */
    private function formatUSDAFoodDetails(array $data): array
    {
        $nutrients = [];
        foreach ($data['foodNutrients'] ?? [] as $nutrient) {
            $nutrients[$this->mapUSDANutrientName($nutrient['nutrient']['name'])] = [
                'amount' => $nutrient['amount'] ?? 0,
                'unit' => $nutrient['nutrient']['unitName'] ?? 'g'
            ];
        }

        return [
            'id' => $data['fdcId'],
            'source' => 'usda',
            'name' => $data['description'],
            'brand_name' => $data['brandName'] ?? $data['brandOwner'] ?? null,
            'category' => $data['foodCategory'] ?? null,
            'data_type' => $data['dataType'],
            'ingredients' => $data['ingredients'] ?? null,
            'serving_size' => $data['servingSize'] ?? 100,
            'serving_size_unit' => $data['servingSizeUnit'] ?? 'g',
            'nutrients' => $nutrients,
            'publication_date' => $data['publishedDate'] ?? null
        ];
    }

    /**
     * Format Edamam food details
     */
    private function formatEdamamFoodDetails(array $data): array
    {
        $nutrients = [];
        if (isset($data['totalNutrients'])) {
            foreach ($data['totalNutrients'] as $key => $nutrient) {
                $nutrients[$this->mapEdamamNutrientName($key)] = [
                    'amount' => $nutrient['quantity'] ?? 0,
                    'unit' => $nutrient['unit'] ?? 'g'
                ];
            }
        }

        return [
            'id' => $data['uri'] ?? '',
            'source' => 'edamam',
            'name' => $data['ingredients'][0]['parsed'][0]['food'] ?? '',
            'nutrients' => $nutrients,
            'serving_size' => 100,
            'serving_size_unit' => 'g'
        ];
    }

    /**
     * Format local food details
     */
    private function formatLocalFoodDetails(array $data): array
    {
        return [
            'id' => $data['id'],
            'source' => 'local',
            'name' => $data['name'],
            'brand_name' => $data['brand_name'],
            'category' => $data['category'],
            'description' => $data['description'],
            'serving_size' => $data['serving_size'] ?? 100,
            'serving_size_unit' => $data['serving_unit'] ?? 'g',
            'nutrients' => [
                'calories' => ['amount' => $data['calories_per_100g'], 'unit' => 'kcal'],
                'protein' => ['amount' => $data['protein_per_100g'], 'unit' => 'g'],
                'fat' => ['amount' => $data['fat_per_100g'], 'unit' => 'g'],
                'carbs' => ['amount' => $data['carbohydrates_per_100g'], 'unit' => 'g'],
                'fiber' => ['amount' => $data['fiber_per_100g'], 'unit' => 'g'],
                'sugar' => ['amount' => $data['sugar_per_100g'], 'unit' => 'g'],
                'sodium' => ['amount' => $data['sodium_per_100g'], 'unit' => 'mg']
            ]
        ];
    }

    /**
     * Extract nutrients preview from USDA data
     */
    private function extractNutrientsPreview(array $nutrients): array
    {
        $preview = [];
        foreach ($nutrients as $nutrient) {
            $name = strtolower($nutrient['nutrient']['name'] ?? '');
            $amount = $nutrient['amount'] ?? 0;

            if (str_contains($name, 'energy') && str_contains($name, 'kcal')) {
                $preview['calories'] = $amount;
            } elseif (str_contains($name, 'protein')) {
                $preview['protein'] = $amount;
            } elseif (str_contains($name, 'total lipid') || str_contains($name, 'fat')) {
                $preview['fat'] = $amount;
            } elseif (str_contains($name, 'carbohydrate')) {
                $preview['carbs'] = $amount;
            } elseif (str_contains($name, 'fiber')) {
                $preview['fiber'] = $amount;
            }
        }

        return $preview;
    }

    /**
     * Map USDA nutrient names to standard names
     */
    private function mapUSDANutrientName(string $name): string
    {
        $mappings = [
            'Energy' => 'calories',
            'Energy (Atwater General Factors)' => 'calories',
            'Energy (Atwater Specific Factors)' => 'calories',
            'Protein' => 'protein',
            'Total lipid (fat)' => 'fat',
            'Carbohydrate, by difference' => 'carbs',
            'Fiber, total dietary' => 'fiber',
            'Sugars, total including NLEA' => 'sugar',
            'Sodium, Na' => 'sodium',
            'Calcium, Ca' => 'calcium',
            'Iron, Fe' => 'iron',
            'Vitamin C, total ascorbic acid' => 'vitamin_c',
            'Vitamin A, IU' => 'vitamin_a'
        ];

        return $mappings[$name] ?? strtolower(str_replace([' ', ',', '(', ')'], '_', $name));
    }

    /**
     * Map Edamam nutrient keys to standard names
     */
    private function mapEdamamNutrientName(string $key): string
    {
        $mappings = [
            'ENERC_KCAL' => 'calories',
            'PROCNT' => 'protein',
            'FAT' => 'fat',
            'CHOCDF' => 'carbs',
            'FIBTG' => 'fiber',
            'SUGAR' => 'sugar',
            'NA' => 'sodium',
            'CA' => 'calcium',
            'FE' => 'iron',
            'VITC' => 'vitamin_c',
            'VITA_IU' => 'vitamin_a'
        ];

        return $mappings[$key] ?? strtolower($key);
    }

    /**
     * Generate cache key for search results
     */
    private function generateSearchCacheKey(string $query, int $limit, array $filters): string
    {
        $filtersString = http_build_query($filters);
        return "food_search_" . md5($query . $limit . $filtersString);
    }

    /**
     * Clear food cache
     */
    public function clearCache(string $pattern = 'food_*'): bool
    {
        try {
            // This would depend on your cache driver
            // For Redis, you could use SCAN to find keys
            Cache::tags(['foods'])->flush();
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to clear food cache', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get popular/trending foods
     */
    public function getPopularFoods(int $limit = 20): array
    {
        $cacheKey = "popular_foods_{$limit}";
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Get most frequently searched foods from local database
        $popularFoods = Food::withCount('foodLogs')
            ->orderBy('food_logs_count', 'desc')
            ->limit($limit)
            ->get();

        $result = [
            'foods' => $this->formatLocalResults($popularFoods->toArray()),
            'source' => 'local'
        ];

        Cache::put($cacheKey, $result, now()->addHours(4));
        
        return $result;
    }

    /**
     * Get food categories
     */
    public function getFoodCategories(): array
    {
        $cacheKey = 'food_categories';
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Get categories from local database
        $categories = Food::distinct('category')
            ->whereNotNull('category')
            ->pluck('category')
            ->filter()
            ->sort()
            ->values()
            ->toArray();

        Cache::put($cacheKey, $categories, now()->addHours(24));
        
        return $categories;
    }

    /**
     * Import and cache food from external API
     */
    public function importAndCacheFood(string $id, string $source = 'usda'): ?Food
    {
        $foodDetails = $this->getFoodDetails($id, $source);
        
        if (!$foodDetails) {
            return null;
        }

        // Check if food already exists in local database
        $existingFood = Food::where('external_id', $id)
            ->where('source', $source)
            ->first();

        if ($existingFood) {
            // Update existing food
            $existingFood->update($this->mapToFoodModel($foodDetails));
            return $existingFood;
        }

        // Create new food entry
        $foodData = $this->mapToFoodModel($foodDetails);
        $foodData['external_id'] = $id;
        $foodData['source'] = $source;

        return Food::create($foodData);
    }

    /**
     * Map API food data to Food model structure
     */
    private function mapToFoodModel(array $foodDetails): array
    {
        $nutrients = $foodDetails['nutrients'] ?? [];

        return [
            'name' => $foodDetails['name'],
            'brand_name' => $foodDetails['brand_name'],
            'category' => $foodDetails['category'],
            'description' => $foodDetails['description'] ?? null,
            'serving_size' => $foodDetails['serving_size'] ?? 100,
            'serving_unit' => $foodDetails['serving_size_unit'] ?? 'g',
            'calories_per_100g' => $nutrients['calories']['amount'] ?? 0,
            'protein_per_100g' => $nutrients['protein']['amount'] ?? 0,
            'fat_per_100g' => $nutrients['fat']['amount'] ?? 0,
            'carbohydrates_per_100g' => $nutrients['carbs']['amount'] ?? 0,
            'fiber_per_100g' => $nutrients['fiber']['amount'] ?? 0,
            'sugar_per_100g' => $nutrients['sugar']['amount'] ?? 0,
            'sodium_per_100g' => isset($nutrients['sodium']) ? $nutrients['sodium']['amount'] / 1000 : 0, // Convert mg to g
            'last_updated' => Carbon::now()
        ];
    }
}