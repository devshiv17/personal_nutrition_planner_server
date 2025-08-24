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
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('duration_days');
            $table->decimal('target_calories_per_day', 8, 2)->nullable();
            $table->decimal('target_protein_per_day', 8, 2)->nullable();
            $table->decimal('target_carbs_per_day', 8, 2)->nullable();
            $table->decimal('target_fat_per_day', 8, 2)->nullable();
            $table->json('dietary_preferences')->nullable();
            $table->json('allergen_restrictions')->nullable();
            $table->json('cuisine_preferences')->nullable();
            $table->decimal('budget_limit_per_day', 8, 2)->nullable();
            $table->integer('max_cooking_time_minutes')->nullable();
            $table->integer('preferred_difficulty_level')->nullable();
            $table->json('meal_types')->nullable(); // breakfast, lunch, dinner, snacks
            $table->boolean('include_meal_prep')->default(false);
            $table->boolean('prioritize_seasonal')->default(false);
            $table->boolean('avoid_repetition')->default(true);
            $table->integer('max_recipe_reuse_days')->default(7);
            $table->json('generation_preferences')->nullable();
            $table->json('feedback_data')->nullable();
            $table->enum('status', ['draft', 'generating', 'active', 'completed', 'archived'])->default('draft');
            $table->decimal('actual_calories_avg', 8, 2)->nullable();
            $table->decimal('actual_protein_avg', 8, 2)->nullable();
            $table->decimal('actual_carbs_avg', 8, 2)->nullable();
            $table->decimal('actual_fat_avg', 8, 2)->nullable();
            $table->decimal('adherence_score', 5, 2)->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['start_date', 'end_date']);
            $table->index(['status']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_plans');
    }
};
