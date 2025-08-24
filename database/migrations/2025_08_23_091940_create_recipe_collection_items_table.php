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
        Schema::create('recipe_collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_collection_id')->constrained('recipe_collections')->onDelete('cascade');
            $table->foreignId('recipe_id')->constrained('recipes')->onDelete('cascade');
            $table->integer('order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Ensure unique recipe per collection
            $table->unique(['recipe_collection_id', 'recipe_id']);
            
            // Indexes
            $table->index(['recipe_collection_id', 'order']);
            $table->index(['recipe_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_collection_items');
    }
};
