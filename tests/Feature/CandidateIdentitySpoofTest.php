<?php

declare(strict_types=1);

use App\Models\Employer;
use App\Models\User;
use ParticleAcademy\LaravelJobs\Enums\JobPostingStatus;
use ParticleAcademy\LaravelJobs\Models\JobApplication;
use ParticleAcademy\LaravelJobs\Models\JobPosting;

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
beforeEach(function () {
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
});

test('an anonymous caller cannot read an application by passing user_id', function () {
    $response = $this->getJson('/api/jobs/my-applications?user_id='.$this->victim->getKey());

    expect($response->getStatusCode())->not->toBe(
        200,
        'an unauthenticated caller supplying a user_id must not be treated as that user',
    );
});

test('an anonymous caller cannot read an application via the candidate header', function () {
    $response = $this->getJson('/api/jobs/my-applications', [
        'X-Candidate-Id' => (string) $this->victim->getKey(),
    ]);

    expect($response->getStatusCode())->not->toBe(200);
});

test('the contact details and resume path do not leak', function () {
    // The payload is the point. An application carries a CV path, a cover
    // letter, an email and a phone number -- and `my-applications` returns all
    // of them.
    $body = $this->getJson('/api/jobs/my-applications?user_id='.$this->victim->getKey())->getContent();

    foreach (['resumes/victim-cv.pdf', 'victim@example.test', '555-0100', 'Please hire me.'] as $secret) {
        $this->assertStringNotContainsString(
            $secret,
            (string) $body,
            'an unauthenticated response must not contain a candidate\'s '.$secret,
        );
    }
});

test('an anonymous caller cannot withdraw someone elses application', function () {
    // Worse than a read: a write. Withdrawing an application is visible to the
    // employer and to the candidate, and it is not obviously an attack when
    // they notice it.
    $response = $this->postJson(
        '/api/jobs/applications/'.$this->application->getKey().'/withdraw',
        ['user_id' => $this->victim->getKey()],
    );

    expect($response->getStatusCode())->not->toBe(200);
    expect($this->application->fresh()->status->value ?? $this->application->fresh()->status)->toBe(
        'submitted',
        'the application must still be submitted',
    );
});

test('an authenticated candidate still reads their OWN applications', function () {
    // The other half. A fix that locks out the legitimate candidate is the same
    // defect wearing a different coat.
    $this->actingAs($this->victim)
        ->getJson('/api/jobs/my-applications')
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->application->getKey());
});
