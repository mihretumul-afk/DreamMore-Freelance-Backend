<?php

namespace Tests\Feature\Api\V1;

use App\Models\Credential;
use App\Models\Skill;
use App\Models\SkillTest;
use App\Models\SkillTestQuestion;
use App\Models\SkillTestAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LmsCertificationTest extends TestCase
{
    use RefreshDatabase;

    private User $freelancer;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freelancer = User::factory()->create(['role' => 'freelancer']);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    // ==========================================
    // LMS Certificate Verification Tests
    // ==========================================

    public function test_valid_lms_certificate_creates_approved_credential(): void
    {
        $payload = [
            'user_email' => $this->freelancer->email,
            'certificate_id' => 'DM-WEB-2026-00124',
            'course_id' => 'CRS-WEB-101',
            'course_name' => 'Web Development Fundamentals',
            'skill_name' => 'Web Development',
            'skill_id' => null,
            'completion_date' => '2026-08-15',
            'issue_date' => '2026-08-16',
        ];

        $response = $this->postJson('/api/v1/lms/certificate-completed', $payload, [
            'X-LMS-Signature' => $this->generateLmsSignature(json_encode($payload)),
        ]);

        $response->assertOk();
        $response->assertJsonFragment(['status' => 'approved']);

        $credential = Credential::where('lms_certificate_id', 'DM-WEB-2026-00124')->first();
        $this->assertNotNull($credential);
        $this->assertEquals('approved', $credential->status);
        $this->assertTrue($credential->auto_verified);
        $this->assertEquals('dream_more_lms', $credential->verification_source);
        $this->assertEquals('Dream More', $credential->issuing_organization);
        $this->assertEquals('DM-WEB-2026-00124', $credential->lms_certificate_id);
        $this->assertEquals('CRS-WEB-101', $credential->lms_course_id);
        $this->assertFalse($credential->test_required);
        $this->assertEquals('not_required', $credential->test_status);
    }

    public function test_invalid_lms_signature_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/lms/certificate-completed', [
            'user_email' => $this->freelancer->email,
            'certificate_id' => 'DM-WEB-2026-00124',
            'course_id' => 'CRS-WEB-101',
            'course_name' => 'Web Development Fundamentals',
            'skill_name' => 'Web Development',
            'completion_date' => '2026-08-15',
        ], [
            'X-LMS-Signature' => 'invalid-signature',
        ]);

        $response->assertStatus(401);
    }

    public function test_missing_lms_signature_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/lms/certificate-completed', [
            'user_email' => $this->freelancer->email,
            'certificate_id' => 'DM-WEB-2026-00124',
            'course_id' => 'CRS-WEB-101',
            'course_name' => 'Web Development Fundamentals',
            'skill_name' => 'Web Development',
            'completion_date' => '2026-08-15',
        ]);

        $response->assertStatus(401);
    }

    public function test_non_existent_user_email_returns_422(): void
    {
        $payload = [
            'user_email' => 'nonexistent@example.com',
            'certificate_id' => 'DM-WEB-2026-00124',
            'course_id' => 'CRS-WEB-101',
            'course_name' => 'Web Development Fundamentals',
            'skill_name' => 'Web Development',
            'completion_date' => '2026-08-15',
        ];

        $response = $this->postJson('/api/v1/lms/certificate-completed', $payload, [
            'X-LMS-Signature' => $this->generateLmsSignature(json_encode($payload)),
        ]);

        $response->assertStatus(422);
    }

    public function test_duplicate_lms_certificate_is_prevented(): void
    {
        Credential::create([
            'user_id' => $this->freelancer->id,
            'title' => 'Web Development',
            'type' => 'dream_more_certificate',
            'certificate_identifier' => 'DM-WEB-2026-00124',
            'file_path' => 'lms-verified/test',
            'status' => 'approved',
            'verification_source' => 'dream_more_lms',
            'lms_certificate_id' => 'DM-WEB-2026-00124',
            'auto_verified' => true,
        ]);

        $payload = [
            'user_email' => $this->freelancer->email,
            'certificate_id' => 'DM-WEB-2026-00124',
            'course_id' => 'CRS-WEB-101',
            'course_name' => 'Web Development Fundamentals',
            'skill_name' => 'Web Development',
            'completion_date' => '2026-08-15',
        ];

        $response = $this->postJson('/api/v1/lms/certificate-completed', $payload, [
            'X-LMS-Signature' => $this->generateLmsSignature(json_encode($payload)),
        ]);

        $response->assertOk();
        $this->assertEquals(1, Credential::where('lms_certificate_id', 'DM-WEB-2026-00124')->count());
    }

    public function test_lms_certificate_creates_notification(): void
    {
        $payload = [
            'user_email' => $this->freelancer->email,
            'certificate_id' => 'DM-WEB-2026-00124',
            'course_id' => 'CRS-WEB-101',
            'course_name' => 'Web Development Fundamentals',
            'skill_name' => 'Web Development',
            'completion_date' => '2026-08-15',
        ];

        $this->postJson('/api/v1/lms/certificate-completed', $payload, [
            'X-LMS-Signature' => $this->generateLmsSignature(json_encode($payload)),
        ]);

        $notification = \App\Models\Notification::where('user_id', $this->freelancer->id)
            ->where('type', 'lms_certificate_verified')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Web Development Fundamentals', $notification->message);
    }

    // ==========================================
    // LMS Certificate Revocation Tests
    // ==========================================

    public function test_lms_certificate_can_be_revoked(): void
    {
        $credential = Credential::create([
            'user_id' => $this->freelancer->id,
            'title' => 'Web Development',
            'type' => 'dream_more_certificate',
            'file_path' => 'lms-verified/test',
            'status' => 'approved',
            'verification_source' => 'dream_more_lms',
            'lms_certificate_id' => 'DM-WEB-2026-00124',
            'auto_verified' => true,
        ]);

        $payload = [
            'certificate_id' => 'DM-WEB-2026-00124',
            'reason' => 'Course completion invalidated',
        ];

        $response = $this->postJson('/api/v1/lms/certificate-revoked', $payload, [
            'X-LMS-Signature' => $this->generateLmsSignature(json_encode($payload)),
        ]);

        $response->assertOk();

        $credential->refresh();
        $this->assertEquals('rejected', $credential->status);
        $this->assertEquals('Course completion invalidated', $credential->rejection_reason);
    }

    public function test_revoking_nonexistent_certificate_returns_404(): void
    {
        $payload = [
            'certificate_id' => 'NONEXISTENT',
        ];

        $response = $this->postJson('/api/v1/lms/certificate-revoked', $payload, [
            'X-LMS-Signature' => $this->generateLmsSignature(json_encode($payload)),
        ]);

        $response->assertStatus(404);
    }

    // ==========================================
    // External Credential Tests
    // ==========================================

    public function test_external_credential_starts_pending(): void
    {
        $this->actingAs($this->freelancer);

        $response = $this->postJson('/api/v1/credentials', [
            'title' => 'Adobe Premiere Pro Certified',
            'type' => 'external_certificate',
            'issuing_organization' => 'Adobe',
            'certificate_identifier' => 'ADOBE-PP-2026-123',
            'description' => 'Professional video editing certification',
            'document' => \Illuminate\Http\UploadedFile::fake()->create('credential.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['status' => 'pending']);

        $credential = Credential::where('title', 'Adobe Premiere Pro Certified')->first();
        $this->assertEquals('external_manual', $credential->verification_source);
    }

    public function test_dream_more_certificate_submitted_by_freelancer_starts_pending(): void
    {
        $this->actingAs($this->freelancer);

        $response = $this->postJson('/api/v1/credentials', [
            'title' => 'Web Development Certificate',
            'type' => 'dream_more_certificate',
            'issuing_organization' => 'Dream More',
            'certificate_identifier' => 'DM-WEB-2026-99999',
            'description' => 'I completed the Dream More web development course',
            'document' => \Illuminate\Http\UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated();

        // Should be pending — NOT auto-approved even though type is dream_more_certificate
        $credential = Credential::where('certificate_identifier', 'DM-WEB-2026-99999')->first();
        $this->assertEquals('pending', $credential->status);
    }

    public function test_admin_cannot_manually_approve_lms_certificate(): void
    {
        $credential = Credential::create([
            'user_id' => $this->freelancer->id,
            'title' => 'Web Development',
            'type' => 'dream_more_certificate',
            'file_path' => 'lms-verified/test',
            'status' => 'pending',
            'verification_source' => 'dream_more_lms',
            'lms_certificate_id' => 'DM-WEB-2026-00124',
            'auto_verified' => true,
        ]);

        $this->actingAs($this->admin);

        $response = $this->putJson("/api/v1/admin/credentials/{$credential->id}/approve");
        $response->assertStatus(422);
    }

    // ==========================================
    // Skill Test Exemption Tests
    // ==========================================

    public function test_lms_certified_freelancer_is_exempt_from_skill_test(): void
    {
        Credential::create([
            'user_id' => $this->freelancer->id,
            'title' => 'Web Development',
            'type' => 'dream_more_certificate',
            'file_path' => 'lms-verified/test',
            'status' => 'approved',
            'verification_source' => 'dream_more_lms',
            'auto_verified' => true,
        ]);

        $skill = Skill::create(['name' => 'Web Development', 'slug' => 'web-dev', 'is_active' => true]);
        $test = SkillTest::create([
            'skill_id' => $skill->id,
            'title' => 'Web Development Test',
            'passing_score' => 70,
            'time_limit_minutes' => 30,
            'is_active' => true,
        ]);

        $this->actingAs($this->freelancer);

        $response = $this->getJson("/api/v1/skill-tests/{$test->id}/exemption");
        $response->assertOk();
        $response->assertJsonFragment(['exempt' => true]);
    }

    public function test_freelancer_without_lms_cert_is_not_exempt(): void
    {
        $skill = Skill::create(['name' => 'Web Development', 'slug' => 'web-dev', 'is_active' => true]);
        $test = SkillTest::create([
            'skill_id' => $skill->id,
            'title' => 'Web Development Test',
            'passing_score' => 70,
            'time_limit_minutes' => 30,
            'is_active' => true,
        ]);

        $this->actingAs($this->freelancer);

        $response = $this->getJson("/api/v1/skill-tests/{$test->id}/exemption");
        $response->assertOk();
        $response->assertJsonFragment(['exempt' => false]);
    }

    // ==========================================
    // Skill Test Submission Tests
    // ==========================================

    public function test_freelancer_can_take_and_pass_skill_test(): void
    {
        $skill = Skill::create(['name' => 'JavaScript', 'slug' => 'javascript', 'is_active' => true]);
        $test = SkillTest::create([
            'skill_id' => $skill->id,
            'title' => 'JavaScript Fundamentals',
            'passing_score' => 70,
            'time_limit_minutes' => 30,
            'is_active' => true,
        ]);

        $q1 = SkillTestQuestion::create([
            'skill_test_id' => $test->id,
            'question' => 'What does typeof null return?',
            'options' => [
                ['text' => 'null', 'is_correct' => false],
                ['text' => 'object', 'is_correct' => true],
                ['text' => 'undefined', 'is_correct' => false],
                ['text' => 'number', 'is_correct' => false],
            ],
            'sort_order' => 1,
        ]);

        $q2 = SkillTestQuestion::create([
            'skill_test_id' => $test->id,
            'question' => 'Which keyword declares a block-scoped variable?',
            'options' => [
                ['text' => 'var', 'is_correct' => false],
                ['text' => 'let', 'is_correct' => true],
                ['text' => 'function', 'is_correct' => false],
                ['text' => 'const', 'is_correct' => false],
            ],
            'sort_order' => 2,
        ]);

        $this->actingAs($this->freelancer);

        $response = $this->postJson("/api/v1/skill-tests/{$test->id}/submit", [
            'answers' => [
                (string) $q1->id => 1,
                (string) $q2->id => 1,
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['passed' => true]);
        $response->assertJsonFragment(['score' => 100]);

        $this->assertDatabaseHas('skill_test_attempts', [
            'user_id' => $this->freelancer->id,
            'skill_test_id' => $test->id,
            'passed' => true,
        ]);
    }

    public function test_freelancer_can_fail_skill_test(): void
    {
        $skill = Skill::create(['name' => 'JavaScript', 'slug' => 'javascript', 'is_active' => true]);
        $test = SkillTest::create([
            'skill_id' => $skill->id,
            'title' => 'JavaScript Fundamentals',
            'passing_score' => 70,
            'time_limit_minutes' => 30,
            'is_active' => true,
        ]);

        $q1 = SkillTestQuestion::create([
            'skill_test_id' => $test->id,
            'question' => 'What does typeof null return?',
            'options' => [
                ['text' => 'null', 'is_correct' => false],
                ['text' => 'object', 'is_correct' => true],
                ['text' => 'undefined', 'is_correct' => false],
                ['text' => 'number', 'is_correct' => false],
            ],
            'sort_order' => 1,
        ]);

        $this->actingAs($this->freelancer);

        $response = $this->postJson("/api/v1/skill-tests/{$test->id}/submit", [
            'answers' => [
                (string) $q1->id => 0,
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['passed' => false]);
    }

    public function test_exempt_freelancer_cannot_take_skill_test(): void
    {
        $skill = Skill::create(['name' => 'JavaScript', 'slug' => 'javascript', 'is_active' => true]);
        $test = SkillTest::create([
            'skill_id' => $skill->id,
            'title' => 'JavaScript Fundamentals',
            'passing_score' => 70,
            'time_limit_minutes' => 30,
            'is_active' => true,
        ]);

        $q1 = SkillTestQuestion::create([
            'skill_test_id' => $test->id,
            'question' => 'Test question',
            'options' => [['text' => 'A', 'is_correct' => true]],
            'sort_order' => 1,
        ]);

        Credential::create([
            'user_id' => $this->freelancer->id,
            'title' => 'JavaScript',
            'type' => 'dream_more_certificate',
            'file_path' => 'lms-verified/test',
            'status' => 'approved',
            'verification_source' => 'dream_more_lms',
            'auto_verified' => true,
        ]);

        $this->actingAs($this->freelancer);

        $response = $this->postJson("/api/v1/skill-tests/{$test->id}/submit", [
            'answers' => [(string) $q1->id => 0],
        ]);

        $response->assertStatus(422);
    }

    public function test_passing_test_with_linked_credential_updates_credential_status(): void
    {
        $skill = Skill::create(['name' => 'JavaScript', 'slug' => 'javascript', 'is_active' => true]);
        $test = SkillTest::create([
            'skill_id' => $skill->id,
            'title' => 'JavaScript Fundamentals',
            'passing_score' => 70,
            'time_limit_minutes' => 30,
            'is_active' => true,
        ]);

        $q1 = SkillTestQuestion::create([
            'skill_test_id' => $test->id,
            'question' => 'Test question',
            'options' => [
                ['text' => 'A', 'is_correct' => true],
                ['text' => 'B', 'is_correct' => false],
            ],
            'sort_order' => 1,
        ]);

        $credential = Credential::create([
            'user_id' => $this->freelancer->id,
            'title' => 'JavaScript Certified',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
            'verification_source' => 'external_manual',
            'test_required' => true,
            'test_status' => 'pending',
        ]);

        $this->actingAs($this->freelancer);

        $response = $this->postJson("/api/v1/skill-tests/{$test->id}/submit", [
            'answers' => [(string) $q1->id => 0],
            'credential_id' => $credential->id,
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['passed' => true]);

        $credential->refresh();
        $this->assertEquals('approved', $credential->status);
        $this->assertEquals('passed', $credential->test_status);
    }

    public function test_unauthenticated_user_cannot_take_skill_test(): void
    {
        $skill = Skill::create(['name' => 'JavaScript', 'slug' => 'javascript', 'is_active' => true]);
        $test = SkillTest::create([
            'skill_id' => $skill->id,
            'title' => 'JavaScript Test',
            'passing_score' => 70,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/v1/skill-tests/{$test->id}/submit", [
            'answers' => [],
        ]);

        $response->assertStatus(401);
    }

    public function test_skill_test_history_can_be_viewed(): void
    {
        $skill = Skill::create(['name' => 'JavaScript', 'slug' => 'javascript', 'is_active' => true]);
        $test = SkillTest::create([
            'skill_id' => $skill->id,
            'title' => 'JavaScript Test',
            'passing_score' => 70,
            'is_active' => true,
        ]);

        SkillTestAttempt::create([
            'user_id' => $this->freelancer->id,
            'skill_test_id' => $test->id,
            'answers' => [1 => 0],
            'score' => 100,
            'passed' => true,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->freelancer);

        $response = $this->getJson('/api/v1/my-test-history');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    // ==========================================
    // Public Profile Tests
    // ==========================================

    public function test_public_profile_shows_dream_more_certified_trust_level(): void
    {
        $this->freelancer->freelancerProfile()->create([]);

        Credential::create([
            'user_id' => $this->freelancer->id,
            'title' => 'Web Development',
            'type' => 'dream_more_certificate',
            'file_path' => 'lms-verified/test',
            'status' => 'approved',
            'verification_source' => 'dream_more_lms',
            'lms_course_name' => 'Web Development Fundamentals',
            'auto_verified' => true,
        ]);

        $response = $this->getJson("/api/v1/freelancers/{$this->freelancer->id}");
        $response->assertOk();

        $verifiedCreds = $response->json('data.verified_credentials');
        $this->assertCount(1, $verifiedCreds);
        $this->assertEquals('dream_more_lms', $verifiedCreds[0]['verification_source']);
        $this->assertTrue($verifiedCreds[0]['auto_verified']);
        $this->assertEquals('Web Development Fundamentals', $verifiedCreds[0]['lms_course_name']);
    }

    public function test_pending_credentials_not_shown_in_public_profile(): void
    {
        $this->freelancer->freelancerProfile()->create([]);

        Credential::create([
            'user_id' => $this->freelancer->id,
            'title' => 'Pending Credential',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/v1/freelancers/{$this->freelancer->id}");
        $response->assertOk();

        $verifiedCreds = $response->json('data.verified_credentials');
        $this->assertCount(0, $verifiedCreds);
    }

    public function test_credential_file_path_not_exposed_publicly(): void
    {
        $this->freelancer->freelancerProfile()->create([]);

        Credential::create([
            'user_id' => $this->freelancer->id,
            'title' => 'Verified Credential',
            'type' => 'external_certificate',
            'file_path' => 'credentials/test.pdf',
            'file_original_name' => 'secret-document.pdf',
            'status' => 'approved',
            'verification_source' => 'external_manual',
        ]);

        $response = $this->getJson("/api/v1/freelancers/{$this->freelancer->id}");
        $response->assertOk();

        $verifiedCreds = $response->json('data.verified_credentials');
        $this->assertCount(1, $verifiedCreds);
        $this->assertArrayNotHasKey('file_path', $verifiedCreds[0]);
        $this->assertArrayNotHasKey('file_original_name', $verifiedCreds[0]);
    }

    // ==========================================
    // Helpers
    // ==========================================

    private function generateLmsSignature(string $payload): string
    {
        $secret = config('app.lms_secret', env('LMS_SECRET_KEY', 'dream-more-lms-secret-key-change-in-production'));
        return hash_hmac('sha256', $payload, $secret);
    }
}
