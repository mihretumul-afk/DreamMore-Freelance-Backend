<?php

namespace App\Services;

use App\Models\FreelancerProfile;
use App\Models\Job;
use App\Models\SavedFreelancer;
use App\Models\SavedJob;
use App\Models\User;
use Illuminate\Support\Collection;

class RecommendationService
{
    /**
     * Get recommended jobs for a freelancer based on their skills, saved jobs,
     * and profile information.
     *
     * Returns a collection of ['job' => Job, 'reason' => string, 'score' => float]
     */
    public function getRecommendedJobs(User $user, int $limit = 10): Collection
    {
        $profile = $user->freelancerProfile()->with(['skills.category'])->first();

        if (! $profile) {
            return collect();
        }

        // Gather the freelancer's skill IDs and category IDs.
        $skillIds = $profile->skills->pluck('id');
        $categoryIds = $profile->skills->pluck('category_id')->unique()->values();

        // Gather skill names for matching.
        $skillNames = $profile->skills->pluck('name')->map(fn ($name) => strtolower($name));

        // Get saved job IDs to use for recommendation signals.
        $savedJobIds = SavedJob::where('user_id', $user->id)->pluck('job_id');
        $savedJobs = Job::with(['skills', 'category'])->whereIn('id', $savedJobIds)->get();
        $savedSkillIds = $savedJobs->flatMap(fn ($j) => $j->skills->pluck('id'))->unique();
        $savedCategoryIds = $savedJobs->pluck('category_id')->filter()->unique();

        // Build a pool of candidate open jobs (exclude jobs already proposed to).
        $proposedJobIds = $user->proposals()->pluck('job_id');

        $candidates = Job::query()
            ->with(['category', 'skills', 'employer'])
            ->open()
            ->whereHas('employer', fn ($e) => $e->where('status', 'active'))
            ->whereNotIn('id', $proposedJobIds)
            ->where('employer_id', '!=', $user->id)
            ->get();

        $scored = $candidates->map(function (Job $job) use (
            $skillIds, $categoryIds, $skillNames,
            $savedSkillIds, $savedCategoryIds, $profile
        ) {
            $score = 0;
            $reasons = [];

            // Skill match — highest signal.
            $jobSkillIds = $job->skills->pluck('id');
            $matchedSkills = $skillIds->intersect($jobSkillIds);
            $skillCount = $matchedSkills->count();

            if ($skillCount > 0) {
                $score += $skillCount * 30;
                $matchedNames = $job->skills->whereIn('id', $matchedSkills)->pluck('name')->implode(', ');
                $reasons[] = "Your profile includes {$matchedNames}";
            }

            // Category match.
            if ($job->category_id && $categoryIds->contains($job->category_id)) {
                $score += 15;
                $reasons[] = "Matches your {$job->category->name} expertise";
            }

            // Experience level match.
            if ($job->experience_level && $job->experience_level === $profile->experience_level) {
                $score += 10;
                $reasons[] = "Matches your {$job->experience_level} experience level";
            }

            // Location match.
            if ($job->location && $profile->location &&
                strtolower($job->location) === strtolower($profile->location)) {
                $score += 8;
                $reasons[] = "Located in {$job->location}, same as your profile";
            }

            // Matches saved job interests.
            $savedSkillOverlap = $savedSkillIds->intersect($jobSkillIds)->count();
            if ($savedSkillOverlap > 0) {
                $score += $savedSkillOverlap * 10;
                $reasons[] = 'Matches your saved job interests';
            }

            $savedCategoryOverlap = in_array($job->category_id, $savedCategoryIds->toArray());
            if ($savedCategoryOverlap && ! collect($reasons)->contains(fn ($r) => str_contains($r, 'Matches your'))) {
                $score += 5;
                $reasons[] = 'Similar to your saved job categories';
            }

            // Keyword relevance — boost if job title/description matches profile skills by name.
            $jobText = strtolower($job->title . ' ' . $job->description);
            $keywordMatches = $skillNames->filter(fn ($name) => str_contains($jobText, $name))->count();
            if ($keywordMatches > 0 && $skillCount === 0) {
                $score += $keywordMatches * 5;
                $reasons[] = 'Keywords match your skill set';
            }

            return [
                'job' => $job,
                'score' => $score,
                'reason' => $reasons ? implode('; ', array_slice($reasons, 0, 2)) : 'Open opportunity on the marketplace',
            ];
        });

        return $scored
            ->filter(fn ($item) => $item['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /**
     * Get recommended freelancers for an employer based on their saved
     * freelancers, job categories, and skills需求.
     *
     * Returns a collection of ['freelancer' => FreelancerProfile, 'reason' => string, 'score' => float]
     */
    public function getRecommendedFreelancers(User $user, int $limit = 10): Collection
    {
        // Get the employer's jobs to determine needed skills/categories.
        $employerJobs = Job::with(['skills'])->where('employer_id', $user->id)->get();

        $neededSkillIds = $employerJobs->flatMap(fn ($j) => $j->skills->pluck('id'))->unique();
        $neededCategoryIds = $employerJobs->pluck('category_id')->filter()->unique();
        $neededExperience = $employerJobs->pluck('experience_level')->filter()->unique();

        // Get saved freelancer profiles to understand preferences.
        $savedFreelancerIds = SavedFreelancer::where('user_id', $user->id)->pluck('freelancer_profile_id');
        $savedProfiles = FreelancerProfile::with(['skills'])
            ->whereIn('id', $savedFreelancerIds)
            ->get();

        $preferredSkillIds = $savedProfiles->flatMap(fn ($p) => $p->skills->pluck('id'))->unique();
        $preferredLocations = $savedProfiles->pluck('location')->filter()->unique();
        $preferredExperience = $savedProfiles->pluck('experience_level')->filter()->unique();

        // Build a pool of available freelancers (exclude those already under contract).
        $activeFreelancerIds = $user->employerContracts()
            ->whereIn('status', ['active', 'paused'])
            ->pluck('freelancer_id');

        $candidates = FreelancerProfile::query()
            ->with(['user', 'skills'])
            ->approved()
            ->where('availability_status', '!=', 'not_available')
            ->whereNotIn('user_id', $activeFreelancerIds)
            ->whereHas('user', fn ($u) => $u->where('status', 'active')->where('role', 'freelancer'))
            ->get();

        $scored = $candidates->map(function (FreelancerProfile $freelancer) use (
            $neededSkillIds, $neededCategoryIds, $neededExperience,
            $preferredSkillIds, $preferredLocations, $preferredExperience
        ) {
            $score = 0;
            $reasons = [];

            $freelancerSkillIds = $freelancer->skills->pluck('id');
            $freelancerCategoryIds = $freelancer->skills->pluck('category_id')->unique();

            // Skill match from employer's jobs — highest signal.
            $jobSkillOverlap = $neededSkillIds->intersect($freelancerSkillIds)->count();
            if ($jobSkillOverlap > 0) {
                $score += $jobSkillOverlap * 25;
                $matchedNames = $freelancer->skills->whereIn('id', $neededSkillIds->intersect($freelancerSkillIds))
                    ->pluck('name')->implode(', ');
                $reasons[] = "Has skills matching your job needs: {$matchedNames}";
            }

            // Category match from employer's jobs.
            $categoryOverlap = $neededCategoryIds->intersect($freelancerCategoryIds)->count();
            if ($categoryOverlap > 0) {
                $score += $categoryOverlap * 10;
                $reasons[] = 'Works in your job categories';
            }

            // Experience level match.
            if ($freelancer->experience_level && $neededExperience->contains($freelancer->experience_level)) {
                $score += 10;
                $reasons[] = "Has {$freelancer->experience_level} experience you're looking for";
            }

            // Preferred skill match from saved freelancers.
            $prefSkillOverlap = $preferredSkillIds->intersect($freelancerSkillIds)->count();
            if ($prefSkillOverlap > 0) {
                $score += $prefSkillOverlap * 8;
                $reasons[] = 'Has skills similar to your saved freelancers';
            }

            // Location preference from saved freelancers.
            if ($freelancer->location && $preferredLocations->contains($freelancer->location)) {
                $score += 5;
                $reasons[] = "Located in {$freelancer->location}, matching your saved preferences";
            }

            // Experience preference from saved freelancers.
            if ($freelancer->experience_level && $preferredExperience->contains($freelancer->experience_level)) {
                $score += 3;
            }

            // High rating bonus.
            if ($freelancer->rating >= 4.0) {
                $score += 5;
                $reasons[] = "Highly rated ({$freelancer->rating}★)";
            }

            // Availability bonus.
            if ($freelancer->availability_status === 'available') {
                $score += 2;
            }

            return [
                'freelancer' => $freelancer,
                'score' => $score,
                'reason' => $reasons ? implode('; ', array_slice($reasons, 0, 2)) : 'Available on the marketplace',
            ];
        });

        return $scored
            ->filter(fn ($item) => $item['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }
}
