<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_dietary_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->json('dietary_restrictions')->nullable(); // vegan, vegetarian, keto, etc.
            $table->json('allergens')->nullable(); // nuts, dairy, gluten, etc.
            $table->json('cuisine_preferences')->nullable(); // italian, asian, mexican, etc.
            $table->json('disliked_ingredients')->nullable();
            $table->json('preferred_ingredients')->nullable();
            $table->integer('max_cooking_time')->nullable();
            $table->integer('preferred_difficulty_max')->nullable();
            $table->decimal('target_calories_per_day', 8, 2)->nullable();
            $table->json('macro_targets')->nullable(); // protein, carbs, fat percentages
            $table->boolean('prioritize_protein')->default(false);
            $table->boolean('low_sodium')->default(false);
            $table->boolean('low_sugar')->default(false);
            $table->boolean('high_fiber')->default(false);
            $table->json('meal_frequency')->nullable(); // 3 meals + 2 snacks, etc.
            $table->boolean('meal_prep_friendly')->default(false);
            $table->decimal('budget_per_meal', 8, 2)->nullable();
            $table->boolean('seasonal_preference')->default(false);
            $table->string('region')->nullable();
            $table->json('equipment_available')->nullable();
            $table->json('shopping_preferences')->nullable();
            $table->timestamps();

            // Ensure one preference record per user
            $table->unique(['user_id']);
            
            // Indexes
            $table->index(['user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_dietary_preferences');
    }
};
