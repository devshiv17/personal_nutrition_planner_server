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
        Schema::create('foods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('brand_name')->nullable();
            $table->string('barcode')->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->json('serving_sizes')->nullable();
            $table->decimal('calories_per_100g', 8, 2);
            $table->decimal('protein_per_100g', 8, 2);
            $table->decimal('carbohydrates_per_100g', 8, 2);
            $table->decimal('fat_per_100g', 8, 2);
            $table->decimal('fiber_per_100g', 8, 2)->nullable();
            $table->decimal('sugar_per_100g', 8, 2)->nullable();
            $table->decimal('sodium_per_100g', 8, 2)->nullable();
            $table->json('vitamins')->nullable();
            $table->json('minerals')->nullable();
            $table->string('data_source')->default('manual');
            $table->string('external_id')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            // Indexes
            $table->index(['name']);
            $table->index(['category']);
            $table->index(['brand_name']);
            $table->index(['data_source']);
            $table->index(['is_verified']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('foods');
    }
};
