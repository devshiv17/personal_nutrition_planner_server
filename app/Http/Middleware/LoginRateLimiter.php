<?php

namespace App\Http\Middleware;

use App\Models\LoginAttempt;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoginRateLimiter
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only apply rate limiting to login attempts
        if (!$this->isLoginRequest($request)) {
            return $next($request);
        }

        $email = $request->input('email', '');
        $ipAddress = $request->ip();

        // Check email-based rate limiting
        $emailAttempts = LoginAttempt::getRecentFailedAttempts($email, 15);
        if ($emailAttempts >= 5) {
            return $this->rateLimitResponse('Too many failed attempts for this email. Please try again in 15 minutes.');
        }

        // Check IP-based rate limiting (more aggressive)
        $ipAttempts = LoginAttempt::getRecentFailedAttemptsByIp($ipAddress, 15);
        if ($ipAttempts >= 10) {
            return $this->rateLimitResponse('Too many failed attempts from this IP address. Please try again in 15 minutes.');
        }

        // Stricter limits for higher attempt counts
        if ($emailAttempts >= 3) {
            // Add delay for accounts with 3+ failed attempts
            sleep(2);
        }

        return $next($request);
    }

    /**
     * Check if this is a login request
     */
    private function isLoginRequest(Request $request): bool
    {
        return $request->is('api/v1/auth/login') && $request->isMethod('POST');
    }

    /**
     * Return rate limit response
     */
    private function rateLimitResponse(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'rate_limited' => true,
            'retry_after' => 900, // 15 minutes in seconds
        ], 429);
    }
}
