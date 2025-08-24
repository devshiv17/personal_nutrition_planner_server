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
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->json('instructions');
            $table->integer('prep_time_minutes');
            $table->integer('cook_time_minutes');
            $table->integer('total_time_minutes');
            $table->integer('servings');
            $table->integer('difficulty_level')->default(1);
            $table->string('cuisine_type')->nullable();
            $table->string('meal_category');
            $table->json('dietary_preferences')->nullable();
            $table->json('allergens')->nullable();
            $table->string('image_url')->nullable();
            $table->json('images')->nullable();
            $table->string('video_url')->nullable();
            $table->string('source_url')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->boolean('is_public')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->integer('total_ratings')->default(0);
            $table->integer('total_reviews')->default(0);
            $table->decimal('calories_per_serving', 8, 2)->nullable();
            $table->decimal('protein_per_serving', 8, 2)->nullable();
            $table->decimal('carbs_per_serving', 8, 2)->nullable();
            $table->decimal('fat_per_serving', 8, 2)->nullable();
            $table->decimal('fiber_per_serving', 8, 2)->nullable();
            $table->decimal('sugar_per_serving', 8, 2)->nullable();
            $table->decimal('sodium_per_serving', 8, 2)->nullable();
            $table->json('tags')->nullable();
            $table->json('equipment_needed')->nullable();
            $table->text('storage_instructions')->nullable();
            $table->text('nutritional_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['meal_category']);
            $table->index(['cuisine_type']);
            $table->index(['difficulty_level']);
            $table->index(['is_public', 'is_verified']);
            $table->index(['average_rating']);
            $table->index(['created_by']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
