<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Employer;
use App\Models\User;
use App\Notifications\ApplicationReceived;
use Illuminate\Support\Facades\Log;
use ParticleAcademy\LaravelJobs\Events\ApplicationSubmitted;
use Throwable;

/**
 * The host's half of `ApplicationSubmitted`.
 *
 * `laravel-jobs` dispatches four events — `ApplicationSubmitted`,
 * `ApplicationStatusChanged`, `JobPostingPublished`, `JobPostingClosed` — and
 * listens to none of them, correctly: it cannot know whether you mail, queue a
 * job, hit a webhook or do nothing.
 *
 * That makes them the quietest thing in the package. A host that never writes a
 * listener has a system where applications arrive and nobody is told, and
 * NOTHING ANYWHERE FAILS. No error, no log, no red test. It is indistinguishable
 * from a system with no applicants, which is also what it looks like to the
 * employer.
 *
 * So this exists less as a feature than as a demonstration that the wire has two
 * ends, and `ApplicationEventsTest` asserts the event actually arrives here from
 * the real `ApplicationService::submit()` path rather than from a hand-dispatch
 * in a test.
 */
class NotifyEmployerOfApplication
{
    public function handle(ApplicationSubmitted $event): void
    {
        $employerId = $event->application->jobPosting?->employer_id;

        if ($employerId === null) {
            return;
        }

        $employer = Employer::query()->find($employerId);

        if ($employer === null) {
            return;
        }

        $owner = User::query()->find($employer->user_id);

        if ($owner === null) {
            // No exception when there is nobody to notify. An employer row without
            // a user is a data problem, not a reason to involve the candidate.
            return;
        }

        /*
         * The notification is wrapped, and the reason is not belt-and-braces.
         *
         * laravel-jobs 0.5.0 made these events `ShouldDispatchAfterCommit`, so a
         * throw here can no longer destroy the application -- it is committed
         * before this runs. But the dispatch is still SYNCHRONOUS within the
         * request, so an exception still reaches the candidate as a 500 on a
         * submission that actually succeeded. They would be told it failed and
         * would try again.
         *
         * Note what does NOT fix this: adding `ShouldQueue` to the listener. The
         * default queue connection is `sync`, which runs the job inline and
         * propagates the exception exactly as this would -- so a host that reaches
         * for the queue and never changes the driver has moved the code and
         * nothing else. Catching is correct on every driver.
         *
         * Swallowed, not rethrown, and logged with the application id so the
         * failure is findable. The employer missing one email is recoverable; the
         * candidate being told their application failed when it did not is not.
         */
        try {
            $owner->notify(new ApplicationReceived($event->application));
        } catch (Throwable $e) {
            Log::warning('Could not notify the employer about an application.', [
                'application_id' => $event->application->getKey(),
                'employer_id' => $employerId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
