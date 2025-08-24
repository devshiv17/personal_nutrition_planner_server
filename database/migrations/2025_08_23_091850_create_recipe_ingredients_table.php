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
        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes')->onDelete('cascade');
            $table->foreignId('food_id')->nullable()->constrained('foods')->onDelete('cascade');
            $table->string('ingredient_name');
            $table->decimal('amount', 8, 2);
            $table->string('unit', 20);
            $table->decimal('amount_grams', 8, 2)->nullable();
            $table->text('preparation_notes')->nullable();
            $table->boolean('is_optional')->default(false);
            $table->string('group_name')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            // Indexes
            $table->index(['recipe_id', 'order']);
            $table->index(['food_id']);
            $table->index(['group_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredients');
    }
};
