<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use ParticleAcademy\LaravelJobs\Models\JobApplication;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serving a candidate's resume. THIS IS THE SHAPE TO COPY.
 *
 * `laravel-jobs` stores a `resume_path` and leaves serving entirely to the host.
 * That split is correct — the package cannot know your disk, your auth, or who
 * you think may read a CV. But it means the obvious host implementation is
 * `Storage::url()` on a PUBLIC disk, and then every candidate's CV is readable by
 * anyone who guesses a path.
 *
 * So a reference consumer that wrote `resume_path` and stopped would be modelling
 * the dangerous half and calling it done. Raised by the package's first consumer,
 * who had already built the safe version and pointed out that the write is only
 * half of it.
 *
 * Four decisions here, each with a test:
 *
 *   1. The file lives on a PRIVATE disk. Nothing is reachable by URL.
 *   2. Every request is authorised — not just the first one, and not by
 *      possession of a path.
 *   3. Exactly two parties may read it: the candidate who uploaded it, and the
 *      employer it was sent to.
 *   4. **Denial is 404, not 403.** A 403 confirms an application exists at that
 *      id, which is itself a disclosure — it tells an attacker their guess was
 *      right. 404 says nothing.
 *
 * (4) is the one nobody writes unprompted, and the reason this file is worth
 * copying rather than re-deriving.
 */
class ResumeDownloadController extends Controller
{
    public function __invoke(Request $request, int $applicationId): StreamedResponse
    {
        $application = JobApplication::query()->find($applicationId);

        // One `abort(404)` for every refusal: missing application, missing file,
        // and not-allowed are indistinguishable from outside. That is deliberate.
        if ($application === null || ! $this->mayRead($request, $application)) {
            abort(404);
        }

        $path = $application->resume_path;

        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->download($path);
    }

    private function mayRead(Request $request, JobApplication $application): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        // The candidate who applied.
        if ((string) $application->user_id === (string) $user->getKey()) {
            return true;
        }

        // The employer the application was sent to — via the posting, because the
        // application does not carry an employer of its own.
        $employerId = $application->jobPosting?->employer_id;

        if ($employerId === null) {
            return false;
        }

        // NOT admins, deliberately. A CV is sent to one employer by one person;
        // "staff can read anything" is a decision a host should have to make
        // explicitly rather than inherit from a reference implementation.
        return \App\Models\Employer::query()
            ->whereKey($employerId)
            ->where('user_id', $user->getKey())
            ->exists();
    }
}
