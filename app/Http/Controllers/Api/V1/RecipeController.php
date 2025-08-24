<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeCollection;
use App\Services\NutritionalCalculationService;
use App\Services\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecipeController extends BaseApiController
{
    protected NutritionalCalculationService $nutritionalCalculationService;
    protected ImageUploadService $imageUploadService;

    public function __construct(
        NutritionalCalculationService $nutritionalCalculationService,
        ImageUploadService $imageUploadService
    ) {
        $this->nutritionalCalculationService = $nutritionalCalculationService;
        $this->imageUploadService = $imageUploadService;
    }

    /**
     * Get recipes with advanced filtering
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'search' => 'sometimes|string|max:255',
                'meal_category' => 'sometimes|string|in:' . implode(',', Recipe::MEAL_CATEGORIES),
                'cuisine_type' => 'sometimes|string|in:' . implode(',', Recipe::CUISINE_TYPES),
                'difficulty_level' => 'sometimes|integer|min:1|max:4',
                'max_cooking_time' => 'sometimes|integer|min:1',
                'dietary_preferences' => 'sometimes|array',
                'dietary_preferences.*' => 'string',
                'allergen_free' => 'sometimes|array',
                'allergen_free.*' => 'string',
                'min_rating' => 'sometimes|numeric|min:0|max:5',
                'created_by' => 'sometimes|integer',
                'is_public' => 'sometimes|boolean',
                'per_page' => 'sometimes|integer|min:1|max:50'
            ]);

            $query = Recipe::query();
            $user = $request->user();

            // Base access control
            if ($user) {
                $query->forUser($user->id);
            } else {
                $query->public()->verified();
            }

            // Apply search filters
            if (!empty($validatedData['search'])) {
                $query->searchByName($validatedData['search']);
            }

            if (!empty($validatedData['meal_category'])) {
                $query->byMealCategory($validatedData['meal_category']);
            }

            if (!empty($validatedData['cuisine_type'])) {
                $query->byCuisine($validatedData['cuisine_type']);
            }

            if (!empty($validatedData['difficulty_level'])) {
                $query->byDifficulty($validatedData['difficulty_level']);
            }

            if (!empty($validatedData['max_cooking_time'])) {
                $query->maxCookingTime($validatedData['max_cooking_time']);
            }

            if (!empty($validatedData['min_rating'])) {
                $query->minRating($validatedData['min_rating']);
            }

            if (!empty($validatedData['dietary_preferences'])) {
                foreach ($validatedData['dietary_preferences'] as $preference) {
                    $query->byDietaryPreference($preference);
                }
            }

            if (!empty($validatedData['allergen_free'])) {
                $query->allergenFree($validatedData['allergen_free']);
            }

            if (!empty($validatedData['created_by'])) {
                $query->where('created_by', $validatedData['created_by']);
            }

            if (isset($validatedData['is_public'])) {
                if ($validatedData['is_public']) {
                    $query->public();
                } else {
                    $query->where('created_by', $user?->id);
                }
            }

            // Load relationships and paginate
            $recipes = $query->with(['creator:id,first_name,last_name', 'ingredients.food'])
                            ->orderByDesc('average_rating')
                            ->orderByDesc('created_at')
                            ->paginate($validatedData['per_page'] ?? 20);

            return $this->success([
                'recipes' => $recipes->items(),
                'pagination' => [
                    'current_page' => $recipes->currentPage(),
                    'total_pages' => $recipes->lastPage(),
                    'per_page' => $recipes->perPage(),
                    'total' => $recipes->total()
                ]
            ], 'Recipes retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve recipes', 500, $e->getMessage());
        }
    }

    /**
     * Get specific recipe with full details
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $query = Recipe::with([
                'creator:id,first_name,last_name',
                'ingredients.food:id,name,brand_name,calories_per_100g,protein_per_100g,carbohydrates_per_100g,fat_per_100g,fiber_per_100g,sugar_per_100g,sodium_per_100g',
                'ratings.user:id,first_name,last_name'
            ]);

            if ($user) {
                $recipe = $query->forUser($user->id)->findOrFail($id);
            } else {
                $recipe = $query->public()->verified()->findOrFail($id);
            }

            // Add user-specific data if authenticated
            $recipeData = $recipe->toArray();
            if ($user) {
                $recipeData['is_favorited'] = $user->favoriteRecipes()->where('recipe_id', $id)->exists();
                $recipeData['user_rating'] = $recipe->ratings()
                    ->where('user_id', $user->id)
                    ->value('rating');
            }

            return $this->success($recipeData, 'Recipe retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Recipe not found', 404, $e->getMessage());
        }
    }

    /**
     * Create new recipe
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'required|string|max:1000',
                'instructions' => 'required|array|min:1',
                'instructions.*' => 'required|string|max:1000',
                'prep_time_minutes' => 'required|integer|min:0|max:1440',
                'cook_time_minutes' => 'required|integer|min:0|max:1440',
                'servings' => 'required|integer|min:1|max:50',
                'difficulty_level' => 'required|integer|min:1|max:4',
                'cuisine_type' => 'sometimes|string|in:' . implode(',', Recipe::CUISINE_TYPES),
                'meal_category' => 'required|string|in:' . implode(',', Recipe::MEAL_CATEGORIES),
                'dietary_preferences' => 'sometimes|array',
                'dietary_preferences.*' => 'string',
                'allergens' => 'sometimes|array',
                'allergens.*' => 'string',
                'tags' => 'sometimes|array',
                'tags.*' => 'string|max:50',
                'equipment_needed' => 'sometimes|array',
                'equipment_needed.*' => 'string|max:100',
                'image_url' => 'sometimes|url',
                'video_url' => 'sometimes|url',
                'source_url' => 'sometimes|url',
                'is_public' => 'sometimes|boolean',
                'storage_instructions' => 'sometimes|string|max:500',
                'nutritional_notes' => 'sometimes|string|max:500',
                'ingredients' => 'required|array|min:1',
                'ingredients.*.food_id' => 'sometimes|integer|exists:foods,id',
                'ingredients.*.ingredient_name' => 'required_without:ingredients.*.food_id|string|max:255',
                'ingredients.*.amount' => 'required|numeric|min:0',
                'ingredients.*.unit' => 'required|string|max:20',
                'ingredients.*.preparation_notes' => 'sometimes|string|max:255',
                'ingredients.*.is_optional' => 'sometimes|boolean',
                'ingredients.*.group_name' => 'sometimes|string|max:100'
            ]);

            return DB::transaction(function () use ($validatedData, $user) {
                // Calculate total time
                $validatedData['total_time_minutes'] = $validatedData['prep_time_minutes'] + $validatedData['cook_time_minutes'];
                $validatedData['created_by'] = $user->id;
                $validatedData['is_public'] = $validatedData['is_public'] ?? false;

                // Create recipe
                $recipe = Recipe::create($validatedData);

                // Add ingredients
                foreach ($validatedData['ingredients'] as $index => $ingredientData) {
                    $ingredientData['recipe_id'] = $recipe->id;
                    $ingredientData['order'] = $index + 1;
                    
                    // Convert amount to grams if possible
                    if (isset($ingredientData['food_id'])) {
                        $food = \App\Models\Food::find($ingredientData['food_id']);
                        if ($food) {
                            $ingredientData['amount_grams'] = $this->nutritionalCalculationService
                                ->convertToGrams($ingredientData['amount'], $ingredientData['unit'], $food->name);
                        }
                    }

                    RecipeIngredient::create($ingredientData);
                }

                // Calculate and update nutrition
                $recipe->updateNutritionFromIngredients();

                // Load relationships for response
                $recipe->load(['creator:id,first_name,last_name', 'ingredients.food']);

                return $this->success($recipe, 'Recipe created successfully', 201);
            });

        } catch (\Exception $e) {
            return $this->error('Failed to create recipe', 500, $e->getMessage());
        }
    }

    /**
     * Update recipe
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $recipe = Recipe::where('id', $id)
                           ->where('created_by', $user->id)
                           ->firstOrFail();

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'sometimes|string|max:1000',
                'instructions' => 'sometimes|array|min:1',
                'instructions.*' => 'required|string|max:1000',
                'prep_time_minutes' => 'sometimes|integer|min:0|max:1440',
                'cook_time_minutes' => 'sometimes|integer|min:0|max:1440',
                'servings' => 'sometimes|integer|min:1|max:50',
                'difficulty_level' => 'sometimes|integer|min:1|max:4',
                'cuisine_type' => 'sometimes|string|in:' . implode(',', Recipe::CUISINE_TYPES),
                'meal_category' => 'sometimes|string|in:' . implode(',', Recipe::MEAL_CATEGORIES),
                'dietary_preferences' => 'sometimes|array',
                'allergens' => 'sometimes|array',
                'tags' => 'sometimes|array',
                'equipment_needed' => 'sometimes|array',
                'image_url' => 'sometimes|url',
                'video_url' => 'sometimes|url',
                'source_url' => 'sometimes|url',
                'is_public' => 'sometimes|boolean',
                'storage_instructions' => 'sometimes|string|max:500',
                'nutritional_notes' => 'sometimes|string|max:500',
                'ingredients' => 'sometimes|array|min:1'
            ]);

            return DB::transaction(function () use ($recipe, $validatedData) {
                // Update total time if prep or cook time changed
                if (isset($validatedData['prep_time_minutes']) || isset($validatedData['cook_time_minutes'])) {
                    $prepTime = $validatedData['prep_time_minutes'] ?? $recipe->prep_time_minutes;
                    $cookTime = $validatedData['cook_time_minutes'] ?? $recipe->cook_time_minutes;
                    $validatedData['total_time_minutes'] = $prepTime + $cookTime;
                }

                $recipe->update($validatedData);

                // Update ingredients if provided
                if (isset($validatedData['ingredients'])) {
                    // Delete existing ingredients
                    $recipe->ingredients()->delete();

                    // Add new ingredients
                    foreach ($validatedData['ingredients'] as $index => $ingredientData) {
                        $ingredientData['recipe_id'] = $recipe->id;
                        $ingredientData['order'] = $index + 1;
                        
                        // Convert amount to grams if possible
                        if (isset($ingredientData['food_id'])) {
                            $food = \App\Models\Food::find($ingredientData['food_id']);
                            if ($food) {
                                $ingredientData['amount_grams'] = $this->nutritionalCalculationService
                                    ->convertToGrams($ingredientData['amount'], $ingredientData['unit'], $food->name);
                            }
                        }

                        RecipeIngredient::create($ingredientData);
                    }

                    // Recalculate nutrition
                    $recipe->updateNutritionFromIngredients();
                }

                // Load relationships for response
                $recipe->load(['creator:id,first_name,last_name', 'ingredients.food']);

                return $this->success($recipe, 'Recipe updated successfully');
            });

        } catch (\Exception $e) {
            return $this->error('Failed to update recipe', 500, $e->getMessage());
        }
    }

    /**
     * Delete recipe
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $recipe = Recipe::where('id', $id)
                           ->where('created_by', $user->id)
                           ->firstOrFail();

            $recipe->delete();

            return $this->success(null, 'Recipe deleted successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to delete recipe', 500, $e->getMessage());
        }
    }

    /**
     * Scale recipe for different serving sizes
     */
    public function scale(Request $request, string $id): JsonResponse
    {
        try {
            $request->validate([
                'servings' => 'required|integer|min:1|max:100'
            ]);

            $recipe = Recipe::with('ingredients.food')->findOrFail($id);
            $newServings = $request->get('servings');

            $scaledRecipe = $recipe->getServingsScaled($newServings);
            $scaleFactor = $scaledRecipe['scale_factor'];

            // Scale ingredients
            $scaledIngredients = [];
            foreach ($recipe->ingredients as $ingredient) {
                $scaledAmount = $ingredient->getScaledAmount($scaleFactor);
                $scaledIngredients[] = [
                    'ingredient' => $ingredient,
                    'scaled_amount' => $scaledAmount['amount'],
                    'scaled_amount_grams' => $scaledAmount['amount_grams'],
                    'formatted_amount' => $scaledAmount['formatted_amount']
                ];
            }

            return $this->success([
                'recipe' => $recipe,
                'original_servings' => $recipe->servings,
                'new_servings' => $newServings,
                'scale_factor' => $scaleFactor,
                'scaled_nutrition' => $scaledRecipe,
                'scaled_ingredients' => $scaledIngredients
            ], 'Recipe scaled successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to scale recipe', 500, $e->getMessage());
        }
    }

    /**
     * Add rating to recipe
     */
    public function addRating(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $validatedData = $request->validate([
                'rating' => 'required|integer|min:1|max:5',
                'review' => 'sometimes|string|max:1000'
            ]);

            $recipe = Recipe::findOrFail($id);
            
            $recipe->addRating(
                $user->id,
                $validatedData['rating'],
                $validatedData['review'] ?? null
            );

            return $this->success([
                'average_rating' => $recipe->fresh()->average_rating,
                'total_ratings' => $recipe->fresh()->total_ratings
            ], 'Rating added successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to add rating', 500, $e->getMessage());
        }
    }

    /**
     * Get recipe collections
     */
    public function collections(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $query = RecipeCollection::query();
            
            if ($user) {
                $query->forUser($user->id);
            } else {
                $query->public();
            }

            $collections = $query->with(['creator:id,first_name,last_name'])
                                ->withCount('recipes')
                                ->orderByDesc('is_featured')
                                ->orderByDesc('created_at')
                                ->get();

            return $this->success($collections, 'Recipe collections retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve collections', 500, $e->getMessage());
        }
    }

    /**
     * Create recipe collection
     */
    public function createCollection(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'sometimes|string|max:1000',
                'image_url' => 'sometimes|url',
                'is_public' => 'sometimes|boolean',
                'tags' => 'sometimes|array',
                'tags.*' => 'string|max:50',
                'color_theme' => 'sometimes|string|max:50'
            ]);

            $validatedData['created_by'] = $user->id;
            $validatedData['is_public'] = $validatedData['is_public'] ?? false;

            $collection = RecipeCollection::create($validatedData);

            return $this->success($collection, 'Recipe collection created successfully', 201);

        } catch (\Exception $e) {
            return $this->error('Failed to create collection', 500, $e->getMessage());
        }
    }

    /**
     * Add recipe to collection
     */
    public function addToCollection(Request $request, string $recipeId): JsonResponse
    {
        try {
            $user = $request->user();
            $validatedData = $request->validate([
                'collection_id' => 'required|integer|exists:recipe_collections,id',
                'notes' => 'sometimes|string|max:500'
            ]);

            $recipe = Recipe::findOrFail($recipeId);
            $collection = RecipeCollection::where('id', $validatedData['collection_id'])
                                        ->where('created_by', $user->id)
                                        ->firstOrFail();

            $collection->addRecipe($recipe, null, $validatedData['notes'] ?? null);

            return $this->success(null, 'Recipe added to collection successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to add recipe to collection', 500, $e->getMessage());
        }
    }

    /**
     * Get popular recipes
     */
    public function popular(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 20);
            
            $recipes = Recipe::public()
                           ->verified()
                           ->popular($limit)
                           ->with(['creator:id,first_name,last_name'])
                           ->get();

            return $this->success($recipes, 'Popular recipes retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve popular recipes', 500, $e->getMessage());
        }
    }

    /**
     * Duplicate recipe
     */
    public function duplicate(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $originalRecipe = Recipe::with('ingredients')->findOrFail($id);
            
            $duplicatedRecipe = $originalRecipe->duplicate($user->id);

            return $this->success($duplicatedRecipe, 'Recipe duplicated successfully', 201);

        } catch (\Exception $e) {
            return $this->error('Failed to duplicate recipe', 500, $e->getMessage());
        }
    }

    /**
     * Get user's favorite recipes
     */
    public function favorites(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $favorites = $user->favoriteRecipes()
                            ->with(['creator:id,first_name,last_name'])
                            ->orderBy('user_favorite_recipes.created_at', 'desc')
                            ->paginate(20);

            return $this->success([
                'recipes' => $favorites->items(),
                'pagination' => [
                    'current_page' => $favorites->currentPage(),
                    'total_pages' => $favorites->lastPage(),
                    'per_page' => $favorites->perPage(),
                    'total' => $favorites->total()
                ]
            ], 'Favorite recipes retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to get favorite recipes', 500, $e->getMessage());
        }
    }

    /**
     * Add recipe to favorites
     */
    public function addToFavorites(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $recipe = Recipe::findOrFail($id);
            
            if (!$user->favoriteRecipes()->where('recipe_id', $id)->exists()) {
                $user->favoriteRecipes()->attach($id);
            }

            return $this->success(null, 'Recipe added to favorites');

        } catch (\Exception $e) {
            return $this->error('Failed to add recipe to favorites', 500, $e->getMessage());
        }
    }

    /**
     * Remove recipe from favorites
     */
    public function removeFromFavorites(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $user->favoriteRecipes()->detach($id);

            return $this->success(null, 'Recipe removed from favorites');

        } catch (\Exception $e) {
            return $this->error('Failed to remove recipe from favorites', 500, $e->getMessage());
        }
    }

    /**
     * Upload images for a recipe
     */
    public function uploadImages(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $recipe = Recipe::where('id', $id)
                           ->where('created_by', $user->id)
                           ->firstOrFail();

            $request->validate([
                'images' => 'required|array|min:1|max:10',
                'images.*' => 'required|image|mimes:jpeg,jpg,png,webp,gif|max:10240', // 10MB max
                'alt_texts' => 'sometimes|array',
                'alt_texts.*' => 'sometimes|string|max:255',
                'captions' => 'sometimes|array',
                'captions.*' => 'sometimes|string|max:500'
            ]);

            $uploadedImages = [];
            $images = $request->file('images');
            $altTexts = $request->get('alt_texts', []);
            $captions = $request->get('captions', []);

            foreach ($images as $index => $file) {
                $uploadResult = $this->imageUploadService->uploadRecipeImage(
                    $file, 
                    $recipe->id, 
                    $index === 0 ? 'main' : 'additional'
                );

                // Add metadata
                $imageData = array_merge($uploadResult, [
                    'alt_text' => $altTexts[$index] ?? null,
                    'caption' => $captions[$index] ?? null,
                    'order' => count($recipe->images ?? []) + $index
                ]);

                $recipe->addImage($uploadResult['url'], $imageData);
                $uploadedImages[] = $imageData;
            }

            return $this->success([
                'uploaded_images' => $uploadedImages,
                'recipe' => $recipe->fresh(['creator:id,first_name,last_name'])
            ], 'Images uploaded successfully', 201);

        } catch (\Exception $e) {
            return $this->error('Failed to upload images', 500, $e->getMessage());
        }
    }

    /**
     * Delete a recipe image
     */
    public function deleteImage(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $recipe = Recipe::where('id', $id)
                           ->where('created_by', $user->id)
                           ->firstOrFail();

            $request->validate([
                'image_url' => 'required|string|url'
            ]);

            $imageUrl = $request->get('image_url');
            
            // Find the image in recipe's images array
            $currentImages = $recipe->images ?? [];
            $imageFound = false;
            
            foreach ($currentImages as $image) {
                if ($image['url'] === $imageUrl) {
                    $imageFound = true;
                    // Delete from storage if path is available
                    if (isset($image['path'])) {
                        $this->imageUploadService->deleteRecipeImage($image['path']);
                    }
                    break;
                }
            }

            if (!$imageFound) {
                return $this->error('Image not found', 404);
            }

            // Remove from recipe
            $recipe->removeImage($imageUrl);

            return $this->success(null, 'Image deleted successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to delete image', 500, $e->getMessage());
        }
    }

    /**
     * Update image metadata
     */
    public function updateImageMetadata(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $recipe = Recipe::where('id', $id)
                           ->where('created_by', $user->id)
                           ->firstOrFail();

            $validatedData = $request->validate([
                'image_url' => 'required|string|url',
                'alt_text' => 'sometimes|string|max:255',
                'caption' => 'sometimes|string|max:500',
                'type' => 'sometimes|string|in:main,additional,step,ingredient'
            ]);

            $imageUrl = $validatedData['image_url'];
            unset($validatedData['image_url']);

            $recipe->updateImageMetadata($imageUrl, $validatedData);

            return $this->success([
                'recipe' => $recipe->fresh(['creator:id,first_name,last_name'])
            ], 'Image metadata updated successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to update image metadata', 500, $e->getMessage());
        }
    }

    /**
     * Reorder recipe images
     */
    public function reorderImages(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $recipe = Recipe::where('id', $id)
                           ->where('created_by', $user->id)
                           ->firstOrFail();

            $validatedData = $request->validate([
                'ordered_urls' => 'required|array|min:1',
                'ordered_urls.*' => 'required|string|url'
            ]);

            $recipe->reorderImages($validatedData['ordered_urls']);

            return $this->success([
                'recipe' => $recipe->fresh(['creator:id,first_name,last_name'])
            ], 'Images reordered successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to reorder images', 500, $e->getMessage());
        }
    }

    /**
     * Get all images for a recipe
     */
    public function getImages(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $query = Recipe::query();

            if ($user) {
                $recipe = $query->forUser($user->id)->findOrFail($id);
            } else {
                $recipe = $query->public()->verified()->findOrFail($id);
            }

            $images = $recipe->getAllImages();

            return $this->success([
                'images' => $images,
                'main_image_url' => $recipe->main_image_url,
                'thumbnail_url' => $recipe->thumbnail_url
            ], 'Recipe images retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve recipe images', 500, $e->getMessage());
        }
    }
}