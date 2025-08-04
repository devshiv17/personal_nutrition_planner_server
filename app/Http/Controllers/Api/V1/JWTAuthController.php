<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JWTRefreshToken;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\JWTService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class JWTAuthController extends Controller
{
    protected JWTService $jwtService;

    public function __construct(JWTService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    /**
     * Login with JWT
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => [
                'required',
                'email:rfc,dns',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
                'max:128',
            ],
            'remember_me' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            $this->logFailedAttempt(
                $request->input('email', ''),
                $request->ip(),
                $request->userAgent(),
                'validation_failed',
                $validator->errors()->toArray()
            );

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $email = strtolower(trim($request->email));
        $password = $request->password;
        $ipAddress = $request->ip();
        $userAgent = $request->userAgent();
        $rememberMe = $request->boolean('remember_me', false);

        try {
            DB::beginTransaction();

            // Check if account is temporarily locked
            $failedAttempts = LoginAttempt::getRecentFailedAttempts($email, 15);
            if ($failedAttempts >= 5) {
                $this->logFailedAttempt($email, $ipAddress, $userAgent, 'account_locked');
                
                return response()->json([
                    'message' => 'Account temporarily locked due to multiple failed login attempts. Please try again in 15 minutes.',
                    'account_locked' => true,
                    'retry_after' => 900,
                ], 423);
            }

            // Find user
            $user = User::where('email', $email)->first();

            // Always check password even if user doesn't exist (timing attack prevention)
            $providedPasswordHash = $user ? $user->password_hash : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
            $passwordValid = Hash::check($password, $providedPasswordHash);

            if (!$user || !$passwordValid) {
                $this->logFailedAttempt($email, $ipAddress, $userAgent, 'invalid_credentials');
                
                // Add progressive delay based on failed attempts
                if ($failedAttempts >= 2) {
                    sleep(min($failedAttempts, 5));
                }

                DB::commit();
                return response()->json([
                    'message' => 'Invalid credentials'
                ], 401);
            }

            // Check if account is active
            if (!$user->is_active) {
                $this->logFailedAttempt($email, $ipAddress, $userAgent, 'account_deactivated');
                
                DB::commit();
                return response()->json([
                    'message' => 'Account is deactivated. Please contact support.'
                ], 403);
            }

            // Check email verification
            if (!$user->hasVerifiedEmail()) {
                $this->logFailedAttempt($email, $ipAddress, $userAgent, 'email_not_verified');
                
                DB::commit();
                return response()->json([
                    'message' => 'Please verify your email address before logging in.',
                    'email_verification_required' => true
                ], 403);
            }

            // Successful login - log attempt and update user
            $this->logSuccessfulAttempt($email, $ipAddress, $userAgent);
            
            // Update user's last login info
            $user->update([
                'last_login_at' => Carbon::now(),
                'last_login_ip' => $ipAddress,
            ]);

            // Generate JWT token pair
            $tokenData = $this->jwtService->generateTokenPair($user);

            // Store refresh token in database
            $this->storeRefreshToken($user, $tokenData['refresh_token'], $ipAddress, $userAgent);

            DB::commit();

            // Log successful login
            Log::info('User logged in successfully with JWT', [
                'user_id' => $user->id,
                'email' => $email,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'remember_me' => $rememberMe,
            ]);

            return response()->json([
                'message' => 'Login successful',
                'user' => $user->makeHidden(['password_hash', 'last_login_ip']),
                ...$tokenData,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('JWT Login error', [
                'email' => $email,
                'ip_address' => $ipAddress,
                'error' => $e->getMessage(),
            ]);

            $this->logFailedAttempt($email, $ipAddress, $userAgent, 'system_error');

            return response()->json([
                'message' => 'Login failed due to system error. Please try again.',
            ], 500);
        }
    }

    /**
     * Refresh access token
     */
    public function refreshToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $refreshToken = $request->refresh_token;

        try {
            $tokenData = $this->jwtService->refreshAccessToken($refreshToken);

            if (!$tokenData) {
                return response()->json([
                    'message' => 'Invalid or expired refresh token',
                    'token_invalid' => true,
                ], 401);
            }

            // Update refresh token last used timestamp
            $decoded = $this->jwtService->validateToken($refreshToken);
            if ($decoded && isset($decoded->jti)) {
                $dbToken = JWTRefreshToken::findByJti($decoded->jti);
                if ($dbToken) {
                    $dbToken->markAsUsed();
                }
            }

            Log::info('Access token refreshed', [
                'user_id' => $decoded->sub ?? null,
                'ip_address' => $request->ip(),
            ]);

            return response()->json($tokenData);

        } catch (\Exception $e) {
            Log::error('Token refresh error', [
                'error' => $e->getMessage(),
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Token refresh failed',
            ], 500);
        }
    }

    /**
     * Logout (revoke current session)
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $token = $request->attributes->get('jwt_token');
            $user = $request->user();

            if ($token) {
                // Blacklist the access token
                $this->jwtService->blacklistToken($token);
            }

            // Get token info to find associated refresh token
            $tokenInfo = $this->jwtService->getTokenInfo($token);
            if ($tokenInfo && isset($tokenInfo['jti'])) {
                // Find and revoke associated refresh token
                $refreshToken = JWTRefreshToken::where('user_id', $user->id)
                    ->where('is_revoked', false)
                    ->first(); // In a real implementation, you'd link access and refresh tokens

                if ($refreshToken) {
                    $refreshToken->revoke();
                }
            }

            Log::info('User logged out (JWT)', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Logged out successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('JWT Logout error', [
                'error' => $e->getMessage(),
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Logout failed'
            ], 500);
        }
    }

    /**
     * Logout from all devices
     */
    public function logoutAll(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Blacklist all tokens for the user
            $this->jwtService->blacklistAllUserTokens($user->id);

            // Revoke all refresh tokens
            JWTRefreshToken::revokeAllForUser($user->id);

            Log::info('User logged out from all devices (JWT)', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Logged out from all devices successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('JWT Logout all error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Logout from all devices failed'
            ], 500);
        }
    }

    /**
     * Get active refresh tokens for current user
     */
    public function getActiveTokens(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $tokens = JWTRefreshToken::getActiveTokensForUser($user->id);

            $tokenList = $tokens->map(function ($token) {
                return [
                    'id' => $token->id,
                    'jti' => $token->jti,
                    'ip_address' => $token->ip_address,
                    'user_agent' => $token->user_agent,
                    'created_at' => $token->created_at->toISOString(),
                    'expires_at' => $token->expires_at->toISOString(),
                    'last_used_at' => $token->last_used_at?->toISOString(),
                ];
            });

            return response()->json([
                'tokens' => $tokenList,
                'total_tokens' => $tokens->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Get active tokens error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Failed to retrieve active tokens'
            ], 500);
        }
    }

    /**
     * Revoke specific refresh token
     */
    public function revokeToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token_id' => 'required|integer|exists:jwt_refresh_tokens,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->user();
            $tokenId = $request->token_id;
            
            $token = JWTRefreshToken::where('id', $tokenId)
                ->where('user_id', $user->id)
                ->where('is_revoked', false)
                ->first();
            
            if (!$token) {
                return response()->json([
                    'message' => 'Token not found or access denied'
                ], 404);
            }

            $token->revoke();

            Log::info('Refresh token revoked', [
                'user_id' => $user->id,
                'token_id' => $tokenId,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Token revoked successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Revoke token error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'token_id' => $request->token_id ?? null,
            ]);

            return response()->json([
                'message' => 'Failed to revoke token'
            ], 500);
        }
    }

    /**
     * Store refresh token in database
     */
    private function storeRefreshToken(User $user, string $refreshToken, string $ipAddress, ?string $userAgent): void
    {
        try {
            $decoded = $this->jwtService->validateToken($refreshToken);
            if ($decoded && isset($decoded->jti, $decoded->exp)) {
                JWTRefreshToken::create([
                    'user_id' => $user->id,
                    'jti' => $decoded->jti,
                    'token_hash' => hash('sha256', $refreshToken),
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'expires_at' => Carbon::createFromTimestamp($decoded->exp),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error storing refresh token', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log a failed login attempt
     */
    private function logFailedAttempt(
        string $email,
        string $ipAddress,
        ?string $userAgent,
        string $failureReason,
        ?array $requestData = null
    ): void {
        try {
            LoginAttempt::logAttempt(
                $email,
                $ipAddress,
                $userAgent,
                false,
                $failureReason,
                $requestData
            );
        } catch (\Exception $e) {
            Log::error('Failed to log login attempt', [
                'error' => $e->getMessage(),
                'email' => $email,
                'ip_address' => $ipAddress,
            ]);
        }
    }

    /**
     * Log a successful login attempt
     */
    private function logSuccessfulAttempt(
        string $email,
        string $ipAddress,
        ?string $userAgent
    ): void {
        try {
            LoginAttempt::logAttempt(
                $email,
                $ipAddress,
                $userAgent,
                true
            );
        } catch (\Exception $e) {
            Log::error('Failed to log successful login attempt', [
                'error' => $e->getMessage(),
                'email' => $email,
                'ip_address' => $ipAddress,
            ]);
        }
    }
}
