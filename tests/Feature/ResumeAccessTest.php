<?php

declare(strict_types=1);

use App\Models\Employer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ParticleAcademy\LaravelJobs\Enums\JobPostingStatus;
use ParticleAcademy\LaravelJobs\Models\JobApplication;
use ParticleAcademy\LaravelJobs\Models\JobPosting;

/**
 * `resume_path` — the passthrough AND the authorised read.
 *
 * The package stores a path and leaves serving to the host. That split is right,
 * and it is also a trap: the obvious host implementation is `Storage::url()` on a
 * PUBLIC disk, and then every candidate's CV is readable by anyone who guesses a
 * path.
 *
 * So asserting only that the path persists would model the dangerous half and
 * call it done — and because this repo is the thing people copy, that would make
 * the unsafe shape look sanctioned. Raised by the package's first consumer, who
 * had already built the safe version.
 *
 * Note what is NOT a package defect here: `resume_path` having no writer was the
 * package doing its half and nobody having done the other. `JobApplication` has
 * `$guarded = []` and `submit()` spreads attributes into `create()`, so a path
 * passed in persists as-is. That is a different thing from the events, where the
 * wiring genuinely waits on a host — same silence, different owner.
 */
beforeEach(function () {
    Storage::fake('local');

    config()->set('laravel-jobs.employer_model', Employer::class);

    $this->candidate = User::factory()->create();
    $this->employerOwner = User::factory()->create();
    $this->stranger = User::factory()->create();

    $this->employer = Employer::query()->create([
        'user_id' => $this->employerOwner->getKey(),
        'name' => 'Acme Hiring',
        'status' => 'approved',
    ]);

    $this->posting = JobPosting::query()->create([
        'employer_id' => $this->employer->getKey(),
        'title' => 'Security Officer',
        'slug' => 'security-officer',
        'description' => 'Guard things.',
        'status' => JobPostingStatus::Published,
        'published_at' => now(),
    ]);
});

function applicationWithResume(JobPosting $posting, User $candidate): JobApplication
{
    // The HOST stores the file and hands the package a path. This is the half
    // the package deliberately does not own.
    $path = UploadedFile::fake()
        ->create('cv.pdf', 12, 'application/pdf')
        ->store('resumes', 'local');

    return JobApplication::query()->create([
        'job_posting_id' => $posting->getKey(),
        'user_id' => $candidate->getKey(),
        'status' => 'submitted',
        'resume_path' => $path,
    ]);
}

test('the path persists which is the passthrough', function () {
    $application = applicationWithResume($this->posting, $this->candidate);

    expect($application->resume_path)->not->toBeNull();
    expect(Storage::disk('local')->exists($application->resume_path))->toBeTrue();
    expect($application->fresh()->resume_path)->toBe(
        $application->resume_path,
        'the path must survive a round trip, which is what the passthrough means',
    );
});

test('the file is NOT reachable at a public url', function () {
    // The load-bearing one. If this ever passes by accident -- a host moving
    // uploads to the public disk "so the link works" -- every CV in the system
    // becomes readable by path guessing, with nothing failing to say so.
    $application = applicationWithResume($this->posting, $this->candidate);

    expect(Storage::disk('public')->exists($application->resume_path))->toBeFalse(
        'a resume must never land on the public disk',
    );

    // And the private disk's own HTTP route does not serve it.
    //
    // Note what this asserts and what it does NOT. It asserts "not served",
    // not a status code -- because the status DIFFERS BY ENVIRONMENT, which is
    // a trap worth naming. Measured in the framework
    // (`Illuminate\Filesystem\ServeFile::__invoke`):
    //
    //     abort_unless($this->hasValidSignature($request), $isProduction ? 404 : 403);
    //
    // so an unsigned request is **403 locally and 404 in production**. A test
    // pinned to 403 passes on every developer machine and asserts the wrong
    // thing about the only environment that matters.
    $response = $this->get('/storage/'.$application->resume_path);

    expect($response->getStatusCode())->not->toBe(
        200,
        'the private disk must not serve a resume to an unsigned request',
    );
});

test('the private disk IS exposed over http and only a signature stops it', function () {
    // Surprising enough to pin, and nothing in `laravel-jobs` tells you:
    // Laravel's default `local` disk ships `'serve' => true`, which registers
    // `GET storage/{path}` (and a `PUT` upload twin) over
    // `storage_path('app/private')`. So "private disk" means
    // signature-required, NOT unreachable.
    //
    // Measured, not assumed -- `ServeFile::hasValidSignature()` allows a
    // request when EITHER the disk's visibility is `public` OR the URL carries
    // a valid relative signature.
    expect(config('filesystems.disks.local.serve'))->toBeTrue();
    expect(app('router')->getRoutes()->getByName('storage.local'))->not->toBeNull(
        'the private disk has an HTTP route; a host that assumes otherwise is wrong',
    );

    // Which is why the authorisation lives in a CONTROLLER rather than in the
    // path. A signed URL would be a bearer token: valid for whoever holds it,
    // with no check of who that is. `Storage::temporaryUrl()` is the
    // convenient wrong answer here, and it is one keystroke away.
    expect(config('filesystems.disks.local.visibility', 'private'))->toBe(
        'private',
        'if this disk were public-visibility, the signature check is skipped entirely',
    );
});

test('the candidate who uploaded it may read it', function () {
    $application = applicationWithResume($this->posting, $this->candidate);

    $this->actingAs($this->candidate)
        ->get(route('applications.resume', $application->getKey()))
        ->assertOk();
});

test('the employer it was sent to may read it', function () {
    $application = applicationWithResume($this->posting, $this->candidate);

    $this->actingAs($this->employerOwner)
        ->get(route('applications.resume', $application->getKey()))
        ->assertOk();
});

test('anybody else gets 404 rather than 403', function () {
    // 404, NOT 403, and the difference is a disclosure: a 403 confirms an
    // application exists at that id, which tells an attacker their guess was
    // right. This is the decision nobody writes unprompted.
    $application = applicationWithResume($this->posting, $this->candidate);

    $this->actingAs($this->stranger)
        ->get(route('applications.resume', $application->getKey()))
        ->assertNotFound();
});

test('an application that does not exist is indistinguishable from one you may not read', function () {
    // The same 404 for "no such application" and "not yours". If these
    // differed, the pair would leak which ids are real.
    $this->actingAs($this->stranger)
        ->get(route('applications.resume', 999999))
        ->assertNotFound();
});

test('a guest cannot read a resume', function () {
    $application = applicationWithResume($this->posting, $this->candidate);

    $this->get(route('applications.resume', $application->getKey()))
        ->assertRedirect(route('login'));
});

test('an application with no resume is a 404 not an error', function () {
    $application = JobApplication::query()->create([
        'job_posting_id' => $this->posting->getKey(),
        'user_id' => $this->candidate->getKey(),
        'status' => 'submitted',
    ]);

    $this->actingAs($this->candidate)
        ->get(route('applications.resume', $application->getKey()))
        ->assertNotFound();
});
