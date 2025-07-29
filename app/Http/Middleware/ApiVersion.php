<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ApiVersion
{
    /**
     * Supported API versions
     */
    protected array $supportedVersions = [
        'v1' => '1.0',
    ];

    /**
     * Default API version
     */
    protected string $defaultVersion = 'v1';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $requiredVersion = null): Response
    {
        $requestedVersion = $this->getRequestedVersion($request);

        // Validate version format
        if (!$this->isValidVersion($requestedVersion)) {
            return $this->invalidVersionResponse($requestedVersion);
        }

        // Check if specific version is required for this route
        if ($requiredVersion && $requestedVersion !== $requiredVersion) {
            return $this->versionMismatchResponse($requestedVersion, $requiredVersion);
        }

        // Add version info to request
        $request->attributes->set('api_version', $requestedVersion);
        $request->attributes->set('api_version_number', $this->supportedVersions[$requestedVersion]);

        $response = $next($request);

        // Add version headers to response
        return $this->addVersionHeaders($response, $requestedVersion);
    }

    /**
     * Get requested API version from request
     */
    protected function getRequestedVersion(Request $request): string
    {
        // Check Accept header first (e.g., application/vnd.api+json;version=1)
        $acceptHeader = $request->header('Accept');
        if ($acceptHeader && preg_match('/version=(\w+)/', $acceptHeader, $matches)) {
            return 'v' . $matches[1];
        }

        // Check X-API-Version header
        $versionHeader = $request->header('X-API-Version');
        if ($versionHeader) {
            return str_starts_with($versionHeader, 'v') ? $versionHeader : 'v' . $versionHeader;
        }

        // Check URL path (e.g., /api/v1/users)
        $path = $request->path();
        if (preg_match('/^api\/(v\d+)\//', $path, $matches)) {
            return $matches[1];
        }

        // Return default version
        return $this->defaultVersion;
    }

    /**
     * Check if version is valid
     */
    protected function isValidVersion(string $version): bool
    {
        return isset($this->supportedVersions[$version]);
    }

    /**
     * Return invalid version response
     */
    protected function invalidVersionResponse(string $requestedVersion): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Unsupported API version requested.',
            'errors' => [
                'version' => [
                    'requested' => $requestedVersion,
                    'supported' => array_keys($this->supportedVersions),
                ]
            ]
        ], 400);
    }

    /**
     * Return version mismatch response
     */
    protected function versionMismatchResponse(string $requested, string $required): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This endpoint requires a specific API version.',
            'errors' => [
                'version' => [
                    'requested' => $requested,
                    'required' => $required,
                ]
            ]
        ], 400);
    }

    /**
     * Add version headers to response
     */
    protected function addVersionHeaders(Response $response, string $version): Response
    {
        $response->headers->set('X-API-Version', $version);
        $response->headers->set('X-API-Version-Number', $this->supportedVersions[$version]);
        
        return $response;
    }

    /**
     * Get supported versions
     */
    public function getSupportedVersions(): array
    {
        return $this->supportedVersions;
    }
}