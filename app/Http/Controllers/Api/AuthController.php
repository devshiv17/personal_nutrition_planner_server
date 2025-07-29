<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other,prefer_not_to_say',
            'height_cm' => 'nullable|numeric|min:50|max:300',
            'current_weight_kg' => 'nullable|numeric|min:20|max:500',
            'activity_level' => 'nullable|in:sedentary,lightly_active,moderately_active,very_active',
            'primary_goal' => 'nullable|in:weight_loss,weight_gain,maintenance,muscle_gain,health_management',
            'dietary_preference' => 'nullable|in:keto,mediterranean,vegan,diabetic_friendly',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'password_hash' => Hash::make($request->password),
            'date_of_birth' => $request->date_of_birth,
            'gender' => $request->gender,
            'height_cm' => $request->height_cm,
            'current_weight_kg' => $request->current_weight_kg,
            'activity_level' => $request->activity_level ?? 'sedentary',
            'primary_goal' => $request->primary_goal ?? 'maintenance',
            'dietary_preference' => $request->dietary_preference,
        ]);

        // Calculate BMR and TDEE if we have the required data
        if ($user->height_cm && $user->current_weight_kg && $user->date_of_birth && $user->gender) {
            $this->calculateMetrics($user);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user->makeHidden(['password_hash']),
            'token' => $token,
            'token_type' => 'Bearer'
        ], 201);
    }

    /**
     * Login user
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Account is deactivated'
            ], 403);
        }

        // Update last login
        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user->makeHidden(['password_hash']),
            'token' => $token,
            'token_type' => 'Bearer'
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Reset password (simplified version)
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // In a real application, you would:
        // 1. Generate a reset token
        // 2. Store it in password_reset_tokens table
        // 3. Send email with reset link
        // For now, we'll just return a success message

        return response()->json([
            'message' => 'Password reset link sent to your email'
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
}