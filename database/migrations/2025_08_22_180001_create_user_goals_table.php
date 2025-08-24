<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('goal_type', [
                'weight_loss',
                'weight_gain', 
                'muscle_gain',
                'fat_loss',
                'maintain_weight',
                'improve_fitness',
                'increase_steps',
                'improve_sleep',
                'increase_water_intake',
                'reduce_body_fat',
                'increase_muscle_mass'
            ]);
            $table->decimal('target_value', 8, 2)->nullable();
            $table->string('target_unit', 20)->nullable();
            $table->decimal('current_value', 8, 2)->nullable();
            $table->date('start_date');
            $table->date('target_date');
            $table->enum('status', ['active', 'completed', 'paused', 'cancelled'])->default('active');
            $table->integer('priority')->default(1); // 1 = highest, 5 = lowest
            $table->text('description')->nullable();
            $table->json('milestones')->nullable(); // Array of milestone objects
            $table->decimal('progress_percentage', 5, 2)->default(0);
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'goal_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_goals');
    }
};