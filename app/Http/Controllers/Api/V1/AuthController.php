<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use App\Notifications\PasswordResetSuccessNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z\s\-\'\.]+$/',  // Only letters, spaces, hyphens, apostrophes, dots
            ],
            'last_name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z\s\-\'\.]+$/',
            ],
            'email' => [
                'required',
                'string',
                'email:rfc,dns',  // More strict email validation
                'max:255',
                'unique:users,email',
                'not_regex:/\+.*@/',  // Prevent plus addressing for security
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:128',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',  // Strong password
            ],
            'date_of_birth' => [
                'nullable',
                'date',
                'before:today',
                'after:1900-01-01',  // Reasonable age limit
            ],
            'gender' => 'nullable|in:male,female,other,prefer_not_to_say',
            'height_cm' => [
                'nullable',
                'numeric',
                'min:50',
                'max:300',
                'decimal:0,2',
            ],
            'current_weight_kg' => [
                'nullable',
                'numeric',
                'min:20',
                'max:500',
                'decimal:0,2',
            ],
            'activity_level' => 'nullable|in:sedentary,lightly_active,moderately_active,very_active',
            'primary_goal' => 'nullable|in:weight_loss,weight_gain,maintenance,muscle_gain,health_management',
            'dietary_preference' => 'nullable|in:keto,mediterranean,vegan,diabetic_friendly',
        ], [
            'first_name.regex' => 'First name can only contain letters, spaces, hyphens, apostrophes, and dots.',
            'last_name.regex' => 'Last name can only contain letters, spaces, hyphens, apostrophes, and dots.',
            'email.not_regex' => 'Email addresses with plus signs are not allowed.',
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.',
            'date_of_birth.after' => 'Please enter a valid birth date.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::create([
                'first_name' => trim($request->first_name),
                'last_name' => trim($request->last_name),
                'email' => strtolower(trim($request->email)),
                'password_hash' => Hash::make($request->password),
                'date_of_birth' => $request->date_of_birth,
                'gender' => $request->gender,
                'height_cm' => $request->height_cm,
                'current_weight_kg' => $request->current_weight_kg,
                'activity_level' => $request->activity_level ?? 'sedentary',
                'primary_goal' => $request->primary_goal ?? 'maintenance',
                'dietary_preference' => $request->dietary_preference,
                'is_active' => true,
                'email_notifications' => true,
                'push_notifications' => false,
            ]);

            // Calculate BMR and TDEE if we have the required data
            if ($user->height_cm && $user->current_weight_kg && $user->date_of_birth && $user->gender) {
                $this->calculateMetrics($user);
            }

            // Send email verification notification
            $user->sendEmailVerificationNotification();

            return response()->json([
                'message' => 'User registered successfully. Please check your email to verify your account.',
                'user' => $user->makeHidden(['password_hash']),
                'email_verification_sent' => true,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Registration failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Login user with comprehensive security
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

            // Create token with appropriate expiration
            $rememberMe = $request->boolean('remember_me', false);
            $tokenName = 'auth_token_' . Carbon::now()->timestamp;
            $token = $user->createToken($tokenName);
            
            // Set token expiration (24 hours default, 30 days if remember me)
            if ($rememberMe) {
                $token->accessToken->expires_at = Carbon::now()->addDays(30);
            } else {
                $token->accessToken->expires_at = Carbon::now()->addHours(24);
            }
            $token->accessToken->save();

            DB::commit();

            // Log successful login
            Log::info('User logged in successfully', [
                'user_id' => $user->id,
                'email' => $email,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'remember_me' => $rememberMe,
            ]);

            return response()->json([
                'message' => 'Login successful',
                'user' => $user->makeHidden(['password_hash', 'last_login_ip']),
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at->toISOString(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Login error', [
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
     * Logout user (single session)
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $token = $request->user()->currentAccessToken();
            
            // Log the logout
            Log::info('User logged out', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip_address' => $request->ip(),
                'token_name' => $token->name,
            ]);

            // Delete the current token
            $token->delete();

            return response()->json([
                'message' => 'Logged out successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Logout error', [
                'error' => $e->getMessage(),
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Logout failed'
            ], 500);
        }
    }

    /**
     * Logout user from all devices
     */
    public function logoutAll(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            // Log the logout from all devices
            Log::info('User logged out from all devices', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip_address' => $request->ip(),
                'tokens_count' => $user->tokens()->count(),
            ]);

            // Delete all tokens for the user
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Logged out from all devices successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Logout all error', [
                'error' => $e->getMessage(),
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Logout from all devices failed'
            ], 500);
        }
    }

    /**
     * Request password reset
     */
    public function requestPasswordReset(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => [
                'required',
                'email:rfc,dns',
                'max:255',
                'exists:users,email',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $email = strtolower(trim($request->email));
        $ipAddress = $request->ip();
        $userAgent = $request->userAgent();

        try {
            // Check rate limiting
            if (PasswordResetToken::hasRecentRequest($email, 15)) {
                return response()->json([
                    'message' => 'Password reset request already sent. Please check your email or wait 15 minutes before requesting again.',
                    'rate_limited' => true,
                    'retry_after' => 900,
                ], 429);
            }

            // Check for abuse - limit attempts per email
            $emailAttempts = PasswordResetToken::getRecentAttempts($email, 60);
            if ($emailAttempts >= 3) {
                return response()->json([
                    'message' => 'Too many password reset attempts. Please try again in 1 hour.',
                    'rate_limited' => true,
                    'retry_after' => 3600,
                ], 429);
            }

            // Check for abuse - limit attempts per IP
            $ipAttempts = PasswordResetToken::getRecentAttemptsByIp($ipAddress, 60);
            if ($ipAttempts >= 5) {
                return response()->json([
                    'message' => 'Too many password reset attempts from this IP address. Please try again in 1 hour.',
                    'rate_limited' => true,
                    'retry_after' => 3600,
                ], 429);
            }

            DB::beginTransaction();

            $user = User::where('email', $email)->first();

            // Create password reset token
            $resetToken = PasswordResetToken::createToken($email, $ipAddress, $userAgent, 60);

            // Generate reset URL (you'll need to configure this based on your frontend)
            $resetUrl = config('app.frontend_url') . '/reset-password?token=' . $resetToken->token;

            // Send password reset email
            $user->notify(new PasswordResetNotification($resetToken, $resetUrl));

            DB::commit();

            // Log the password reset request
            Log::info('Password reset requested', [
                'email' => $email,
                'ip_address' => $ipAddress,
                'token_id' => $resetToken->id,
            ]);

            // Always return success to prevent email enumeration
            return response()->json([
                'message' => 'If an account with that email exists, a password reset link has been sent.',
                'sent' => true,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Password reset request error', [
                'email' => $email,
                'ip_address' => $ipAddress,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Password reset request failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Reset password with token
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => [
                'required',
                'string',
                'min:64',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:128',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
            ],
        ], [
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $token = $request->token;
        $password = $request->password;
        $ipAddress = $request->ip();

        try {
            DB::beginTransaction();

            // Verify and consume the token
            $resetToken = PasswordResetToken::verifyToken($token);

            if (!$resetToken) {
                return response()->json([
                    'message' => 'Invalid or expired password reset token.',
                    'token_invalid' => true,
                ], 400);
            }

            // Get the user
            $user = User::where('email', $resetToken->email)->first();

            if (!$user) {
                return response()->json([
                    'message' => 'User not found.',
                ], 404);
            }

            // Update the user's password
            $user->update([
                'password_hash' => Hash::make($password),
            ]);

            // Invalidate all existing tokens/sessions for security
            $user->tokens()->delete();

            DB::commit();

            // Send success notification
            $user->notify(new PasswordResetSuccessNotification($ipAddress, Carbon::now()));

            // Log successful password reset
            Log::info('Password reset successful', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip_address' => $ipAddress,
                'token_id' => $resetToken->id,
            ]);

            return response()->json([
                'message' => 'Password reset successful. You can now log in with your new password.',
                'success' => true,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Password reset error', [
                'token' => substr($token, 0, 8) . '...',
                'ip_address' => $ipAddress,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Password reset failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Verify password reset token (check if valid without consuming)
     */
    public function verifyPasswordResetToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string|min:64|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $token = $request->token;

        $resetToken = PasswordResetToken::where('token', $token)
            ->where('used', false)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$resetToken) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid or expired token',
            ], 400);
        }

        $user = User::where('email', $resetToken->email)->first();

        return response()->json([
            'valid' => true,
            'email' => $resetToken->email,
            'expires_at' => $resetToken->expires_at->toISOString(),
            'user_name' => $user ? $user->first_name : null,
        ]);
    }

    /**
     * Calculate BMR and TDEE for user
     */
    private function calculateMetrics(User $user): void
    {
        if (!$user->height_cm || !$user->current_weight_kg || !$user->date_of_birth || !$user->gender) {
            return;
        }

        $age = now()->diffInYears($user->date_of_birth);
        $weight = $user->current_weight_kg;
        $height = $user->height_cm;

        // Mifflin-St Jeor Equation
        if ($user->gender === 'male') {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) + 5;
        } else {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) - 161;
        }

        // Activity multipliers
        $activityMultipliers = [
            'sedentary' => 1.2,
            'lightly_active' => 1.375,
            'moderately_active' => 1.55,
            'very_active' => 1.725,
        ];

        $tdee = $bmr * ($activityMultipliers[$user->activity_level] ?? 1.2);

        // Set daily calorie target based on goal
        $calorieAdjustments = [
            'weight_loss' => -500,
            'weight_gain' => 500,
            'muscle_gain' => 300,
            'maintenance' => 0,
            'health_management' => 0,
        ];

        $dailyTarget = $tdee + ($calorieAdjustments[$user->primary_goal] ?? 0);

        $user->update([
            'bmr_calories' => round($bmr, 2),
            'tdee_calories' => round($tdee, 2),
            'daily_calorie_target' => round($dailyTarget),
        ]);
    }

    /**
     * Verify email address
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $user = User::findOrFail($request->route('id'));

        if (!hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))) {
            return response()->json([
                'message' => 'Invalid verification link'
            ], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified',
                'verified' => true
            ]);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
            // Send welcome email
            $user->notify(new WelcomeNotification());
        }

        return response()->json([
            'message' => 'Email verified successfully',
            'verified' => true
        ]);
    }

    /**
     * Resend email verification notification
     */
    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email is already verified'
            ], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Verification email sent successfully'
        ]);
    }

    /**
     * Check email verification status
     */
    public function checkEmailVerification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        return response()->json([
            'verified' => $user->hasVerifiedEmail(),
            'email_verified_at' => $user->email_verified_at
        ]);
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

    /**
     * Get active sessions for current user
     */
    public function getActiveSessions(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $currentToken = $request->user()->currentAccessToken();
            
            $sessions = $user->tokens()
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', Carbon::now())
                ->get()
                ->map(function ($token) use ($currentToken) {
                    return [
                        'id' => $token->id,
                        'name' => $token->name,
                        'last_used_at' => $token->last_used_at,
                        'created_at' => $token->created_at,
                        'expires_at' => $token->expires_at,
                        'is_current' => $token->id === $currentToken->id,
                    ];
                });

            return response()->json([
                'sessions' => $sessions,
                'total_sessions' => $sessions->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Get active sessions error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Failed to retrieve active sessions'
            ], 500);
        }
    }

    /**
     * Revoke a specific session
     */
    public function revokeSession(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token_id' => 'required|integer|exists:personal_access_tokens,id',
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
            
            // Ensure the token belongs to the authenticated user
            $token = $user->tokens()->where('id', $tokenId)->first();
            
            if (!$token) {
                return response()->json([
                    'message' => 'Session not found or access denied'
                ], 404);
            }

            // Prevent revoking current session
            if ($token->id === $request->user()->currentAccessToken()->id) {
                return response()->json([
                    'message' => 'Cannot revoke current session. Use logout instead.'
                ], 400);
            }

            $token->delete();

            Log::info('Session revoked', [
                'user_id' => $user->id,
                'revoked_token_id' => $tokenId,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Session revoked successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Revoke session error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'token_id' => $request->token_id ?? null,
            ]);

            return response()->json([
                'message' => 'Failed to revoke session'
            ], 500);
        }
    }
}