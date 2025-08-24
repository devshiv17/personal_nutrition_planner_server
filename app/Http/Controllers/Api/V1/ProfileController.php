<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\ProfileCompletion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProfileController extends BaseApiController
{
    /**
     * Get profile completion status
     */
    public function completion(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            // Calculate current completion status
            $profileCompletion = ProfileCompletion::calculateCompletionForUser($user->id);
            
            if (!$profileCompletion) {
                return $this->error('Failed to calculate profile completion', 500);
            }

            $completionDetails = $profileCompletion->getCompletionDetails();
            $nextSection = $profileCompletion->getNextSection();
            $missingCritical = $profileCompletion->getMissingCriticalSections();

            return $this->success([
                'completion_percentage' => $profileCompletion->completion_percentage,
                'is_complete' => $profileCompletion->isProfileComplete(),
                'completed_sections' => $profileCompletion->completed_sections,
                'pending_sections' => $profileCompletion->pending_sections,
                'next_section' => $nextSection,
                'missing_critical_sections' => $missingCritical,
                'completion_details' => $completionDetails,
                'last_updated' => $profileCompletion->last_updated
            ], 'Profile completion status retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve profile completion', 500, $e->getMessage());
        }
    }

    /**
     * Mark a profile section as completed
     */
    public function markSectionComplete(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $validatedData = $request->validate([
                'section' => 'required|string|in:' . implode(',', array_keys(ProfileCompletion::PROFILE_SECTIONS))
            ]);

            // Recalculate completion after marking section
            $profileCompletion = ProfileCompletion::calculateCompletionForUser($user->id);
            
            return $this->success($profileCompletion, 'Profile section marked as complete');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to mark section as complete', 500, $e->getMessage());
        }
    }

    /**
     * Get profile completion progress with recommendations
     */
    public function progress(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $profileCompletion = $user->profileCompletion;
            
            if (!$profileCompletion) {
                $profileCompletion = ProfileCompletion::calculateCompletionForUser($user->id);
            }

            $recommendations = $this->getCompletionRecommendations($profileCompletion);
            $benefits = $this->getCompletionBenefits($profileCompletion->completion_percentage);

            return $this->success([
                'current_percentage' => $profileCompletion->completion_percentage,
                'recommendations' => $recommendations,
                'benefits' => $benefits,
                'sections_overview' => $this->getSectionsOverview($profileCompletion)
            ], 'Profile progress retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve profile progress', 500, $e->getMessage());
        }
    }

    /**
     * Get profile sections overview
     */
    public function sections(Request $request): JsonResponse
    {
        try {
            $sections = [];
            
            foreach (ProfileCompletion::PROFILE_SECTIONS as $key => $section) {
                $sections[] = [
                    'key' => $key,
                    'name' => $section['name'],
                    'weight' => $section['weight'],
                    'required_fields' => $section['fields']
                ];
            }

            return $this->success($sections, 'Profile sections retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve profile sections', 500, $e->getMessage());
        }
    }

    /**
     * Get completion recommendations based on current status
     */
    private function getCompletionRecommendations($profileCompletion): array
    {
        $recommendations = [];
        $pendingSections = $profileCompletion->pending_sections ?? [];
        
        // Critical sections first
        $criticalSections = ['basic_info', 'physical_metrics', 'fitness_goals'];
        $missingCritical = array_intersect($criticalSections, $pendingSections);
        
        if (!empty($missingCritical)) {
            foreach ($missingCritical as $section) {
                $sectionInfo = ProfileCompletion::PROFILE_SECTIONS[$section];
                $recommendations[] = [
                    'type' => 'critical',
                    'title' => "Complete {$sectionInfo['name']}",
                    'description' => "This section is essential for personalized recommendations",
                    'section' => $section,
                    'priority' => 'high',
                    'estimated_time' => '2-3 minutes'
                ];
            }
        }

        // High-value sections
        $highValueSections = ['health_conditions', 'dietary_preferences'];
        $missingHighValue = array_intersect($highValueSections, $pendingSections);
        
        foreach ($missingHighValue as $section) {
            $sectionInfo = ProfileCompletion::PROFILE_SECTIONS[$section];
            $recommendations[] = [
                'type' => 'high_value',
                'title' => "Add {$sectionInfo['name']}",
                'description' => "Helps us provide safer and more accurate nutrition advice",
                'section' => $section,
                'priority' => 'medium',
                'estimated_time' => '3-4 minutes'
            ];
        }

        // Quick wins
        $quickSections = ['preferences', 'initial_measurements'];
        $missingQuick = array_intersect($quickSections, $pendingSections);
        
        foreach ($missingQuick as $section) {
            $sectionInfo = ProfileCompletion::PROFILE_SECTIONS[$section];
            $recommendations[] = [
                'type' => 'quick_win',
                'title' => "Set up {$sectionInfo['name']}",
                'description' => "Quick setup for a better app experience",
                'section' => $section,
                'priority' => 'low',
                'estimated_time' => '1-2 minutes'
            ];
        }

        return array_slice($recommendations, 0, 3); // Return top 3 recommendations
    }

    /**
     * Get completion benefits based on percentage
     */
    private function getCompletionBenefits($completionPercentage): array
    {
        $benefits = [
            'unlocked' => [],
            'upcoming' => []
        ];

        $allBenefits = [
            25 => 'Basic nutrition tracking',
            50 => 'Personalized meal suggestions',
            75 => 'Advanced health insights',
            90 => 'Custom workout recommendations',
            100 => 'Premium AI nutrition coach'
        ];

        foreach ($allBenefits as $threshold => $benefit) {
            if ($completionPercentage >= $threshold) {
                $benefits['unlocked'][] = [
                    'threshold' => $threshold,
                    'title' => $benefit,
                    'status' => 'unlocked'
                ];
            } else {
                $benefits['upcoming'][] = [
                    'threshold' => $threshold,
                    'title' => $benefit,
                    'status' => 'locked',
                    'progress_needed' => $threshold - $completionPercentage
                ];
            }
        }

        return $benefits;
    }

    /**
     * Get sections overview with completion status
     */
    private function getSectionsOverview($profileCompletion): array
    {
        $overview = [];
        $completedSections = $profileCompletion->completed_sections ?? [];
        
        foreach (ProfileCompletion::PROFILE_SECTIONS as $key => $section) {
            $overview[] = [
                'key' => $key,
                'name' => $section['name'],
                'weight' => $section['weight'],
                'completed' => in_array($key, $completedSections),
                'required_fields' => $section['fields']
            ];
        }

        return $overview;
    }
}