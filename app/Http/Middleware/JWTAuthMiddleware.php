<?php

namespace App\Http\Middleware;

use App\Services\JWTService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class JWTAuthMiddleware
{
    protected JWTService $jwtService;

    public function __construct(JWTService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->jwtService->extractTokenFromHeader($request->header('Authorization'));

        if (!$token) {
            return $this->unauthorizedResponse('Authorization token not provided');
        }

        $user = $this->jwtService->getUserFromToken($token);

        if (!$user) {
            return $this->unauthorizedResponse('Invalid or expired token');
        }

        // Check if user is active
        if (!$user->is_active) {
            return $this->unauthorizedResponse('User account is deactivated');
        }

        // Set the authenticated user
        Auth::setUser($user);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        // Add token info to request for potential use in controllers
        $tokenInfo = $this->jwtService->getTokenInfo($token);
        $request->attributes->set('jwt_token_info', $tokenInfo);
        $request->attributes->set('jwt_token', $token);

        return $next($request);
    }

    /**
     * Return unauthorized response
     */
    private function unauthorizedResponse(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error' => 'Unauthorized',
        ], 401);
    }
}
