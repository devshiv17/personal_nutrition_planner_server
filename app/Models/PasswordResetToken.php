<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PasswordResetToken extends Model
{
    use HasFactory;

    protected $table = 'secure_password_reset_tokens';

    protected $fillable = [
        'email',
        'token',
        'ip_address',
        'user_agent',
        'used',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'used' => 'boolean',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * Generate a secure password reset token
     */
    public static function generateSecureToken(): string
    {
        return hash('sha256', Str::random(60) . microtime() . random_bytes(32));
    }

    /**
     * Create a new password reset token
     */
    public static function createToken(
        string $email,
        string $ipAddress,
        ?string $userAgent = null,
        int $expirationMinutes = 60
    ): static {
        // Invalidate any existing tokens for this email
        static::where('email', $email)
            ->where('used', false)
            ->where('expires_at', '>', Carbon::now())
            ->update(['used' => true, 'used_at' => Carbon::now()]);

        $token = static::generateSecureToken();

        return static::create([
            'email' => $email,
            'token' => $token,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'expires_at' => Carbon::now()->addMinutes($expirationMinutes),
        ]);
    }

    /**
     * Verify and consume a password reset token
     */
    public static function verifyToken(string $token): ?static
    {
        $resetToken = static::where('token', $token)
            ->where('used', false)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if ($resetToken) {
            $resetToken->update([
                'used' => true,
                'used_at' => Carbon::now(),
            ]);
        }

        return $resetToken;
    }

    /**
     * Check if email has recent password reset request
     */
    public static function hasRecentRequest(string $email, int $minutes = 15): bool
    {
        return static::where('email', $email)
            ->where('created_at', '>=', Carbon::now()->subMinutes($minutes))
            ->exists();
    }

    /**
     * Get recent password reset attempts count for an email
     */
    public static function getRecentAttempts(string $email, int $minutes = 60): int
    {
        return static::where('email', $email)
            ->where('created_at', '>=', Carbon::now()->subMinutes($minutes))
            ->count();
    }

    /**
     * Get recent password reset attempts count for an IP
     */
    public static function getRecentAttemptsByIp(string $ipAddress, int $minutes = 60): int
    {
        return static::where('ip_address', $ipAddress)
            ->where('created_at', '>=', Carbon::now()->subMinutes($minutes))
            ->count();
    }

    /**
     * Clean up expired and used tokens
     */
    public static function cleanup(): int
    {
        return static::where(function ($query) {
            $query->where('expires_at', '<', Carbon::now())
                  ->orWhere('used', true);
        })
        ->where('created_at', '<', Carbon::now()->subHours(24)) // Keep for 24 hours for audit
        ->delete();
    }

    /**
     * Get the user associated with this token
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }
}
