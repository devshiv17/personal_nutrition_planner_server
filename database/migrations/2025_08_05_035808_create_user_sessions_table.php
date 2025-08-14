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
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('session_id')->unique();
            $table->ipAddress('ip_address');
            $table->text('user_agent')->nullable();
            $table->string('device_fingerprint')->nullable();
            $table->json('location_info')->nullable();
            $table->boolean('is_mobile')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_activity');
            $table->timestamp('expires_at');
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['session_id', 'is_active']);
            $table->index('expires_at');
            $table->index('last_activity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
