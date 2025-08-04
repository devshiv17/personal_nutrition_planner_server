<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class JWTService
{
    private string $secretKey;
    private string $algorithm;
    private int $accessTokenTTL;
    private int $refreshTokenTTL;
    private string $issuer;

    public function __construct()
    {
        $this->secretKey = config('jwt.secret_key');
        $this->algorithm = config('jwt.algorithm', 'HS256');
        $this->accessTokenTTL = config('jwt.access_token_ttl', 900); // 15 minutes
        $this->refreshTokenTTL = config('jwt.refresh_token_ttl', 604800); // 7 days
        $this->issuer = config('jwt.issuer', config('app.name'));
    }

    /**
     * Generate access token
     */
    public function generateAccessToken(User $user, array $customClaims = []): string
    {
        $now = Carbon::now();
        $payload = array_merge([
            'iss' => $this->issuer,
            'sub' => $user->id,
            'aud' => config('app.url'),
            'iat' => $now->timestamp,
            'nbf' => $now->timestamp,
            'exp' => $now->addSeconds($this->accessTokenTTL)->timestamp,
            'jti' => Str::uuid()->toString(),
            'typ' => 'access',
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email_verified' => $user->hasVerifiedEmail(),
                'is_active' => $user->is_active,
            ],
        ], $customClaims);

        return JWT::encode($payload, $this->secretKey, $this->algorithm);
    }

    /**
     * Generate refresh token
     */
    public function generateRefreshToken(User $user): string
    {
        $now = Carbon::now();
        $jti = Str::uuid()->toString();
        
        $payload = [
            'iss' => $this->issuer,
            'sub' => $user->id,
            'aud' => config('app.url'),
            'iat' => $now->timestamp,
            'nbf' => $now->timestamp,
            'exp' => $now->addSeconds($this->refreshTokenTTL)->timestamp,
            'jti' => $jti,
            'typ' => 'refresh',
        ];

        $token = JWT::encode($payload, $this->secretKey, $this->algorithm);

        // Store refresh token in cache for validation
        $this->storeRefreshToken($user->id, $jti, $this->refreshTokenTTL);

        return $token;
    }

    /**
     * Generate token pair (access + refresh)
     */
    public function generateTokenPair(User $user, array $customClaims = []): array
    {
        return [
            'access_token' => $this->generateAccessToken($user, $customClaims),
            'refresh_token' => $this->generateRefreshToken($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTTL,
            'refresh_expires_in' => $this->refreshTokenTTL,
        ];
    }

    /**
     * Validate and decode token
     */
    public function validateToken(string $token): ?object
    {
        try {
            // Check if token is blacklisted
            if ($this->isTokenBlacklisted($token)) {
                return null;
            }

            $decoded = JWT::decode($token, new Key($this->secretKey, $this->algorithm));
            
            // Additional validation
            if (!$this->isTokenValid($decoded)) {
                return null;
            }

            return $decoded;
        } catch (ExpiredException $e) {
            Log::info('JWT token expired', ['error' => $e->getMessage()]);
            return null;
        } catch (SignatureInvalidException $e) {
            Log::warning('JWT signature invalid', ['error' => $e->getMessage()]);
            return null;
        } catch (BeforeValidException $e) {
            Log::warning('JWT token not yet valid', ['error' => $e->getMessage()]);
            return null;
        } catch (\Exception $e) {
            Log::error('JWT validation error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Refresh access token using refresh token
     */
    public function refreshAccessToken(string $refreshToken): ?array
    {
        $decoded = $this->validateToken($refreshToken);
        
        if (!$decoded || $decoded->typ !== 'refresh') {
            return null;
        }

        // Check if refresh token is stored and valid
        if (!$this->isRefreshTokenValid($decoded->sub, $decoded->jti)) {
            return null;
        }

        $user = User::find($decoded->sub);
        if (!$user || !$user->is_active) {
            return null;
        }

        // Generate new access token
        return [
            'access_token' => $this->generateAccessToken($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTTL,
        ];
    }

    /**
     * Blacklist a token
     */
    public function blacklistToken(string $token): bool
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, $this->algorithm));
            $jti = $decoded->jti ?? null;
            
            if ($jti) {
                $ttl = max($decoded->exp - time(), 0);
                Cache::put("jwt_blacklist:{$jti}", true, $ttl);
                
                Log::info('JWT token blacklisted', ['jti' => $jti]);
                return true;
            }
        } catch (\Exception $e) {
            Log::error('Error blacklisting token', ['error' => $e->getMessage()]);
        }
        
        return false;
    }

    /**
     * Blacklist all tokens for a user
     */
    public function blacklistAllUserTokens(int $userId): void
    {
        // Set a flag to invalidate all tokens issued before this time
        $now = Carbon::now()->timestamp;
        Cache::put("user_token_invalidate:{$userId}", $now, $this->refreshTokenTTL);
        
        // Remove all refresh tokens for this user
        $this->revokeAllRefreshTokens($userId);
        
        Log::info('All JWT tokens blacklisted for user', ['user_id' => $userId]);
    }

    /**
     * Get user from token
     */
    public function getUserFromToken(string $token): ?User
    {
        $decoded = $this->validateToken($token);
        
        if (!$decoded || $decoded->typ !== 'access') {
            return null;
        }

        return User::find($decoded->sub);
    }

    /**
     * Extract token from request
     */
    public function extractTokenFromHeader(?string $authHeader): ?string
    {
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        return substr($authHeader, 7);
    }

    /**
     * Get token information
     */
    public function getTokenInfo(string $token): ?array
    {
        $decoded = $this->validateToken($token);
        
        if (!$decoded) {
            return null;
        }

        return [
            'jti' => $decoded->jti ?? null,
            'sub' => $decoded->sub ?? null,
            'typ' => $decoded->typ ?? null,
            'iat' => $decoded->iat ?? null,
            'exp' => $decoded->exp ?? null,
            'expires_at' => isset($decoded->exp) ? Carbon::createFromTimestamp($decoded->exp)->toISOString() : null,
            'is_expired' => isset($decoded->exp) ? $decoded->exp < time() : false,
        ];
    }

    /**
     * Check if token is blacklisted
     */
    private function isTokenBlacklisted(string $token): bool
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, $this->algorithm));
            $jti = $decoded->jti ?? null;
            
            if ($jti && Cache::has("jwt_blacklist:{$jti}")) {
                return true;
            }

            // Check if all user tokens are invalidated
            $userId = $decoded->sub ?? null;
            $tokenIat = $decoded->iat ?? null;
            
            if ($userId && $tokenIat) {
                $invalidateTime = Cache::get("user_token_invalidate:{$userId}");
                if ($invalidateTime && $tokenIat < $invalidateTime) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // If we can't decode, treat as blacklisted for security
            return true;
        }
        
        return false;
    }

    /**
     * Additional token validation
     */
    private function isTokenValid(object $decoded): bool
    {
        // Check required claims
        $requiredClaims = ['iss', 'sub', 'aud', 'iat', 'exp', 'jti', 'typ'];
        foreach ($requiredClaims as $claim) {
            if (!isset($decoded->$claim)) {
                return false;
            }
        }

        // Validate issuer
        if ($decoded->iss !== $this->issuer) {
            return false;
        }

        // Validate audience
        if ($decoded->aud !== config('app.url')) {
            return false;
        }

        return true;
    }

    /**
     * Store refresh token
     */
    private function storeRefreshToken(int $userId, string $jti, int $ttl): void
    {
        $key = "refresh_token:{$userId}:{$jti}";
        Cache::put($key, true, $ttl);
    }

    /**
     * Check if refresh token is valid
     */
    private function isRefreshTokenValid(int $userId, string $jti): bool
    {
        $key = "refresh_token:{$userId}:{$jti}";
        return Cache::has($key);
    }

    /**
     * Revoke specific refresh token
     */
    public function revokeRefreshToken(int $userId, string $jti): void
    {
        $key = "refresh_token:{$userId}:{$jti}";
        Cache::forget($key);
    }

    /**
     * Revoke all refresh tokens for a user
     */
    public function revokeAllRefreshTokens(int $userId): void
    {
        // This is a simplified approach - in production you might want to use a more efficient method
        $pattern = "refresh_token:{$userId}:*";
        $keys = Cache::getRedis()->keys($pattern);
        
        if (!empty($keys)) {
            Cache::getRedis()->del($keys);
        }
    }
}