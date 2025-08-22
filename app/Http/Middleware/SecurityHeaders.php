<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Security headers configuration
     */
    private array $securityHeaders = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'X-Download-Options' => 'noopen',
        'Cross-Origin-Embedder-Policy' => 'require-corp',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Apply all security headers
        $this->applySecurityHeaders($response, $request);

        // Apply HSTS for HTTPS connections
        $this->applyHSTS($response, $request);

        // Apply Content Security Policy
        $this->applyCSP($response, $request);

        // Apply Permissions Policy
        $this->applyPermissionsPolicy($response);

        // Remove identifying server headers
        $this->removeServerHeaders($response);

        // Apply cache control for sensitive routes
        $this->applyCacheControl($response, $request);

        return $response;
    }

    /**
     * Apply basic security headers
     */
    private function applySecurityHeaders(Response $response, Request $request): void
    {
        foreach ($this->securityHeaders as $header => $value) {
            $response->headers->set($header, $value);
        }
    }

    /**
     * Apply HTTP Strict Transport Security
     */
    private function applyHSTS(Response $response, Request $request): void
    {
        if ($request->isSecure()) {
            $maxAge = Config::get('security.hsts.max_age', 31536000); // 1 year default
            $includeSubDomains = Config::get('security.hsts.include_subdomains', true);
            $preload = Config::get('security.hsts.preload', true);

            $hsts = "max-age={$maxAge}";
            
            if ($includeSubDomains) {
                $hsts .= '; includeSubDomains';
            }
            
            if ($preload) {
                $hsts .= '; preload';
            }

            $response->headers->set('Strict-Transport-Security', $hsts);
        }
    }

    /**
     * Apply Content Security Policy
     */
    private function applyCSP(Response $response, Request $request): void
    {
        $isApiRoute = $request->is('api/*');
        
        if ($isApiRoute) {
            // Stricter CSP for API routes
            $csp = [
                "default-src 'none'",
                "script-src 'none'",
                "style-src 'none'",
                "img-src 'none'",
                "font-src 'none'",
                "connect-src 'self'",
                "media-src 'none'",
                "object-src 'none'",
                "child-src 'none'",
                "worker-src 'none'",
                "frame-ancestors 'none'",
                "form-action 'none'",
                "base-uri 'none'",
            ];
        } else {
            // More permissive CSP for web routes (if any)
            $frontendUrl = Config::get('app.frontend_url', 'http://localhost:3000');
            
            $csp = [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "img-src 'self' data: https: blob:",
                "font-src 'self' https://fonts.gstatic.com",
                "connect-src 'self' {$frontendUrl}",
                "media-src 'self'",
                "object-src 'none'",
                "child-src 'none'",
                "worker-src 'self'",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "base-uri 'self'",
                "upgrade-insecure-requests",
            ];
        }
        
        $response->headers->set('Content-Security-Policy', implode('; ', $csp));
        
        // Also set report-only version for monitoring
        if (Config::get('security.csp.report_only', false)) {
            $response->headers->set('Content-Security-Policy-Report-Only', implode('; ', $csp));
        }
    }

    /**
     * Apply Permissions Policy (formerly Feature Policy)
     */
    private function applyPermissionsPolicy(Response $response): void
    {
        $permissions = [
            'accelerometer' => '()',
            'ambient-light-sensor' => '()',
            'autoplay' => '()',
            'battery' => '()',
            'camera' => '()',
            'cross-origin-isolated' => '()',
            'display-capture' => '()',
            'document-domain' => '()',
            'encrypted-media' => '()',
            'execution-while-not-rendered' => '()',
            'execution-while-out-of-viewport' => '()',
            'fullscreen' => '()',
            'geolocation' => '()',
            'gyroscope' => '()',
            'keyboard-map' => '()',
            'magnetometer' => '()',
            'microphone' => '()',
            'midi' => '()',
            'navigation-override' => '()',
            'payment' => '()',
            'picture-in-picture' => '()',
            'publickey-credentials-get' => '()',
            'screen-wake-lock' => '()',
            'sync-xhr' => '()',
            'usb' => '()',
            'web-share' => '()',
            'xr-spatial-tracking' => '()',
        ];

        $permissionsHeader = [];
        foreach ($permissions as $permission => $value) {
            $permissionsHeader[] = "{$permission}={$value}";
        }

        $response->headers->set('Permissions-Policy', implode(', ', $permissionsHeader));
    }

    /**
     * Remove server identifying headers
     */
    private function removeServerHeaders(Response $response): void
    {
        $headersToRemove = [
            'Server',
            'X-Powered-By',
            'X-AspNet-Version',
            'X-AspNetMvc-Version',
            'X-Generator',
        ];

        foreach ($headersToRemove as $header) {
            $response->headers->remove($header);
        }
    }

    /**
     * Apply cache control headers for sensitive routes
     */
    private function applyCacheControl(Response $response, Request $request): void
    {
        $sensitiveRoutes = [
            'api/user/*',
            'api/auth/*',
            'api/food-logs/*',
            'api/meal-plans/*',
        ];

        $isSensitiveRoute = false;
        foreach ($sensitiveRoutes as $pattern) {
            if ($request->is($pattern)) {
                $isSensitiveRoute = true;
                break;
            }
        }

        if ($isSensitiveRoute) {
            $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }
    }
}
