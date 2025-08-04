<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ApiAuthenticate
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated via Sanctum
        if (!auth('sanctum')->check()) {
            return $this->unauthenticatedResponse();
        }

        $user = auth('sanctum')->user();

        // Check if user account is active
        if (!$user->is_active) {
            return $this->accountDeactivatedResponse();
        }

        // Update last login timestamp
        $user->update(['last_login_at' => now()]);

        return $next($request);
    }

    /**
     * Return unauthenticated response
     */
    private function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated. Please provide a valid API token.',
            'errors' => [
                'auth' => ['Authentication required']
            ]
        ], 401);
    }

    /**
     * Return account deactivated response
     */
    private function accountDeactivatedResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Account has been deactivated. Please contact support.',
            'errors' => [
                'account' => ['Account deactivated']
            ]
        ], 403);
    }
}