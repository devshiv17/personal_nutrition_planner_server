<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiLogger
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        
        // Log incoming request
        $this->logRequest($request);

        $response = $next($request);

        // Log response
        $this->logResponse($request, $response, $startTime);

        return $response;
    }

    /**
     * Log incoming request
     */
    protected function logRequest(Request $request): void
    {
        $logData = [
            'method' => $request->getMethod(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'user_id' => auth('sanctum')->id(),
            'headers' => $this->filterHeaders($request->headers->all()),
        ];

        // Only log request body for non-GET requests and exclude sensitive data
        if (!$request->isMethod('GET')) {
            $logData['body'] = $this->filterSensitiveData($request->all());
        }

        Log::info('API Request', $logData);
    }

    /**
     * Log response
     */
    protected function logResponse(Request $request, Response $response, float $startTime): void
    {
        $executionTime = round((microtime(true) - $startTime) * 1000, 2); // in milliseconds

        $logData = [
            'method' => $request->getMethod(),
            'url' => $request->fullUrl(),
            'status_code' => $response->getStatusCode(),
            'execution_time_ms' => $executionTime,
            'user_id' => auth('sanctum')->id(),
            'memory_usage_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ];

        // Log response body for error responses (4xx, 5xx)
        if ($response->getStatusCode() >= 400) {
            $logData['response_body'] = $response->getContent();
        }

        $logLevel = $this->getLogLevel($response->getStatusCode());
        Log::log($logLevel, 'API Response', $logData);

        // Log slow requests
        if ($executionTime > 1000) { // > 1 second
            Log::warning('Slow API Request', [
                'method' => $request->getMethod(),
                'url' => $request->fullUrl(),
                'execution_time_ms' => $executionTime,
                'user_id' => auth('sanctum')->id(),
            ]);
        }
    }

    /**
     * Filter sensitive headers
     */
    protected function filterHeaders(array $headers): array
    {
        $sensitiveHeaders = [
            'authorization',
            'cookie',
            'x-api-key',
            'x-auth-token',
        ];

        $filtered = [];
        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitiveHeaders)) {
                $filtered[$key] = '***FILTERED***';
            } else {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Filter sensitive data from request body
     */
    protected function filterSensitiveData(array $data): array
    {
        $sensitiveFields = [
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'token',
            'api_key',
            'secret',
        ];

        $filtered = $data;
        foreach ($sensitiveFields as $field) {
            if (isset($filtered[$field])) {
                $filtered[$field] = '***FILTERED***';
            }
        }

        return $filtered;
    }

    /**
     * Get appropriate log level based on status code
     */
    protected function getLogLevel(int $statusCode): string
    {
        if ($statusCode >= 500) {
            return 'error';
        }

        if ($statusCode >= 400) {
            return 'warning';
        }

        return 'info';
    }
}