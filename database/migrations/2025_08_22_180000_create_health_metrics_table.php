<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('metric_type', [
                'weight', 
                'height', 
                'body_fat', 
                'muscle_mass', 
                'bmi',
                'waist_circumference',
                'hip_circumference',
                'chest_circumference',
                'arm_circumference',
                'thigh_circumference',
                'neck_circumference',
                'blood_pressure_systolic',
                'blood_pressure_diastolic',
                'heart_rate',
                'steps',
                'sleep_hours',
                'water_intake'
            ]);
            $table->decimal('value', 8, 2);
            $table->string('unit', 20); // kg, lbs, cm, in, %, bpm, etc.
            $table->date('recorded_date');
            $table->time('recorded_time')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable(); // For storing additional data like measurement conditions
            $table->boolean('is_goal')->default(false); // If this is a goal metric vs actual measurement
            $table->timestamps();
            
            // Indexes for efficient querying
            $table->index(['user_id', 'metric_type']);
            $table->index(['user_id', 'recorded_date']);
            $table->index(['user_id', 'metric_type', 'recorded_date']);
            $table->unique(['user_id', 'metric_type', 'recorded_date', 'is_goal'], 'unique_daily_metric');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_metrics');
    }
};