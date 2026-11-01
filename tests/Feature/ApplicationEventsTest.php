<?php

declare(strict_types=1);

use App\Listeners\NotifyEmployerOfApplication;
use App\Models\Employer;
use App\Models\User;
use App\Notifications\ApplicationReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use ParticleAcademy\LaravelJobs\Enums\ApplicationStatus;
use ParticleAcademy\LaravelJobs\Enums\JobPostingStatus;
use ParticleAcademy\LaravelJobs\Events\ApplicationSubmitted;
use ParticleAcademy\LaravelJobs\Models\JobPosting;
use ParticleAcademy\LaravelJobs\Services\ApplicationService;

/**
 * The quietest wire in the package, and the one a host is most likely to leave
 * unconnected.
 *
 * `laravel-jobs` dispatches four events and listens to none of them, which is
 * right — it cannot know whether you mail, queue, call a webhook or do nothing.
 * The consequence is that a host which never writes a listener has a portal where
 * applications arrive and nobody is told, and **nothing anywhere fails**. No
 * error, no log, no red test. It looks exactly like having no applicants, which
 * is also what the employer sees.
 *
 * So these tests are about the WIRE, not the notification. Two things are easy to
 * assert and prove nothing on their own:
 *
 *   - `Event::fake()` then `assertDispatched` — proves the service dispatches,
 *     and passes happily when no listener exists.
 *   - dispatching the event by hand and asserting the listener ran — proves the
 *     listener works, and passes happily when the service never fires it.
 *
 * Each is green while the system is broken, because each stubs out the half the
 * other was meant to cover. The assertion that matters goes end to end: call the
 * real `ApplicationService::submit()`, with events live, and check the
 * notification came out the far end.
 */
beforeEach(function () {
    config()->set('laravel-jobs.employer_model', Employer::class);

    $this->candidate = User::factory()->create();
    $this->employerOwner = User::factory()->create();

    $employer = Employer::query()->create([
        'user_id' => $this->employerOwner->getKey(),
        'name' => 'Acme Hiring',
        'status' => 'approved',
    ]);

    $this->posting = JobPosting::query()->create([
        'employer_id' => $employer->getKey(),
        'title' => 'Security Officer',
        'slug' => 'security-officer',
        'description' => 'Guard things.',
        'status' => JobPostingStatus::Published,
        'published_at' => now(),
    ]);
});

function submitApplication(JobPosting $posting, User $candidate): void
{
    app(ApplicationService::class)->submit(
        $posting,
        $candidate->getKey(),
        ['cover_letter' => 'Please hire me.'],
    );
}

test('submitting through the real service notifies the employer', function () {
    // THE ONE THAT MATTERS. Events are NOT faked; the listener is the one the
    // provider registered; the path is the package's own service.
    Notification::fake();

    submitApplication($this->posting, $this->candidate);

    Notification::assertSentTo(
        $this->employerOwner,
        ApplicationReceived::class,
    );
});

test('nobody else is notified', function () {
    Notification::fake();

    submitApplication($this->posting, $this->candidate);

    Notification::assertNotSentTo($this->candidate, ApplicationReceived::class);
});

test('the notification does not carry the candidates contact details', function () {
    // A mail body is archived, forwarded and readable by whoever reaches the
    // inbox, with none of the authorization the download route applies. It is
    // the easiest place in the system to leak the four fields the resume route
    // is careful about.
    Notification::fake();

    submitApplication($this->posting, $this->candidate);

    Notification::assertSentTo($this->employerOwner, ApplicationReceived::class, function ($notification) {
        $mail = $notification->toMail($this->employerOwner);
        $rendered = json_encode($mail->toArray());

        foreach (['Please hire me.', (string) $this->candidate->email] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $rendered);
        }

        return true;
    });
});

test('the listener is actually registered for the event', function () {
    // Guards the wiring itself rather than its effect. If someone removes the
    // `Event::listen` line, the test above fails with a confusing "no
    // notification" -- this one fails saying what is wrong.
    expect(array_map(
        static fn ($l) => is_string($l) ? $l : $l::class,
        Event::getRawListeners()[ApplicationSubmitted::class] ?? [],
    ))->toContain(NotifyEmployerOfApplication::class);
});

test('a dead mail server does not fail the candidates submission', function () {
    // The reason the listener catches. Since laravel-jobs 0.5.0 the events are
    // `ShouldDispatchAfterCommit`, so a throw can no longer DESTROY the
    // application -- but dispatch is still synchronous within the request, so
    // an unhandled throw would reach the candidate as a 500 on a submission
    // that actually succeeded. They would be told it failed and would retry.
    Notification::shouldReceive('send')->andThrow(new RuntimeException('smtp is down'));

    submitApplication($this->posting, $this->candidate);

    $this->assertDatabaseHas('job_applications', [
        'job_posting_id' => $this->posting->getKey(),
        'user_id' => $this->candidate->getKey(),
    ]);
});

test('a posting whose employer is missing does not break the application', function () {
    // A data problem on the employer side must not surface to the candidate as
    // a failed submission.
    //
    // This state is reachable, and the reason is structural rather than
    // sloppy: `job_postings.employer_id` is an indexed `unsignedBigInteger`
    // with **no foreign key**, because the package cannot constrain a table it
    // does not own -- the host supplies the employer model. So every host has
    // a column that can point at nothing, and the listener's `null` guard is
    // load-bearing rather than defensive.
    //
    // (The sibling case -- an employer whose owning USER is missing -- is NOT
    // reachable here, because this app's own `employers.user_id` IS a foreign
    // key. `Schema::withoutForeignKeyConstraints` does not get you there
    // either: SQLite ignores the `foreign_keys` pragma inside a transaction,
    // and `RefreshDatabase` wraps every test in one. The guard for it stays in
    // the listener for hosts without the constraint.)
    $orphan = JobPosting::query()->create([
        'employer_id' => 999999,
        'title' => 'Night Guard',
        'slug' => 'night-guard',
        'description' => 'Guard things at night.',
        'status' => JobPostingStatus::Published,
        'published_at' => now(),
    ]);

    Notification::fake();

    app(ApplicationService::class)->submit($orphan, $this->candidate->getKey(), []);

    $this->assertDatabaseHas('job_applications', [
        'job_posting_id' => $orphan->getKey(),
        'user_id' => $this->candidate->getKey(),
        'status' => ApplicationStatus::Submitted->value,
    ]);

    Notification::assertNothingSent();
});
