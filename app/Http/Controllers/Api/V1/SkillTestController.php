<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Credential;
use App\Models\SkillTest;
use App\Models\SkillTestAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SkillTestController extends BaseApiController
{
    /**
     * List available skill tests.
     * Public endpoint — freelancers can see what tests are available.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SkillTest::with('skill:id,name,slug')
            ->where('is_active', true);

        if ($request->filled('skill_id')) {
            $query->where('skill_id', $request->input('skill_id'));
        }

        $tests = $query->orderByDesc('created_at')->paginate(15);

        return $this->sendResponse($tests->items(), 'Skill tests retrieved successfully.', 200, [
            'current_page' => $tests->currentPage(),
            'last_page' => $tests->lastPage(),
            'per_page' => $tests->perPage(),
            'total' => $tests->total(),
        ]);
    }

    /**
     * Get a single skill test with questions (for starting a test).
     */
    public function show(SkillTest $skillTest): JsonResponse
    {
        if (!$skillTest->is_active) {
            return $this->sendError('This skill test is not currently available.', [], 404);
        }

        $skillTest->load('skill:id,name,slug');

        // Return questions without correct answers
        $questions = $skillTest->questions->map(fn ($q) => [
            'id' => $q->id,
            'question' => $q->question,
            'options' => collect($q->options)->map(fn ($opt) => ['text' => $opt['text']])->toArray(),
            'sort_order' => $q->sort_order,
        ]);

        return $this->sendResponse([
            'id' => $skillTest->id,
            'title' => $skillTest->title,
            'description' => $skillTest->description,
            'skill' => $skillTest->skill,
            'passing_score' => $skillTest->passing_score,
            'time_limit_minutes' => $skillTest->time_limit_minutes,
            'question_count' => $skillTest->questions->count(),
            'questions' => $questions,
        ], 'Skill test retrieved successfully.');
    }

    /**
     * Check if a freelancer is exempt from a test (has Dream More certificate).
     */
    public function checkExemption(Request $request, SkillTest $skillTest): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'freelancer') {
            return $this->sendForbidden('Only freelancers can take skill tests.');
        }

        // Check if the user has already passed this test
        $hasPassed = $skillTest->userHasPassed($user->id);
        if ($hasPassed) {
            return $this->sendResponse([
                'exempt' => false,
                'already_passed' => true,
                'message' => 'You have already passed this skill test.',
            ], 'Test status checked.');
        }

        // Check if user has a Dream More LMS certificate that covers this skill
        $hasLmsCert = Credential::where('user_id', $user->id)
            ->where('verification_source', 'dream_more_lms')
            ->where('status', 'approved')
            ->where('auto_verified', true)
            ->exists();

        return $this->sendResponse([
            'exempt' => $hasLmsCert,
            'already_passed' => false,
            'reason' => $hasLmsCert
                ? 'You have a Dream More LMS certificate. This test is not required.'
                : null,
            'message' => $hasLmsCert
                ? 'Test exemption: Dream More LMS certification verified.'
                : 'No exemption. Please take the skill assessment.',
        ], 'Test exemption status checked.');
    }

    /**
     * Submit a skill test attempt.
     */
    public function submit(Request $request, SkillTest $skillTest): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'freelancer') {
            return $this->sendForbidden('Only freelancers can take skill tests.');
        }

        if (!$skillTest->is_active) {
            return $this->sendError('This skill test is not currently available.', [], 404);
        }

        // Check exemption — LMS certified = no test needed
        $hasLmsCert = Credential::where('user_id', $user->id)
            ->where('verification_source', 'dream_more_lms')
            ->where('status', 'approved')
            ->where('auto_verified', true)
            ->exists();

        if ($hasLmsCert) {
            return $this->sendError('You are exempt from this test due to Dream More LMS certification.', [], 422);
        }

        // Check if already passed
        if ($skillTest->userHasPassed($user->id)) {
            return $this->sendError('You have already passed this skill test.', [], 422);
        }

        // Validate answers
        $validated = $request->validate([
            'answers' => 'required|array',
            'answers.*' => 'required|integer|min:0',
            'credential_id' => 'nullable|integer|exists:credentials,id',
        ]);

        // Verify answers are for questions in this test
        $questionIds = $skillTest->questions()->pluck('id')->toArray();
        foreach (array_keys($validated['answers']) as $questionId) {
            if (!in_array((int) $questionId, $questionIds, true)) {
                return $this->sendError("Invalid question ID: {$questionId}", [], 422);
            }
        }

        // Score the test
        $questions = $skillTest->questions()->get()->keyBy('id');
        $correctCount = 0;
        $totalQuestions = $questions->count();

        foreach ($validated['answers'] as $questionId => $selectedIndex) {
            $question = $questions->get($questionId);
            if ($question && $question->isCorrect((int) $selectedIndex)) {
                $correctCount++;
            }
        }

        $score = $totalQuestions > 0 ? round(($correctCount / $totalQuestions) * 100) : 0;
        $passed = $score >= $skillTest->passing_score;

        // Validate credential_id if provided
        $credentialId = $validated['credential_id'] ?? null;
        if ($credentialId) {
            $credential = Credential::where('id', $credentialId)
                ->where('user_id', $user->id)
                ->first();
            if (!$credential) {
                return $this->sendError('Credential not found or not owned by you.', [], 404);
            }
        }

        // Create attempt
        $attempt = DB::transaction(function () use ($user, $skillTest, $validated, $score, $passed, $credentialId) {
            $attempt = SkillTestAttempt::create([
                'user_id' => $user->id,
                'skill_test_id' => $skillTest->id,
                'credential_id' => $credentialId,
                'answers' => $validated['answers'],
                'score' => (int) $score,
                'passed' => $passed,
                'submitted_at' => now(),
            ]);

            // If passed and credential linked, update credential status
            if ($passed && $credentialId) {
                $credential = Credential::find($credentialId);
                if ($credential && $credential->user_id === $user->id && $credential->test_status === 'pending') {
                    $credential->update([
                        'test_status' => 'passed',
                        'test_required' => true,
                        'status' => 'approved',
                        'reviewed_at' => now(),
                    ]);

                    \App\Services\NotificationService::credentialTestPassed(
                        $user->id,
                        $credential->title
                    );
                }
            }

            return $attempt;
        });

        return $this->sendResponse([
            'id' => $attempt->id,
            'score' => $attempt->score,
            'passed' => $attempt->passed,
            'passing_score' => $skillTest->passing_score,
            'correct_count' => $correctCount,
            'total_questions' => $totalQuestions,
            'submitted_at' => $attempt->submitted_at->toISOString(),
        ], $passed
            ? 'Congratulations! You passed the skill test.'
            : 'Test submitted. You did not meet the passing score. Please try again.',
            201
        );
    }

    /**
     * Get the freelancer's test history.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'freelancer') {
            return $this->sendForbidden('Only freelancers can view test history.');
        }

        $attempts = SkillTestAttempt::where('user_id', $user->id)
            ->with('skillTest:id,title,skill_id,passing_score')
            ->orderByDesc('submitted_at')
            ->paginate(15);

        return $this->sendResponse($attempts->items(), 'Test history retrieved successfully.', 200, [
            'current_page' => $attempts->currentPage(),
            'last_page' => $attempts->lastPage(),
            'per_page' => $attempts->perPage(),
            'total' => $attempts->total(),
        ]);
    }
}
