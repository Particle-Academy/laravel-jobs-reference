<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParticleAcademy\LaravelJobs\Support\EmployerGate;
use Tests\TestCase;

/**
 * THE LEAD TEST OF THIS REPO, and it is first on purpose.
 *
 * `laravel-jobs` has sharp edges, and all but one of them are LOUD: forget a host
 * binding and the portal is dead, and you know in seconds. This one was the
 * opposite, and it is the reason this repo exists in the shape it does.
 *
 * `EmployerGate::allowsPublishing()` treated a gate column missing from the
 * employer INSTANCE as *ungated* and returned true. `getAttributes()` is what was
 * LOADED, not what the table has — so an ordinary `select()` narrowing a query
 * for performance, anywhere in a host's code, silently switched moderation off.
 * An unapproved employer's posting went live and the system reported success.
 * Nothing logged. Nothing threw. No `PublishDecision` denial to inspect.
 *
 * Fixed in laravel-jobs 0.3.0 (issue #6), found by the package's first real
 * consumer while answering a question about something else — because a failure
 * this quiet is not found by looking for it.
 *
 * Their own protection was an accident: their publish gate re-checks approval
 * first, because money must never buy past moderation. That is a coincidence they
 * benefited from, not a safety property. **This test is the safety property**, and
 * it is here rather than only in the package because the symptom is a host-shaped
 * one: a query narrowed somewhere else entirely.
 */
final class EmployerGateTest extends TestCase
{
    use RefreshDatabase;

    private function employer(string $status): Employer
    {
        return Employer::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'name' => 'Acme Hiring',
            'status' => $status,
        ]);
    }

    /** Turn gating ON. This app ships it OFF; these tests assert the gate itself. */
    private function gated(): EmployerGate
    {
        config()->set('laravel-jobs.employer_model', Employer::class);
        config()->set('laravel-jobs.employer_gate.column', 'status');
        config()->set('laravel-jobs.employer_gate.approved', 'approved');

        return new EmployerGate();
    }

    public function test_a_narrowed_select_does_not_bypass_the_gate(): void
    {
        // THE ONE THAT MATTERS. A host narrowing a query for performance --
        // elsewhere, long after the gate was configured and tested -- must not
        // move from "moderation enforced" to "moderation off".
        $this->employer('pending');

        $narrowed = Employer::query()->select(['id', 'name'])->firstOrFail();

        self::assertArrayNotHasKey(
            'status',
            $narrowed->getAttributes(),
            'the fixture must not load the gate column, or this test asserts nothing',
        );

        self::assertFalse(
            $this->gated()->allowsPublishing($narrowed),
            'a select() that omits the gate column must not disable moderation',
        );
    }

    public function test_a_narrowed_APPROVED_employer_is_still_allowed(): void
    {
        // The other half, and the reason the fix is not simply "deny when absent".
        // A fail-open traded for a silent lockout is the same defect in a
        // different coat: every publish refused, for a reason nobody can see.
        $this->employer('approved');

        $narrowed = Employer::query()->select(['id', 'name'])->firstOrFail();

        self::assertTrue($this->gated()->allowsPublishing($narrowed));
    }

    public function test_a_fully_loaded_employer_is_gated_as_expected(): void
    {
        self::assertFalse($this->gated()->allowsPublishing($this->employer('pending')));
        self::assertTrue($this->gated()->allowsPublishing($this->employer('approved')));
    }

    public function test_this_APP_runs_ungated_which_is_the_path_nobody_had_exercised(): void
    {
        // This app's own configuration, untouched: `employer_gate.column => null`.
        //
        // Every host that does not moderate its employers must set this
        // explicitly, and the package's first consumer moderates theirs — so
        // outside the package's own tests this path had never run anywhere. It is
        // the DEFAULT that surprises, not the option.
        config()->set('laravel-jobs.employer_model', Employer::class);

        self::assertNull(config('laravel-jobs.employer_gate.column'));

        $gate = new EmployerGate();

        // A `pending` employer publishes freely here, and that is CORRECT: this
        // host does not moderate. The gate is off because it was turned off.
        self::assertTrue($gate->allowsPublishing($this->employer('pending')));
    }

    public function test_a_column_the_table_does_not_have_stays_forgiving(): void
    {
        // The branch the fix had to preserve. A host naming a column its employer
        // table lacks is misconfigured, and refusing every publish would be
        // baffling rather than informative -- so it allows, and warns.
        config()->set('laravel-jobs.employer_model', Employer::class);
        config()->set('laravel-jobs.employer_gate.column', 'no_such_column');

        self::assertTrue((new EmployerGate())->allowsPublishing($this->employer('pending')));
    }
}
