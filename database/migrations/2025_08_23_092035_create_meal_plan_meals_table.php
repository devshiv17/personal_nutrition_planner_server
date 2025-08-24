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
        Schema::create('meal_plan_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_id')->constrained('meal_plans')->onDelete('cascade');
            $table->foreignId('recipe_id')->nullable()->constrained('recipes')->onDelete('cascade');
            $table->date('date');
            $table->enum('meal_type', ['breakfast', 'lunch', 'dinner', 'snack_morning', 'snack_afternoon', 'snack_evening']);
            $table->string('custom_meal_name')->nullable();
            $table->text('custom_meal_description')->nullable();
            $table->json('custom_ingredients')->nullable();
            $table->decimal('servings', 5, 2)->default(1);
            $table->decimal('planned_calories', 8, 2)->nullable();
            $table->decimal('planned_protein', 8, 2)->nullable();
            $table->decimal('planned_carbs', 8, 2)->nullable();
            $table->decimal('planned_fat', 8, 2)->nullable();
            $table->text('preparation_notes')->nullable();
            $table->boolean('is_meal_prep')->default(false);
            $table->date('prep_date')->nullable();
            $table->integer('estimated_prep_time')->nullable();
            $table->enum('status', ['planned', 'prepped', 'completed', 'skipped', 'substituted'])->default('planned');
            $table->text('completion_notes')->nullable();
            $table->integer('user_rating')->nullable();
            $table->text('user_feedback')->nullable();
            $table->json('substitution_reason')->nullable();
            $table->foreignId('substituted_with_recipe_id')->nullable()->constrained('recipes')->onDelete('set null');
            $table->timestamps();

            // Indexes
            $table->index(['meal_plan_id', 'date', 'meal_type']);
            $table->index(['recipe_id']);
            $table->index(['date', 'meal_type']);
            $table->index(['status']);
            $table->index(['is_meal_prep', 'prep_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_plan_meals');
    }
};
