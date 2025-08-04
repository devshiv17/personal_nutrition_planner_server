<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class JWTRefreshToken extends Model
{
    use HasFactory;

    protected $table = 'jwt_refresh_tokens';

    protected $fillable = [
        'user_id',
        'jti',
        'token_hash',
        'ip_address',
        'user_agent',
        'is_revoked',
        'expires_at',
        'last_used_at',
    ];

    protected $casts = [
        'is_revoked' => 'boolean',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    /**
     * Get the user that owns the refresh token
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the token is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the token is valid (not revoked and not expired)
     */
    public function isValid(): bool
    {
        return !$this->is_revoked && !$this->isExpired();
    }

    /**
     * Revoke the token
     */
    public function revoke(): bool
    {
        return $this->update(['is_revoked' => true]);
    }

    /**
     * Update last used timestamp
     */
    public function markAsUsed(): bool
    {
        return $this->update(['last_used_at' => Carbon::now()]);
    }

    /**
     * Clean up expired and revoked tokens
     */
    public static function cleanup(): int
    {
        return static::where(function ($query) {
            $query->where('expires_at', '<', Carbon::now())
                  ->orWhere('is_revoked', true);
        })
        ->where('created_at', '<', Carbon::now()->subDays(30)) // Keep for 30 days for audit
        ->delete();
    }

    /**
     * Get active tokens for a user
     */
    public static function getActiveTokensForUser(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('user_id', $userId)
            ->where('is_revoked', false)
            ->where('expires_at', '>', Carbon::now())
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Revoke all tokens for a user
     */
    public static function revokeAllForUser(int $userId): int
    {
        return static::where('user_id', $userId)
            ->where('is_revoked', false)
            ->update(['is_revoked' => true]);
    }

    /**
     * Find token by JTI
     */
    public static function findByJti(string $jti): ?static
    {
        return static::where('jti', $jti)
            ->where('is_revoked', false)
            ->where('expires_at', '>', Carbon::now())
            ->first();
    }
}
