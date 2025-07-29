<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password_hash',
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'height_cm',
        'current_weight_kg',
        'target_weight_kg',
        'activity_level',
        'primary_goal',
        'target_timeline_weeks',
        'dietary_preference',
        'timezone',
        'locale',
        'email_notifications',
        'push_notifications',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_hash' => 'hashed',
            'date_of_birth' => 'date',
            'height_cm' => 'decimal:2',
            'current_weight_kg' => 'decimal:2',
            'target_weight_kg' => 'decimal:2',
            'bmr_calories' => 'decimal:2',
            'tdee_calories' => 'decimal:2',
            'is_active' => 'boolean',
            'email_notifications' => 'boolean',
            'push_notifications' => 'boolean',
            'profile_completed' => 'boolean',
            'last_login_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
