<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class LoginAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'ip_address',
        'user_agent',
        'successful',
        'failure_reason',
        'request_data',
        'attempted_at',
    ];

    protected $casts = [
        'successful' => 'boolean',
        'request_data' => 'array',
        'attempted_at' => 'datetime',
    ];

    /**
     * Get recent failed attempts for an email
     */
    public static function getRecentFailedAttempts(string $email, int $minutes = 15): int
    {
        return static::where('email', $email)
            ->where('successful', false)
            ->where('attempted_at', '>=', Carbon::now()->subMinutes($minutes))
            ->count();
    }

    /**
     * Get recent failed attempts for an IP address
     */
    public static function getRecentFailedAttemptsByIp(string $ipAddress, int $minutes = 15): int
    {
        return static::where('ip_address', $ipAddress)
            ->where('successful', false)
            ->where('attempted_at', '>=', Carbon::now()->subMinutes($minutes))
            ->count();
    }

    /**
     * Log a login attempt
     */
    public static function logAttempt(
        string $email,
        string $ipAddress,
        ?string $userAgent,
        bool $successful,
        ?string $failureReason = null,
        ?array $requestData = null
    ): static {
        return static::create([
            'email' => $email,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'successful' => $successful,
            'failure_reason' => $failureReason,
            'request_data' => $requestData,
            'attempted_at' => Carbon::now(),
        ]);
    }

    /**
     * Clean up old login attempts (older than 30 days)
     */
    public static function cleanup(): int
    {
        return static::where('attempted_at', '<', Carbon::now()->subDays(30))->delete();
    }
}
