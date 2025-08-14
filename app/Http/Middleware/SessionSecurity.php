<?php

namespace App\Http\Middleware;

use App\Services\SessionManagementService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SessionSecurity
{
    public function __construct(
        private SessionManagementService $sessionService
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip for non-authenticated requests
        if (!Auth::check()) {
            return $next($request);
        }

        $sessionId = session()->getId();
        $user = Auth::user();

        // Validate session security
        $validation = $this->sessionService->validateSessionSecurity($sessionId, $request);
        
        if (!$validation['valid']) {
            Log::warning('Session security validation failed', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'reason' => $validation['reason'],
                'ip_address' => $request->ip(),
            ]);

            // Force logout for invalid sessions
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();

            return response()->json([
                'message' => 'Session expired or invalid. Please log in again.',
                'error' => 'session_invalid',
            ], 401);
        }

        // Check for suspicious activity
        $suspiciousCheck = $this->sessionService->detectSuspiciousActivity($sessionId, $request);
        
        if ($suspiciousCheck['suspicious']) {
            Log::alert('Suspicious session activity detected', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'flags' => $suspiciousCheck['flags'],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // For now, just log - in production you might want to:
            // - Force re-authentication
            // - Send security alert email
            // - Require additional verification
            
            // Add security warning header
            $response = $next($request);
            $response->headers->set('X-Security-Warning', 'suspicious-activity-detected');
            
            return $response;
        }

        // Check session rotation requirement
        if ($this->shouldRotateSession($validation['session'])) {
            $newSessionId = $this->sessionService->rotateSessionId($sessionId, $request);
            
            if ($newSessionId) {
                // Update Laravel's session ID
                session()->setId($newSessionId);
                
                Log::info('Session ID rotated for security', [
                    'user_id' => $user->id,
                    'old_session_id' => $sessionId,
                    'new_session_id' => $newSessionId,
                ]);
            }
        }

        // Update session activity
        $this->sessionService->updateSessionActivity($sessionId, $request);

        // Enforce concurrent session limits
        $this->sessionService->enforceConcurrentSessionLimit($user);

        return $next($request);
    }

    /**
     * Determine if session should be rotated
     */
    private function shouldRotateSession($session): bool
    {
        if (!$session) {
            return false;
        }

        $rotationThreshold = Config::get('security.session.rotation_threshold', 900); // 15 minutes
        $lastRotation = $session->updated_at ?? $session->created_at;
        
        return $lastRotation->diffInSeconds(now()) > $rotationThreshold;
    }
}