<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Employer;
use App\Models\User;
use Illuminate\Database\Seeder;
use ParticleAcademy\LaravelJobs\Enums\ApplicationStatus;
use ParticleAcademy\LaravelJobs\Enums\JobPostingStatus;
use ParticleAcademy\LaravelJobs\Models\JobApplication;
use ParticleAcademy\LaravelJobs\Models\JobPosting;

/**
 * The fixture the live-serve JobsClient suite drives against.
 *
 * Separate from `DatabaseSeeder` on purpose: this one is shaped by what the
 * route-agreement suite asserts, and a test fixture that doubles as the app's
 * demo data drifts the moment either changes.
 *
 * Deliberately tagged. Anything created here is identifiable and removable --
 * demo data that cannot be told apart from real data is how a seeded row ends up
 * in production looking like a customer.
 */
class LiveServeSeeder extends Seeder
{
    public const TAG = 'live-serve-fixture';

    public const PUBLISHED_SLUG = 'live-serve-security-officer';

    public const DRAFT_SLUG = 'live-serve-draft-never-public';

    public function run(): void
    {
        $owner = User::query()->firstOrCreate(
            ['email' => 'employer@'.self::TAG.'.test'],
            ['name' => 'Live Serve Employer', 'password' => bcrypt('password')],
        );

        $employer = Employer::query()->firstOrCreate(
            ['user_id' => $owner->getKey()],
            ['name' => 'Acme Hiring ('.self::TAG.')', 'status' => 'approved'],
        );

        $published = JobPosting::query()->updateOrCreate(
            ['slug' => self::PUBLISHED_SLUG],
            [
                'employer_id' => $employer->getKey(),
                'title' => 'Security Officer',
                'description' => 'Guard things.',
                'status' => JobPostingStatus::Published,
                'published_at' => now(),
            ],
        );

        // A candidate and ONE application, so that the route-agreement suite's
        // model-bound endpoints resolve.
        //
        // Without this, `withdraw(1)` and `setApplicationStatus(1, ...)` 404 from
        // route-model binding rather than from the URL being wrong -- the same
        // status for two completely different causes, and the one the suite is
        // watching for is the other one. A fixture that cannot tell them apart
        // makes those two assertions useless in both directions.
        $candidate = User::query()->firstOrCreate(
            ['email' => 'candidate@'.self::TAG.'.test'],
            ['name' => 'Live Serve Candidate', 'password' => bcrypt('password')],
        );

        JobApplication::query()->firstOrCreate(
            [
                'job_posting_id' => $published->getKey(),
                'user_id' => $candidate->getKey(),
            ],
            [
                'status' => ApplicationStatus::Submitted->value,
                'submitted_at' => now(),
            ],
        );

        // A posting the public board must NOT return. Asserting that the board
        // lists the published one proves the route; asserting it omits this one
        // proves the route means what it says.
        JobPosting::query()->updateOrCreate(
            ['slug' => self::DRAFT_SLUG],
            [
                'employer_id' => $employer->getKey(),
                'title' => 'Draft Never Public',
                'description' => 'Should never appear on the board.',
                'status' => JobPostingStatus::Draft,
            ],
        );
    }
}
