<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiCors
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest();
        }

        $response = $next($request);

        return $this->addCorsHeaders($response, $request);
    }

    /**
     * Handle preflight request
     */
    protected function handlePreflightRequest(): Response
    {
        return response('', 200)
            ->header('Access-Control-Allow-Origin', $this->getAllowedOrigin())
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Origin, Content-Type, Accept, Authorization, X-Requested-With, X-RateLimit-Limit, X-RateLimit-Remaining')
            ->header('Access-Control-Max-Age', '86400'); // 24 hours
    }

    /**
     * Add CORS headers to response
     */
    protected function addCorsHeaders(Response $response, Request $request): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $this->getAllowedOrigin());
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Expose-Headers', 'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset');

        return $response;
    }

    /**
     * Get allowed origin based on environment
     */
    protected function getAllowedOrigin(): string
    {
        // In production, you should specify exact domains
        if (app()->environment('production')) {
            $allowedOrigins = [
                'https://nutrition-planner.com',
                'https://www.nutrition-planner.com',
                // Add your production domains here
            ];

            $origin = request()->header('Origin');
            
            if (in_array($origin, $allowedOrigins)) {
                return $origin;
            }

            return 'https://nutrition-planner.com'; // Default production origin
        }

        // In development, allow localhost origins
        $origin = request()->header('Origin');
        $allowedLocalOrigins = [
            'http://localhost:3000',
            'http://localhost:3001', 
            'http://localhost:8080',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:3001',
            'http://127.0.0.1:8080',
        ];
        
        if ($origin && in_array($origin, $allowedLocalOrigins)) {
            return $origin;
        }
        
        // Fallback to allow all for development
        return '*';
    }
}