<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'ip_address',
        'user_agent',
        'device_fingerprint',
        'location_info',
        'is_mobile',
        'is_active',
        'last_activity',
        'expires_at',
        'invalidated_at',
        'invalidation_reason',
    ];

    protected $casts = [
        'location_info' => 'array',
        'is_mobile' => 'boolean',
        'is_active' => 'boolean',
        'last_activity' => 'datetime',
        'expires_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->is_active && !$this->isExpired();
    }

    public function getLocationString(): ?string
    {
        if (!$this->location_info) {
            return null;
        }

        $parts = [];
        if (isset($this->location_info['city'])) {
            $parts[] = $this->location_info['city'];
        }
        if (isset($this->location_info['country'])) {
            $parts[] = $this->location_info['country'];
        }

        return !empty($parts) ? implode(', ', $parts) : null;
    }

    public function getDeviceType(): string
    {
        return $this->is_mobile ? 'Mobile' : 'Desktop';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->where('expires_at', '>', Carbon::now());
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', Carbon::now());
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
