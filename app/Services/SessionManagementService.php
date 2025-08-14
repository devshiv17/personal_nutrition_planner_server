<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class SessionManagementService
{
    private const MAX_CONCURRENT_SESSIONS = 5;
    private const SESSION_ROTATION_THRESHOLD = 900; // 15 minutes
    private const MAX_SESSION_LIFETIME = 43200; // 12 hours
    private const SUSPICIOUS_ACTIVITY_THRESHOLD = 10;

    public function __construct()
    {
        //
    }

    /**
     * Create a new session record
     */
    public function createSession(User $user, Request $request, string $sessionId = null): UserSession
    {
        $sessionId = $sessionId ?: session()->getId();
        
        $session = UserSession::create([
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_fingerprint' => $this->generateDeviceFingerprint($request),
            'location_info' => $this->getLocationInfo($request->ip()),
            'is_mobile' => $this->isMobileDevice($request->userAgent()),
            'last_activity' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(Config::get('session.lifetime', 120)),
        ]);

        // Update user's last activity
        $user->update([
            'last_activity_at' => Carbon::now(),
            'last_login_ip' => $request->ip(),
        ]);

        Log::info('New session created', [
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $session;
    }

    /**
     * Update session activity
     */
    public function updateSessionActivity(string $sessionId, Request $request): bool
    {
        try {
            $updated = UserSession::where('session_id', $sessionId)
                ->where('is_active', true)
                ->update([
                    'last_activity' => Carbon::now(),
                    'expires_at' => Carbon::now()->addMinutes(Config::get('session.lifetime', 120)),
                ]);

            return $updated > 0;
        } catch (\Exception $e) {
            Log::error('Failed to update session activity', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Invalidate a specific session
     */
    public function invalidateSession(string $sessionId, string $reason = 'manual'): bool
    {
        try {
            $session = UserSession::where('session_id', $sessionId)->first();
            
            if ($session) {
                $session->update([
                    'is_active' => false,
                    'invalidated_at' => Carbon::now(),
                    'invalidation_reason' => $reason,
                ]);

                // Clear from cache if exists
                Cache::forget("session:{$sessionId}");

                Log::info('Session invalidated', [
                    'session_id' => $sessionId,
                    'user_id' => $session->user_id,
                    'reason' => $reason,
                ]);

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to invalidate session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Invalidate all sessions for a user except current
     */
    public function invalidateOtherSessions(User $user, string $currentSessionId = null): int
    {
        try {
            $query = UserSession::where('user_id', $user->id)
                ->where('is_active', true);

            if ($currentSessionId) {
                $query->where('session_id', '!=', $currentSessionId);
            }

            $count = $query->update([
                'is_active' => false,
                'invalidated_at' => Carbon::now(),
                'invalidation_reason' => 'logout_all_devices',
            ]);

            Log::info('Multiple sessions invalidated', [
                'user_id' => $user->id,
                'sessions_invalidated' => $count,
                'current_session' => $currentSessionId,
            ]);

            return $count;
        } catch (\Exception $e) {
            Log::error('Failed to invalidate other sessions', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get active sessions for a user
     */
    public function getActiveSessions(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return UserSession::where('user_id', $user->id)
            ->where('is_active', true)
            ->where('expires_at', '>', Carbon::now())
            ->orderBy('last_activity', 'desc')
            ->get();
    }

    /**
     * Validate session security
     */
    public function validateSessionSecurity(string $sessionId, Request $request): array
    {
        $session = UserSession::where('session_id', $sessionId)
            ->where('is_active', true)
            ->first();

        if (!$session) {
            return [
                'valid' => false,
                'reason' => 'session_not_found',
                'action' => 'logout',
            ];
        }

        // Check if session is expired
        if ($session->expires_at->isPast()) {
            $this->invalidateSession($sessionId, 'expired');
            return [
                'valid' => false,
                'reason' => 'session_expired',
                'action' => 'logout',
            ];
        }

        // Check IP address changes (optional - might be too strict)
        $currentIp = $request->ip();
        if (Config::get('session.strict_ip_check', false) && $session->ip_address !== $currentIp) {
            Log::warning('Session IP address mismatch', [
                'session_id' => $sessionId,
                'original_ip' => $session->ip_address,
                'current_ip' => $currentIp,
            ]);
            
            // Don't automatically invalidate - just log for now
        }

        // Check device fingerprint
        $currentFingerprint = $this->generateDeviceFingerprint($request);
        if ($session->device_fingerprint !== $currentFingerprint) {
            Log::warning('Session device fingerprint mismatch', [
                'session_id' => $sessionId,
                'original_fingerprint' => $session->device_fingerprint,
                'current_fingerprint' => $currentFingerprint,
            ]);
        }

        return [
            'valid' => true,
            'session' => $session,
        ];
    }

    /**
     * Cleanup expired sessions
     */
    public function cleanupExpiredSessions(): int
    {
        try {
            $count = UserSession::where('expires_at', '<', Carbon::now())
                ->orWhere('last_activity', '<', Carbon::now()->subDays(30))
                ->update([
                    'is_active' => false,
                    'invalidated_at' => Carbon::now(),
                    'invalidation_reason' => 'cleanup_expired',
                ]);

            Log::info('Expired sessions cleaned up', [
                'sessions_cleaned' => $count,
            ]);

            return $count;
        } catch (\Exception $e) {
            Log::error('Failed to cleanup expired sessions', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Generate device fingerprint
     */
    private function generateDeviceFingerprint(Request $request): string
    {
        $components = [
            $request->userAgent(),
            $request->header('Accept-Language', ''),
            $request->header('Accept-Encoding', ''),
            $request->header('Accept', ''),
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Get location info from IP (placeholder - integrate with IP geolocation service)
     */
    private function getLocationInfo(string $ipAddress): ?array
    {
        // In production, integrate with services like MaxMind, IPinfo, etc.
        // For now, return null or basic info
        if ($ipAddress === '127.0.0.1' || $ipAddress === '::1') {
            return [
                'country' => 'Local',
                'city' => 'Localhost',
                'timezone' => config('app.timezone'),
            ];
        }

        return null;
    }

    /**
     * Detect if request is from mobile device
     */
    private function isMobileDevice(?string $userAgent): bool
    {
        if (!$userAgent) {
            return false;
        }

        $mobileKeywords = [
            'Mobile', 'Android', 'iPhone', 'iPad', 'iPod', 
            'BlackBerry', 'Windows Phone', 'Opera Mini'
        ];

        foreach ($mobileKeywords as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get session statistics for user
     */
    public function getSessionStats(User $user): array
    {
        $activeSessions = $this->getActiveSessions($user);
        $totalSessions = UserSession::where('user_id', $user->id)->count();
        
        return [
            'active_sessions' => $activeSessions->count(),
            'total_sessions' => $totalSessions,
            'current_devices' => $activeSessions->unique('device_fingerprint')->count(),
            'unique_ips' => $activeSessions->unique('ip_address')->count(),
            'mobile_sessions' => $activeSessions->where('is_mobile', true)->count(),
            'desktop_sessions' => $activeSessions->where('is_mobile', false)->count(),
        ];
    }

    /**
     * Rotate session ID for security
     */
    public function rotateSessionId(string $oldSessionId, Request $request): ?string
    {
        try {
            $session = UserSession::where('session_id', $oldSessionId)
                ->where('is_active', true)
                ->first();

            if (!$session) {
                return null;
            }

            // Generate new session ID
            $newSessionId = Str::random(40);
            
            // Update session record
            $session->update([
                'session_id' => $newSessionId,
                'last_activity' => Carbon::now(),
                'rotation_count' => ($session->rotation_count ?? 0) + 1,
            ]);

            // Clear old session from cache
            Cache::forget("session:{$oldSessionId}");

            Log::info('Session ID rotated', [
                'old_session_id' => $oldSessionId,
                'new_session_id' => $newSessionId,
                'user_id' => $session->user_id,
            ]);

            return $newSessionId;
        } catch (\Exception $e) {
            Log::error('Failed to rotate session ID', [
                'session_id' => $oldSessionId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check for concurrent session limits
     */
    public function enforceConcurrentSessionLimit(User $user): bool
    {
        $activeSessions = $this->getActiveSessions($user);
        
        if ($activeSessions->count() > self::MAX_CONCURRENT_SESSIONS) {
            // Remove oldest sessions
            $sessionsToRemove = $activeSessions
                ->sortBy('last_activity')
                ->take($activeSessions->count() - self::MAX_CONCURRENT_SESSIONS);

            foreach ($sessionsToRemove as $session) {
                $this->invalidateSession($session->session_id, 'concurrent_limit_exceeded');
            }

            Log::warning('Concurrent session limit enforced', [
                'user_id' => $user->id,
                'sessions_removed' => $sessionsToRemove->count(),
            ]);

            return true;
        }

        return false;
    }

    /**
     * Detect suspicious session activity
     */
    public function detectSuspiciousActivity(string $sessionId, Request $request): array
    {
        $session = UserSession::where('session_id', $sessionId)->first();
        $suspiciousFlags = [];

        if (!$session) {
            return ['suspicious' => true, 'flags' => ['session_not_found']];
        }

        // Check for rapid IP changes
        $recentSessions = UserSession::where('user_id', $session->user_id)
            ->where('created_at', '>', Carbon::now()->subHour())
            ->distinct('ip_address')
            ->count();

        if ($recentSessions > 3) {
            $suspiciousFlags[] = 'rapid_ip_changes';
        }

        // Check for unusual activity patterns
        $activityKey = "suspicious_activity:{$session->user_id}";
        $activityCount = Cache::get($activityKey, 0);
        
        if ($activityCount > self::SUSPICIOUS_ACTIVITY_THRESHOLD) {
            $suspiciousFlags[] = 'high_activity_rate';
        }

        // Increment activity counter
        Cache::put($activityKey, $activityCount + 1, 300); // 5 minutes

        // Check for session hijacking indicators
        if ($this->checkSessionHijackingIndicators($session, $request)) {
            $suspiciousFlags[] = 'potential_hijacking';
        }

        $isSuspicious = !empty($suspiciousFlags);

        if ($isSuspicious) {
            Log::warning('Suspicious session activity detected', [
                'session_id' => $sessionId,
                'user_id' => $session->user_id,
                'flags' => $suspiciousFlags,
                'ip_address' => $request->ip(),
            ]);
        }

        return [
            'suspicious' => $isSuspicious,
            'flags' => $suspiciousFlags,
        ];
    }

    /**
     * Check for session hijacking indicators
     */
    private function checkSessionHijackingIndicators(UserSession $session, Request $request): bool
    {
        $indicators = 0;

        // Check User-Agent consistency
        if ($session->user_agent !== $request->userAgent()) {
            $indicators++;
        }

        // Check timezone consistency (if available in headers)
        $currentTimezone = $request->header('X-Timezone');
        if ($currentTimezone && isset($session->location_info['timezone'])) {
            if ($session->location_info['timezone'] !== $currentTimezone) {
                $indicators++;
            }
        }

        // Check for impossible travel (basic check)
        if ($this->isImpossibleTravel($session, $request)) {
            $indicators += 2;
        }

        return $indicators >= 2;
    }

    /**
     * Basic impossible travel detection
     */
    private function isImpossibleTravel(UserSession $session, Request $request): bool
    {
        // Skip if locations are not available
        if (!$session->location_info || $request->ip() === $session->ip_address) {
            return false;
        }

        // This is a simplified check - in production, you'd use proper geolocation
        // and calculate actual distances and time between requests
        $timeDiff = $session->last_activity->diffInMinutes(Carbon::now());
        
        // If less than 30 minutes and IP changed significantly, flag as suspicious
        return $timeDiff < 30 && $this->areIPsGeographicallyDistant($session->ip_address, $request->ip());
    }

    /**
     * Check if IPs are geographically distant (simplified)
     */
    private function areIPsGeographicallyDistant(string $ip1, string $ip2): bool
    {
        // This is a placeholder - implement with proper IP geolocation service
        // For now, just check if they're completely different networks
        $network1 = implode('.', array_slice(explode('.', $ip1), 0, 2));
        $network2 = implode('.', array_slice(explode('.', $ip2), 0, 2));
        
        return $network1 !== $network2;
    }

    /**
     * Generate secure session token
     */
    public function generateSecureToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Validate session token
     */
    public function validateSecureToken(string $token, string $expectedHash): bool
    {
        return hash_equals($expectedHash, hash('sha256', $token));
    }

    /**
     * Lock session to prevent concurrent modifications
     */
    public function lockSession(string $sessionId, int $timeout = 10): bool
    {
        $lockKey = "session_lock:{$sessionId}";
        return Cache::lock($lockKey, $timeout)->get();
    }

    /**
     * Release session lock
     */
    public function unlockSession(string $sessionId): void
    {
        $lockKey = "session_lock:{$sessionId}";
        Cache::lock($lockKey)->forceRelease();
    }

    /**
     * Get session security report for admin
     */
    public function getSecurityReport(int $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days);

        return [
            'total_sessions' => UserSession::where('created_at', '>=', $startDate)->count(),
            'active_sessions' => UserSession::where('is_active', true)->count(),
            'invalidated_sessions' => UserSession::where('invalidated_at', '>=', $startDate)->count(),
            'expired_sessions' => UserSession::where('expires_at', '<', Carbon::now())
                ->where('expires_at', '>=', $startDate)->count(),
            'suspicious_activities' => $this->getSuspiciousActivitiesCount($startDate),
            'unique_users' => UserSession::where('created_at', '>=', $startDate)
                ->distinct('user_id')->count(),
            'unique_ips' => UserSession::where('created_at', '>=', $startDate)
                ->distinct('ip_address')->count(),
            'mobile_vs_desktop' => [
                'mobile' => UserSession::where('created_at', '>=', $startDate)
                    ->where('is_mobile', true)->count(),
                'desktop' => UserSession::where('created_at', '>=', $startDate)
                    ->where('is_mobile', false)->count(),
            ],
        ];
    }

    /**
     * Get suspicious activities count
     */
    private function getSuspiciousActivitiesCount(Carbon $startDate): int
    {
        // Count sessions with multiple invalidation reasons that might indicate suspicious activity
        return UserSession::where('invalidated_at', '>=', $startDate)
            ->whereIn('invalidation_reason', ['security_violation', 'potential_hijacking', 'suspicious_activity'])
            ->count();
    }
}