<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParticleAcademy\LaravelJobs\Enums\JobPostingStatus;
use ParticleAcademy\LaravelJobs\Models\JobApplication;
use ParticleAcademy\LaravelJobs\Models\JobPosting;
use Tests\TestCase;

/**
 * Can an unauthenticated caller read someone else's application by claiming to
 * be them?
 *
 * `CandidateResolver::resolve()` prefers `$request->user()`, and FALLS BACK to
 * `$request->input('user_id')` or the `X-Candidate-Id` header when
 * `laravel-jobs.allow_input_user_id` is true -- which it is by default, and which
 * is not in the published config file, so a host cannot switch off an option they
 * have never seen.
 *
 * The candidate routes carry `['api']` middleware and nothing else. So the
 * ownership checks downstream -- `forCandidate($resolved)` and withdraw's
 * `$application->user_id !== $candidateId` -- compare the record against an
 * identity THE CALLER SUPPLIED. That is not an authorization check; it is a check
 * that the attacker filled the form in consistently.
 *
 * These tests state the attack, not the fix. Run against laravel-jobs 0.3.0 they
 * describe what it does.
 */
final class CandidateIdentitySpoofTest extends TestCase
{
    use RefreshDatabase;

    private User $victim;
    private JobApplication $application;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('laravel-jobs.employer_model', Employer::class);

        $this->victim = User::factory()->create();
        $owner = User::factory()->create();

        $employer = Employer::query()->create([
            'user_id' => $owner->getKey(),
            'name' => 'Acme Hiring',
            'status' => 'approved',
        ]);

        $posting = JobPosting::query()->create([
            'employer_id' => $employer->getKey(),
            'title' => 'Security Officer',
            'slug' => 'security-officer',
            'description' => 'Guard things.',
            'status' => JobPostingStatus::Published,
            'published_at' => now(),
        ]);

        $this->application = JobApplication::query()->create([
            'job_posting_id' => $posting->getKey(),
            'user_id' => $this->victim->getKey(),
            'status' => 'submitted',
            'resume_path' => 'resumes/victim-cv.pdf',
            'cover_letter' => 'Please hire me.',
            'contact_email' => 'victim@example.test',
            'contact_phone' => '555-0100',
        ]);
    }

    public function test_an_anonymous_caller_cannot_read_an_application_by_passing_user_id(): void
    {
        $response = $this->getJson('/api/jobs/my-applications?user_id='.$this->victim->getKey());

        self::assertNotSame(
            200,
            $response->getStatusCode(),
            'an unauthenticated caller supplying a user_id must not be treated as that user',
        );
    }

    public function test_an_anonymous_caller_cannot_read_an_application_via_the_candidate_header(): void
    {
        $response = $this->getJson('/api/jobs/my-applications', [
            'X-Candidate-Id' => (string) $this->victim->getKey(),
        ]);

        self::assertNotSame(200, $response->getStatusCode());
    }

    public function test_the_contact_details_and_resume_path_do_not_leak(): void
    {
        // The payload is the point. An application carries a CV path, a cover
        // letter, an email and a phone number -- and `my-applications` returns all
        // of them.
        $body = $this->getJson('/api/jobs/my-applications?user_id='.$this->victim->getKey())->getContent();

        foreach (['resumes/victim-cv.pdf', 'victim@example.test', '555-0100', 'Please hire me.'] as $secret) {
            self::assertStringNotContainsString(
                $secret,
                (string) $body,
                'an unauthenticated response must not contain a candidate\'s '.$secret,
            );
        }
    }

    public function test_an_anonymous_caller_cannot_withdraw_someone_elses_application(): void
    {
        // Worse than a read: a write. Withdrawing an application is visible to the
        // employer and to the candidate, and it is not obviously an attack when
        // they notice it.
        $response = $this->postJson(
            '/api/jobs/applications/'.$this->application->getKey().'/withdraw',
            ['user_id' => $this->victim->getKey()],
        );

        self::assertNotSame(200, $response->getStatusCode());
        self::assertSame(
            'submitted',
            $this->application->fresh()->status->value ?? $this->application->fresh()->status,
            'the application must still be submitted',
        );
    }

    public function test_an_authenticated_candidate_still_reads_their_OWN_applications(): void
    {
        // The other half. A fix that locks out the legitimate candidate is the same
        // defect wearing a different coat.
        $this->actingAs($this->victim)
            ->getJson('/api/jobs/my-applications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->application->getKey());
    }
}
