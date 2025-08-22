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
        Schema::table('users', function (Blueprint $table) {
            // Split name into first_name and last_name
            $table->string('first_name')->nullable()->after('id');
            $table->string('last_name')->nullable()->after('first_name');
            
            // Rename password to password_hash to match controller
            $table->renameColumn('password', 'password_hash');
            
            // Add profile fields
            $table->date('date_of_birth')->nullable()->after('email_verified_at');
            $table->enum('gender', ['male', 'female', 'other', 'prefer_not_to_say'])->nullable()->after('date_of_birth');
            $table->decimal('height_cm', 5, 2)->nullable()->after('gender');
            $table->decimal('current_weight_kg', 5, 2)->nullable()->after('height_cm');
            $table->enum('activity_level', ['sedentary', 'lightly_active', 'moderately_active', 'very_active'])->default('sedentary')->after('current_weight_kg');
            $table->enum('primary_goal', ['weight_loss', 'weight_gain', 'maintenance', 'muscle_gain', 'health_management'])->default('maintenance')->after('activity_level');
            $table->enum('dietary_preference', ['keto', 'mediterranean', 'vegan', 'diabetic_friendly'])->nullable()->after('primary_goal');
            
            // Notification preferences
            $table->boolean('email_notifications')->default(true)->after('dietary_preference');
            $table->boolean('push_notifications')->default(false)->after('email_notifications');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 
                'last_name',
                'date_of_birth',
                'gender',
                'height_cm',
                'current_weight_kg',
                'activity_level',
                'primary_goal',
                'dietary_preference',
                'email_notifications',
                'push_notifications'
            ]);
            
            // Rename back
            $table->renameColumn('password_hash', 'password');
        });
    }
};
