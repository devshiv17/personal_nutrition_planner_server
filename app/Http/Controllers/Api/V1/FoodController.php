<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Food;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FoodController extends BaseApiController
{
    /**
     * Search for foods
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $validatedData = $this->validateRequest($request, [
                'query' => 'required|string|min:2|max:100',
                'category' => 'sometimes|string|max:100',
                'brand' => 'sometimes|string|max:100',
                'verified_only' => 'sometimes|boolean',
                'per_page' => 'sometimes|integer|min:1|max:50',
            ]);

            $paginationParams = $this->getPaginationParams($request);
            $query = Food::query();

            // Text search on name
            $searchTerm = $validatedData['query'];
            $query->whereRaw("to_tsvector('english', name) @@ plainto_tsquery('english', ?)", [$searchTerm])
                  ->orWhere('name', 'ILIKE', "%{$searchTerm}%");

            // Apply filters
            $allowedFilters = [
                'category' => 'category',
                'brand' => 'brand',
                'verified_only' => ['method' => 'where', 'column' => 'is_verified'],
            ];
            $query = $this->applyFilters($query, $request, $allowedFilters);

            // Apply sorting - prioritize verified foods and usage count
            $query->orderByDesc('is_verified')
                  ->orderByDesc('usage_count')
                  ->orderBy('name');

            $foods = $query->paginate($paginationParams['per_page']);

            return $this->paginatedResponse($foods, 'Foods retrieved successfully');

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to search foods');
        }
    }

    /**
     * Get specific food details
     */
    public function show(string $id): JsonResponse
    {
        try {
            $food = Food::find($id);

            if (!$food) {
                return $this->notFoundResponse('Food not found');
            }

            // Increment usage count
            $food->increment('usage_count');

            return $this->successResponse($food, 'Food details retrieved successfully');

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to retrieve food details');
        }
    }

    /**
     * Get popular foods
     */
    public function popular(Request $request): JsonResponse
    {
        try {
            $validatedData = $this->validateRequest($request, [
                'category' => 'sometimes|string|max:100',
                'limit' => 'sometimes|integer|min:1|max:50',
            ]);

            $limit = $validatedData['limit'] ?? 20;
            $query = Food::query()->where('is_verified', true);

            if (isset($validatedData['category'])) {
                $query->where('category', $validatedData['category']);
            }

            $foods = $query->orderByDesc('usage_count')
                          ->orderBy('name')
                          ->limit($limit)
                          ->get();

            return $this->successResponse($foods, 'Popular foods retrieved successfully');

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to retrieve popular foods');
        }
    }

    /**
     * Get food categories
     */
    public function categories(): JsonResponse
    {
        try {
            $categories = Food::select('category')
                             ->whereNotNull('category')
                             ->groupBy('category')
                             ->orderBy('category')
                             ->pluck('category');

            return $this->successResponse($categories, 'Food categories retrieved successfully');

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to retrieve food categories');
        }
    }

    /**
     * Create new food (for verified users or admin)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            $validatedData = $this->validateRequest($request, [
                'name' => 'required|string|max:255',
                'brand' => 'sometimes|nullable|string|max:100',
                'category' => 'sometimes|nullable|string|max:100',
                'subcategory' => 'sometimes|nullable|string|max:100',
                'calories_per_100g' => 'required|numeric|min:0|max:9999',
                'protein_per_100g' => 'sometimes|numeric|min:0|max:100',
                'carbs_per_100g' => 'sometimes|numeric|min:0|max:100',
                'fat_per_100g' => 'sometimes|numeric|min:0|max:100',
                'fiber_per_100g' => 'sometimes|numeric|min:0|max:100',
                'sugar_per_100g' => 'sometimes|numeric|min:0|max:100',
                'sodium_per_100g' => 'sometimes|numeric|min:0|max:10000',
                'common_serving_size' => 'sometimes|nullable|numeric|min:0',
                'common_serving_unit' => 'sometimes|nullable|string|max:20',
                'barcode' => 'sometimes|nullable|string|max:50',
                'additional_nutrients' => 'sometimes|nullable|array',
            ]);

            $validatedData['created_by'] = $user->id;
            $validatedData['data_source'] = 'user_created';
            $validatedData['is_verified'] = false; // Admin verification required

            $food = Food::create($validatedData);

            return $this->successResponse(
                $food,
                'Food created successfully. It will be reviewed for verification.',
                201
            );

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to create food');
        }
    }

    /**
     * Update food (only for food creator or admin)
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            $food = Food::find($id);

            if (!$food) {
                return $this->notFoundResponse('Food not found');
            }

            // Check if user can update this food
            if ($food->created_by !== $user->id) {
                return $this->forbiddenResponse('You can only update foods you created');
            }

            $validatedData = $this->validateRequest($request, [
                'name' => 'sometimes|required|string|max:255',
                'brand' => 'sometimes|nullable|string|max:100',
                'category' => 'sometimes|nullable|string|max:100',
                'subcategory' => 'sometimes|nullable|string|max:100',
                'calories_per_100g' => 'sometimes|required|numeric|min:0|max:9999',
                'protein_per_100g' => 'sometimes|numeric|min:0|max:100',
                'carbs_per_100g' => 'sometimes|numeric|min:0|max:100',
                'fat_per_100g' => 'sometimes|numeric|min:0|max:100',
                'fiber_per_100g' => 'sometimes|numeric|min:0|max:100',
                'sugar_per_100g' => 'sometimes|numeric|min:0|max:100',
                'sodium_per_100g' => 'sometimes|numeric|min:0|max:10000',
                'common_serving_size' => 'sometimes|nullable|numeric|min:0',
                'common_serving_unit' => 'sometimes|nullable|string|max:20',
                'barcode' => 'sometimes|nullable|string|max:50',
                'additional_nutrients' => 'sometimes|nullable|array',
            ]);

            $food->update($validatedData);

            return $this->successResponse($food->fresh(), 'Food updated successfully');

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to update food');
        }
    }
}